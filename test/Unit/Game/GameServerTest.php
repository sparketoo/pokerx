<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Enum\ActionEnum;
use App\Enum\NetworkEnum;
use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\MockProvider;
use App\Game\Providers\ProtoProvider;
use App\Game\Socket\ProtoWebSocketClient;
use App\Model\User;
use App\Model\UserToken;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Context\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeGameServerSender;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\FakeProtoSocketRedis;
use Tests\Fixtures\FakeWebSocketTransport;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameServerTest extends TestCase
{
    public function test_first_message_waits_for_upstream_authentication(): void
    {
        \Swoole\Coroutine\run(function (): void {
            [$server, $sender] = $this->newServer(false);
            $user = new User(['language' => 'zh-CN']);
            $user->id = 1;
            $token = new UserToken;
            $token->id = 42;
            $connection = new GameServerConnectionVo(7, $user, $token);
            $connection->ready = false;
            (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [7 => $connection]);

            Coroutine::create(static function () use ($connection): void {
                Coroutine::sleep(0.1);
                $connection->ready = true;
            });
            $this->send($server, 7, ['id' => 'early-ping', 'type' => 'PING', 'timestamp' => $this->now()]);

            self::assertSame('PING.ACK', $sender->messages[0]['type']);
            self::assertSame([], $sender->disconnected);
        });
    }

    public function test_other_client_cannot_change_or_read_a_proto_socket_game(): void
    {
        [$server, $sender, $providers, $redis] = $this->newServer(false);
        $game = GameVoFixture::headsUp(clientId: '42');
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers->extend('proto', static fn (): ProtoProvider => new ProtoProvider(
            ['url' => 'ws://proto.test/', 'token' => 'proto-secret'], new FakeProtoSocketRedis,
        ));
        $user = new User(['language' => 'zh-CN']);
        $user->id = 1;
        $token = new UserToken;
        $token->id = 43;
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, $user, $token),
        ]);

        foreach ([
            ['type' => 'ACTION', 'payload' => ['game_uuid' => $game->uuid, 'uid' => 'hero', 'action' => 'CALL', 'amount' => 50]],
            ['type' => 'ABORT', 'payload' => ['game_uuid' => $game->uuid]],
            ['type' => 'REQUEST_INSURANCE', 'payload' => ['game_uuid' => $game->uuid, 'stage' => 'FLOP', 'outs' => 1, 'pot' => 100, 'odds' => 2, 'min' => 1, 'max' => 10, 'breakeven' => 5]],
        ] as $index => $request) {
            $this->send($server, 1, [
                'id' => 'foreign-'.$index,
                'type' => $request['type'],
                'timestamp' => $this->now(),
                'payload' => $request['payload'],
            ]);
            self::assertSame(3001, $sender->messages[$index]['payload']['code']);
        }

        $saved = unserialize($redis->get('game:'.$game->uuid));
        self::assertInstanceOf(GameVo::class, $saved);
        self::assertSame(0, $saved->events->count());
    }

    public function test_closing_downstream_fd_closes_its_proto_socket_client(): void
    {
        \Swoole\Coroutine\run(function (): void {
            [$server, , $providers] = $this->newServer(false);
            $redis = new FakeProtoSocketRedis;
            $socket = new FakeWebSocketTransport;
            $socket->receive('{"result":true,"sessionId":"session-42"}');
            $options = ['url' => 'ws://proto.test/', 'token' => 'proto-secret'];
            $provider = new ProtoProvider($options, $redis);
            $client = new ProtoWebSocketClient(1, '42', $options, $redis, static fn (): Client => $socket);
            $client->connect();
            (new ReflectionProperty(ProtoProvider::class, 'clients'))->setValue($provider, [
                '1:42' => ['fd' => 7, 'client' => $client],
            ]);
            $providers->extend('proto', static fn (): ProtoProvider => $provider);
            $user = new User(['language' => 'zh-CN']);
            $user->id = 1;
            $token = new UserToken;
            $token->id = 42;
            (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
                7 => new GameServerConnectionVo(7, $user, $token),
            ]);

            $server->onClose(null, 7, 0);

            self::assertTrue($socket->closed);
        });
    }

    public function test_repeated_action_requests_have_distinct_client_message_ids_in_logs(): void
    {
        $handler = new TestHandler;
        $logger = new Logger('game-server-test');
        $logger->pushHandler($handler);
        [$server, $sender, $providers, $redis] = $this->newServer(true, $logger);
        $game = GameVoFixture::headsUp();
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers->extend('proto', static fn (): BaseProvider => new class extends BaseProvider
        {
            public function requestAction(GameVo $game, \Closure $callback): void
            {
                $callback(RequestActionResultVo::success(ActionEnum::CHECK, 0));
            }
        });

        foreach (['client-first', 'client-second'] as $messageId) {
            $this->send($server, 1, [
                'id' => $messageId,
                'type' => 'REQUEST_ACTION',
                'timestamp' => $this->now(),
                'payload' => ['game_uuid' => $game->uuid],
            ]);
        }

        self::assertCount(2, $sender->messages);
        $requested = array_values(array_filter($handler->getRecords(), static fn ($record): bool => $record->message === 'Poker action requested'));
        $answered = array_values(array_filter($handler->getRecords(), static fn ($record): bool => $record->message === 'Poker action answered'));
        self::assertCount(2, $requested);
        self::assertCount(2, $answered);
        self::assertSame(['client-first', 'client-second'], array_column(array_map(
            static fn ($record): array => $record->context,
            $requested,
        ), 'message_id'));
        self::assertSame($game->uuid, $requested[0]->context['game_id']);
        self::assertSame('client-second', $answered[1]->context['message_id']);
    }

    public function test_invalid_connection_can_receive_auth_error_without_recursion(): void
    {
        [$server, $sender] = $this->newServer(false);

        \Swoole\Coroutine\run(function () use ($server): void {
            $this->send($server, 2, ['id' => 'missing-auth', 'type' => 'PING', 'timestamp' => $this->now()]);
        });

        self::assertSame(2000, $sender->messages[0]['payload']['code']);
        self::assertSame('missing-auth', $sender->messages[0]['reply_to']);
        self::assertSame([2], $sender->disconnected);
        \Swoole\Coroutine\run(static function () use ($server): void {
            $server->onClose(null, 2, 0);
        });
    }

    public function test_malformed_json_is_reported_as_invalid_event(): void
    {
        [$server, $sender] = $this->newServer();

        $this->sendRaw($server, 1, '{invalid');

        self::assertSame(3002, $sender->messages[0]['payload']['code']);
        self::assertNull($sender->messages[0]['reply_to']);
    }

    public function test_invalid_envelope_types_are_reported_as_invalid_event(): void
    {
        [$server, $sender] = $this->newServer();

        $this->send($server, 1, ['id' => 7, 'type' => 'PING', 'timestamp' => $this->now()]);
        $this->send($server, 1, ['id' => 'bad-payload', 'type' => 'START', 'timestamp' => $this->now(), 'payload' => 'invalid']);

        self::assertSame(3002, $sender->messages[0]['payload']['code']);
        self::assertNull($sender->messages[0]['reply_to']);
        self::assertSame(3002, $sender->messages[1]['payload']['code']);
        self::assertSame('bad-payload', $sender->messages[1]['reply_to']);
    }

    public function test_unexpected_exception_has_safe_client_message(): void
    {
        [$server, $sender, $providers, $redis] = $this->newServer();
        $game = GameVoFixture::headsUp();
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers->extend('proto', static fn (): never => throw new RuntimeException('private provider details'));

        $this->send($server, 1, [
            'id' => 'action-1',
            'type' => 'REQUEST_ACTION',
            'timestamp' => $this->now(),
            'payload' => ['game_uuid' => $game->uuid],
        ]);

        self::assertSame(5000, $sender->messages[0]['payload']['code']);
        self::assertSame('服务暂不可用', $sender->messages[0]['payload']['message']);
        self::assertSame('action-1', $sender->messages[0]['reply_to']);
    }

    /** @return array{GameServer, FakeGameServerSender, GameProviderManager, FakeProtoHttpRedis} */
    private function newServer(bool $connected = true, ?LoggerInterface $logger = null): array
    {
        $sender = new FakeGameServerSender;
        $providers = new GameProviderManager;
        $redis = new FakeProtoHttpRedis;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, $redis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $logger ?? $container->get(LoggerInterface::class),
        );
        if ($connected) {
            $connections = [1 => new GameServerConnectionVo(1, new User(['language' => 'zh-CN']), new UserToken)];
            (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, $connections);
        }

        return [$server, $sender, $providers, $redis];
    }

    /** @param array<string, mixed> $message */
    private function send(GameServer $server, int $fd, array $message): void
    {
        $this->sendRaw($server, $fd, json_encode($message, JSON_THROW_ON_ERROR));
    }

    private function sendRaw(GameServer $server, int $fd, string $data): void
    {
        $frame = new Frame;
        $frame->fd = $fd;
        $frame->data = $data;
        $server->onMessage(null, $frame);
    }

    private function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public function test_message_timestamps_do_not_move_backwards_within_a_connection(): void
    {
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

                    return true;
                }

                return false;
            }
        };
        $providers = new GameProviderManager;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, new FakeProtoHttpRedis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, new User(['language' => 'zh-CN']), new UserToken),
            2 => new GameServerConnectionVo(2, new User(['language' => 'zh-CN']), new UserToken),
        ]);

        $send = static function (int $fd, string $id, string $type, int|string $timestamp, ?array $payload = null) use ($server, $sender): array {
            $message = compact('id', 'type', 'timestamp');
            if ($payload !== null) {
                $message['payload'] = $payload;
            }
            $frame = new Frame;
            $frame->fd = $fd;
            $frame->data = json_encode($message, JSON_THROW_ON_ERROR);
            $before = count($sender->messages);
            $server->onMessage(null, $frame);
            self::assertCount($before + 1, $sender->messages);

            return $sender->messages[$before];
        };

        $timestamp = (int) floor(microtime(true) * 1000);
        $nonInteger = $send(1, 'ping-string', 'PING', (string) ($timestamp + 100));
        self::assertSame('error', $nonInteger['type']);
        self::assertSame(3002, $nonInteger['payload']['code']);

        $first = $send(1, 'ping-1', 'PING', $timestamp);
        self::assertSame('PING.ACK', $first['type']);
        self::assertSame('ping-1', $first['reply_to']);
        self::assertSame([], $first['payload']);

        $invalidStart = $send(1, 'start-1', 'START', $timestamp + 1, []);
        self::assertSame('error', $invalidStart['type']);
        self::assertSame(3002, $invalidStart['payload']['code']);

        $older = $send(1, 'ping-old', 'PING', $timestamp);
        self::assertSame('error', $older['type']);
        self::assertSame(3002, $older['payload']['code']);
        self::assertSame('ping-old', $older['reply_to']);

        $olderAgain = $send(1, 'ping-old-again', 'PING', $timestamp);
        self::assertSame('error', $olderAgain['type']);

        $equal = $send(1, 'ping-equal', 'PING', $timestamp + 1);
        self::assertSame('PING.ACK', $equal['type']);
        self::assertSame('ping-equal', $equal['reply_to']);

        $otherConnection = $send(2, 'ping-other', 'PING', $timestamp);
        self::assertSame('PING.ACK', $otherConnection['type']);
    }

    public function test_post_blind_ack_validates_amount_and_records_another_player(): void
    {
        $sender = new FakeGameServerSender;
        $redis = new FakeProtoHttpRedis;
        $game = new GameVo(1, '12345678-90ab-4cde-8f01-23456789abce', NetworkEnum::OK, 'room#129', 2, 1, 2, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 190, 'hero' => true],
            ['uid' => 'small', 'seat' => 2, 'stack' => 190, 'hero' => false],
            ['uid' => 'big', 'seat' => 3, 'stack' => 190, 'hero' => false],
            ['uid' => 'other', 'seat' => 4, 'stack' => 190, 'hero' => false],
        ], 1);
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers = new GameProviderManager;
        $providers->extend($providers->getDefaultProvider(), static fn (): MockProvider => new MockProvider(1));
        $service = new GameService($providers, $redis);
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            $service,
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, new User(['id' => 1, 'language' => 'zh-CN']), new UserToken),
        ]);

        $send = static function (string $id, array $payload, string $type = 'POST_BLIND') use ($server, $sender): array {
            $frame = new Frame;
            $frame->fd = 1;
            $frame->data = json_encode([
                'id' => $id,
                'type' => $type,
                'timestamp' => (int) floor(microtime(true) * 1000),
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR);
            $server->onMessage(null, $frame);

            return $sender->messages[count($sender->messages) - 1];
        };

        $payload = ['game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => 2];
        self::assertSame(3002, $send('invalid-amount', [...$payload, 'amount' => '2'])['payload']['code']);
        self::assertSame(1000, $send('unknown', [...$payload, 'uid' => 'absent'])['payload']['code']);
        $accepted = $send('valid', $payload);
        self::assertSame('POST_BLIND.ACK', $accepted['type']);
        self::assertSame('valid', $accepted['reply_to']);
        self::assertSame([], $accepted['payload']);
        self::assertSame(2, $service->find($game->uuid)->playerOrFail('other')->postBlind);
        self::assertSame(3002, $send('duplicate', $payload)['payload']['code']);
        self::assertCount(1, $service->find($game->uuid)->events);
        self::assertSame('STAGE.ACK', $send('preflop', [
            'game_uuid' => $game->uuid, 'stage' => 'PREFLOP', 'cards' => [],
        ], 'STAGE')['type']);
        self::assertSame(3002, $send('bad-straddle', [
            'game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => '4',
        ], 'STRADDLE_BLIND')['payload']['code']);
        $straddle = $send('valid-straddle', [
            'game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => 4,
        ], 'STRADDLE_BLIND');
        self::assertSame('STRADDLE_BLIND.ACK', $straddle['type']);
        self::assertSame('valid-straddle', $straddle['reply_to']);
        self::assertSame(4, $service->find($game->uuid)->playerOrFail('other')->straddleBlind);
    }
}
