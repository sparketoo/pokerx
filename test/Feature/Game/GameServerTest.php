<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\ProviderInterface;
use App\Model\User;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserTokenService;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameServerMessageVo;
use App\Vo\Game\GameVo;
use Closure;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Di\Container;
use Hyperf\Redis\Redis;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;
use Throwable;

final class GameServerTest extends DatabaseTestCase
{
    public function test_ok_start_does_not_infer_blinds_from_empty_button_seat(): void
    {
        $provider = new class extends BaseProvider
        {
            public ?GameVo $started = null;

            public ?GameEventVo $posted = null;

            public function start(GameVo $game): void
            {
                $this->started = $game;
            }

            public function blindPosted(GameEventVo $event): void
            {
                $this->posted = $event;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new class extends Sender
        {
            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): bool
            {
                return true;
            }
        };
        $user = $this->user();
        $connection = new GameServerConnectionVo(11, $user, 'client-a');
        $server = $this->gameServerWithConnections($provider, [11 => $connection], $sender);
        $server->handleStart(new GameServerMessageVo($connection, 'start-45', 'START', [
            'game_key' => 'empty-sb-'.bin2hex(random_bytes(8)), 'network' => 'OK', 'ante' => 0,
            'small_blind' => 1, 'big_blind' => 2, 'button_seat_number' => 7,
            'players' => [
                ['uid' => 'seat3', 'seat' => 3, 'stack' => 100, 'hero' => false],
                ['uid' => 'seat5', 'seat' => 5, 'stack' => 100, 'hero' => false],
                ['uid' => 'hero', 'seat' => 6, 'stack' => 100, 'hero' => true],
                ['uid' => 'seat8', 'seat' => 8, 'stack' => 100, 'hero' => false],
            ],
        ], time() * 1000));

        self::assertNotNull($provider->started);
        self::assertSame(7, $provider->started->buttonSeatNumber);
        self::assertSame(0, $provider->started->pot());

        $server->handleBlindPosted(new GameServerMessageVo($connection, 'blind-45', 'BLIND_POSTED', [
            'game_uuid' => $provider->started->uuid, 'uid' => 'seat3', 'type' => 'BB', 'amount' => 2,
        ], time() * 1000));
        self::assertSame('BB', $provider->posted?->payload['type']);
        self::assertSame(2, $provider->posted?->game->pot());
    }

    public function test_each_websocket_gets_its_own_client_identity_and_reconnect_restores_it(): void
    {
        $provider = new class extends BaseProvider
        {
            /** @var list<GameServerConnectionVo> */
            public array $connected = [];

            /** @var list<GameServerConnectionVo> */
            public array $disconnected = [];

            public function connect(GameServerConnectionVo $connection): void
            {
                $this->connected[] = $connection;
            }

            public function disconnect(GameServerConnectionVo $connection): void
            {
                $this->disconnected[] = $connection;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new class extends Sender
        {
            /** @var list<array<string, mixed>> */
            public array $messages = [];

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'push') {
                    $this->messages[] = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);
                }

                return true;
            }
        };
        $user = $this->user();
        $loginToken = $this->token($user);
        $server = $this->gameServerWithConnections($provider, [], $sender);
        $socketServer = new class
        {
            /** @var list<int> */
            public array $disconnected = [];

            public function disconnect(int $fd): void
            {
                $this->disconnected[] = $fd;
            }
        };

        $server->onOpen($socketServer, $this->webSocketRequest(11, $loginToken));
        $firstClientId = $sender->messages[0]['payload']['client_id'];
        $server->onOpen($socketServer, $this->webSocketRequest(12, $loginToken));
        $secondClientId = $sender->messages[1]['payload']['client_id'];
        self::assertNotSame($firstClientId, $secondClientId);
        self::assertSame($firstClientId, $provider->connected[0]->clientId);
        self::assertSame($secondClientId, $provider->connected[1]->clientId);

        $server->onClose($socketServer, 11, 0);
        self::assertSame($provider->connected[0], $provider->disconnected[0]);
        $server->onOpen($socketServer, $this->webSocketRequest(13, $loginToken, $firstClientId));

        self::assertSame($firstClientId, $sender->messages[2]['payload']['client_id']);
        self::assertNotSame($provider->connected[0], $provider->connected[2]);
        $otherUser = $this->user('other@example.test');
        $otherToken = $this->token($otherUser, 'other-secret');
        $server->onOpen($socketServer, $this->webSocketRequest(14, $otherToken, $firstClientId));
        self::assertSame([14], $socketServer->disconnected);
        self::assertCount(3, $provider->connected);

        $server->onClose($socketServer, 13, 0);
        $renewedLoginToken = $this->token($user, 'renewed-secret');
        $server->onOpen($socketServer, $this->webSocketRequest(15, $renewedLoginToken, $firstClientId));
        self::assertSame($firstClientId, $sender->messages[3]['payload']['client_id']);
    }

    public function test_on_close_notifies_provider_for_disconnected_client(): void
    {
        $provider = new class extends BaseProvider
        {
            /** @var list<int> */
            public array $disconnectedFds = [];

            public function disconnect(GameServerConnectionVo $connection): void
            {
                $this->disconnectedFds[] = $connection->fd;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $user = new User;
        $user->id = 1;
        $server = $this->gameServerWithConnections($provider, [
            11 => new GameServerConnectionVo(11, $user, 'client-id'),
        ]);

        $server->onClose(null, 11, 0);

        self::assertSame([11], $provider->disconnectedFds);
    }

    public function test_close_during_upstream_connect_cleans_up_without_accepting_client(): void
    {
        $provider = new class extends BaseProvider
        {
            public ?Closure $onConnect = null;

            /** @var list<GameServerConnectionVo> */
            public array $disconnected = [];

            public function connect(GameServerConnectionVo $connection): void
            {
                if ($this->onConnect !== null) {
                    ($this->onConnect)($connection);
                }
            }

            public function disconnect(GameServerConnectionVo $connection): void
            {
                $this->disconnected[] = $connection;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new class extends Sender
        {
            public int $pushes = 0;

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                $this->pushes++;

                return true;
            }
        };
        $server = $this->gameServerWithConnections($provider, [], $sender);
        $provider->onConnect = static function (GameServerConnectionVo $connection) use ($server): void {
            $server->onClose(null, $connection->fd, 0);
        };
        $user = $this->user();
        $socketServer = new class
        {
            public function disconnect(int $fd): void {}
        };

        $server->onOpen($socketServer, $this->webSocketRequest(11, $this->token($user)));

        self::assertSame(0, $sender->pushes);
        self::assertCount(2, $provider->disconnected);
        self::assertSame($provider->disconnected[0], $provider->disconnected[1]);
    }

    public function test_failed_accept_send_disconnects_upstream_client(): void
    {
        $provider = new class extends BaseProvider
        {
            /** @var list<GameServerConnectionVo> */
            public array $disconnected = [];

            public function disconnect(GameServerConnectionVo $connection): void
            {
                $this->disconnected[] = $connection;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new class extends Sender
        {
            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                return false;
            }
        };
        $server = $this->gameServerWithConnections($provider, [], $sender);
        $socketServer = new class
        {
            /** @var list<int> */
            public array $disconnected = [];

            public function disconnect(int $fd): void
            {
                $this->disconnected[] = $fd;
            }
        };
        $user = $this->user();

        $server->onOpen($socketServer, $this->webSocketRequest(11, $this->token($user)));

        self::assertSame([11], $socketServer->disconnected);
        self::assertCount(1, $provider->disconnected);
    }

    public function test_old_message_cannot_use_restored_clients_upstream_connection(): void
    {
        $provider = new class extends BaseProvider
        {
            public int $requests = 0;

            public function requestAction(GameVo $game, Closure $callback): void
            {
                $this->requests++;
            }
        };
        $sender = new class extends Sender
        {
            /** @var list<array<string, mixed>> */
            public array $messages = [];

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'push') {
                    $this->messages[] = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);
                }

                return true;
            }
        };
        $redis = new class extends Redis
        {
            public string $game = '';

            public ?Closure $onGet = null;

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name !== 'get') {
                    throw new RuntimeException('Unexpected Redis command: '.$name);
                }
                if ($this->onGet !== null) {
                    ($this->onGet)();
                    $this->onGet = null;
                }

                return $this->game;
            }
        };
        $container = ApplicationContext::getContainer();
        $gameService = new GameService(
            new GameProviderManager,
            $redis,
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $server = $this->gameServerWithConnections($provider, [], $sender, $gameService);
        $socketServer = new class
        {
            public function disconnect(int $fd): void {}
        };
        $token = $this->token($this->user());
        $server->onOpen($socketServer, $this->webSocketRequest(11, $token));
        $clientId = $sender->messages[0]['payload']['client_id'];
        $game = GameVoFixture::headsUp(clientId: $clientId);
        $redis->game = serialize($game);
        $redis->onGet = function () use ($server, $socketServer, $token, $clientId): void {
            $server->onClose($socketServer, 11, 0);
            $server->onOpen($socketServer, $this->webSocketRequest(11, $token, $clientId));
        };
        $frame = new Frame;
        $frame->fd = 11;
        $frame->data = json_encode([
            'id' => 'old-request',
            'type' => GameServer::TYPE_REQUEST_ACTION,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['game_uuid' => $game->uuid],
        ], JSON_THROW_ON_ERROR);

        $server->onMessage($socketServer, $frame);

        self::assertSame(0, $provider->requests);
        self::assertCount(2, $sender->messages);
    }

    public function test_on_open_disconnects_when_token_validation_throws(): void
    {
        /** @var Container $container */
        $container = ApplicationContext::getContainer();
        $previousResolver = $container->get(ConnectionResolverInterface::class);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willThrowException(new RuntimeException('Connection pool exhausted.'));
        $server = new class
        {
            /** @var list<int> */
            public array $disconnected = [];

            public function disconnect(int $fd): bool
            {
                $this->disconnected[] = $fd;

                return true;
            }
        };
        $request = (object) ['fd' => 42, 'get' => ['token' => '1|test-secret']];
        $error = null;

        try {
            $container->set(ConnectionResolverInterface::class, $resolver);
            try {
                $container->get(GameServer::class)->onOpen($server, $request);
            } catch (Throwable $caught) {
                $error = $caught;
            }
        } finally {
            $container->set(ConnectionResolverInterface::class, $previousResolver);
        }

        self::assertNull($error, 'Authentication exceptions must be handled by onOpen.');
        self::assertSame([42], $server->disconnected);
    }

    /** @param array<int, GameServerConnectionVo> $connections */
    private function gameServerWithConnections(
        BaseProvider $provider,
        array $connections,
        ?Sender $sender = null,
        ?GameService $gameService = null,
    ): GameServer {
        $container = ApplicationContext::getContainer();
        $manager = new GameProviderManager;
        $manager->extend($manager->getDefaultProvider(), static fn (): ProviderInterface => $provider);
        $server = new GameServer(
            $sender ?? $container->get(Sender::class),
            $manager,
            $container->get(UserTokenService::class),
            $gameService ?? $container->get(GameService::class),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
            $container->get(Encrypter::class),
        );
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, $connections);

        return $server;
    }

    private function webSocketRequest(int $fd, string $token, ?string $clientId = null): SwooleRequest
    {
        $request = new SwooleRequest;
        $request->fd = $fd;
        $request->get = ['token' => $token];
        if ($clientId !== null) {
            $request->get['client_id'] = $clientId;
        }

        return $request;
    }
}
