<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\IdelProvider;
use App\Game\Providers\ProtoHttpProvider;
use App\Game\Providers\ProtoProvider;
use App\Game\Providers\ProviderInterface;
use App\Model\User;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use App\Service\UserTokenService;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameServerMessageVo;
use App\Vo\Game\GameVo;
use Closure;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Cache\Cache;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Di\Container;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Contract\Http\RawResponseInterface;
use Hyperf\Engine\Http\RawResponse;
use Hyperf\Redis\Redis;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Hyperf\WebSocketServer\Sender;
use Illuminate\Encryption\Encrypter;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeGameServerSender;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\Fixtures\ProtoHttpLoopbackClient;
use Tests\Support\DatabaseTestCase;
use Throwable;

final class GameServerTest extends DatabaseTestCase
{
    public function test_only_enabled_source_relays_its_named_cards_to_another_worker(): void
    {
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000001', NetworkEnum::WE, 'room-1#1', 100, 50, 10, [
            ['uid' => 'hero', 'name' => 'Alice', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Bob', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1, 'client-a');
        $redis = new class(serialize($game)) extends Redis
        {
            public function __construct(public string $game) {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->game;
                }
                if ($name === 'eval') {
                    $this->game = $arguments[1][2];

                    return 1;
                }
                if ($name === 'lRange') {
                    return [json_encode([
                        'uuid' => '22222222-2222-4222-8222-000000000002', 'fd' => 22,
                        'worker_id' => 1, 'client_id' => 'client-b', 'uid' => 'villain',
                        'name' => 'Bob', 'seat' => 2, 'stack' => 1000,
                    ], JSON_THROW_ON_ERROR)];
                }
                throw new RuntimeException($name);
            }
        };
        $cache = new class extends Cache
        {
            public bool $enabled = true;

            public function __construct() {}

            public function get($key, $default = null): mixed
            {
                return ['share_hole_cards' => $this->enabled ? '1' : '0'];
            }
        };
        $container = ApplicationContext::getContainer();
        $service = new GameService(new GameProviderManager, $redis, $container->get(DriverFactory::class), $container->get(LoggerInterface::class));
        $user = new User;
        $user->id = 1;
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
        $connection->ready = true;
        $sender = new FakeGameServerSender;
        $server = $this->gameServerWithConnections(new class extends BaseProvider
        {
            public function requestAction(GameVo $game, Closure $callback): void {}
        }, [11 => $connection], $sender, $service, new UserGameConfigService($cache));
        $socketServer = new class
        {
            public int $worker_id = 0;

            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function sendMessage(string $message, int $workerId): void
            {
                $this->sent[] = ['worker_id' => $workerId, 'message' => json_decode($message, true, 512, JSON_THROW_ON_ERROR)];
            }
        };
        (new ReflectionProperty(GameServer::class, 'socketServer'))->setValue($server, $socketServer);

        $server->handleDealt(new GameServerMessageVo($connection, 'dealt-1', 'DEALT', [
            'game_uuid' => $game->uuid, 'cards' => ['As', 'Kd'],
        ], time() * 1000));

        self::assertSame('DEALT.ACK', $sender->messages[0]['type']);
        self::assertSame(1, $socketServer->sent[0]['worker_id']);
        self::assertSame(['uid' => 'hero', 'name' => 'Alice', 'cards' => ['As', 'Kd']], array_intersect_key(
            $socketServer->sent[0]['message'], array_flip(['uid', 'name', 'cards']),
        ));

        $cache->enabled = false;
        $redis->game = serialize($game);
        $socketServer->sent = [];
        $server->handleDealt(new GameServerMessageVo($connection, 'dealt-2', 'DEALT', [
            'game_uuid' => $game->uuid, 'cards' => ['As', 'Kd'],
        ], time() * 1000));
        self::assertSame([], $socketServer->sent);
    }

    public function test_shared_show_reaches_only_the_registered_client_without_a_game_uuid(): void
    {
        $game = GameVoFixture::headsUp();
        $redis = new class(serialize($game)) extends Redis
        {
            public function __construct(private string $game) {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                return $name === 'get' ? $this->game : throw new RuntimeException($name);
            }
        };
        $sender = new FakeGameServerSender;
        $container = ApplicationContext::getContainer();
        $service = new GameService(new GameProviderManager, $redis, $container->get(DriverFactory::class), $container->get(LoggerInterface::class));
        $user = new User;
        $user->id = 1;
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
        $connection->ready = true;
        $server = $this->gameServerWithConnections(new class extends BaseProvider
        {
            public function requestAction(GameVo $game, Closure $callback): void {}
        }, [11 => $connection], $sender, $service);
        $message = [
            'target_uuid' => $game->uuid, 'fd' => 11, 'client_id' => 'client-a',
            'network' => 'WE', 'game_key' => 'room-1#1', 'uid' => 'villain',
            'name' => 'Bob', 'cards' => ['As', 'Kd'],
        ];

        $server->deliverSharedShow($message);
        self::assertSame(['network' => 'WE', 'game_key' => 'room-1#1', 'uid' => 'villain', 'name' => 'Bob', 'cards' => ['As', 'Kd']], $sender->messages[0]['payload']);
        self::assertSame('SHOW', $sender->messages[0]['type']);

        $server->deliverSharedShow([...$message, 'client_id' => 'stale-client']);
        self::assertCount(1, $sender->messages);
    }

    public function test_insurance_purchase_and_over_payout_share_the_game_state(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kh', 'Qd']], time() * 1000);
        $redis = new class(serialize($game)) extends Redis
        {
            public function __construct(public string $game) {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->game;
                }
                if ($name === 'eval' && $arguments[1][1] === $this->game) {
                    $this->game = $arguments[1][2];

                    return 1;
                }
                throw new RuntimeException('Unexpected Redis command: '.$name);
            }
        };
        $sender = new class extends Sender
        {
            /** @var list<array<string, mixed>> */
            public array $messages = [];

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): bool
            {
                $this->messages[] = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);

                return true;
            }
        };
        $container = ApplicationContext::getContainer();
        $service = new GameService(new GameProviderManager, $redis, $container->get(DriverFactory::class), $container->get(LoggerInterface::class));
        $user = new User;
        $user->id = 1;
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
        $server = $this->gameServerWithConnections(new class extends BaseProvider
        {
            public function requestAction(GameVo $game, Closure $callback): void {}
        }, [11 => $connection], $sender, $service);
        $server->handleInsurancePurchased(new GameServerMessageVo($connection, 'buy-1', 'INSURANCE_PURCHASED', [
            'game_uuid' => $game->uuid, 'uid' => 'hero', 'stage' => 'FLOP',
            'pot_id' => 1, 'odds' => '2.50', 'amount' => 40,
        ], time() * 1000));
        $server->handleOver(new GameServerMessageVo($connection, 'over-1', 'OVER', [
            'game_uuid' => $game->uuid, 'winners' => [['uid' => 'villain', 'amount' => 0]], 'insurance_payout' => 100,
        ], time() * 1000));

        self::assertSame(['INSURANCE_PURCHASED.ACK', 'OVER.ACK'], array_column($sender->messages, 'type'));
        $saved = unserialize($redis->game);
        self::assertSame(40, $saved->insuranceAmount());
        self::assertSame(100, $saved->insurancePayout());
        self::assertCount(3, $saved->events);
    }

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
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
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
        self::assertSame(2, $provider->posted->game->pot());
    }

    public function test_squid_start_award_and_over_use_the_client_contract(): void
    {
        $provider = new class extends BaseProvider
        {
            public ?GameVo $started = null;

            public ?GameEventVo $overEvent = null;

            public function start(GameVo $game): void
            {
                $this->started = $game;
            }

            public function over(GameEventVo $event): void
            {
                $this->overEvent = $event;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new FakeGameServerSender;
        $user = $this->user();
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
        $server = $this->gameServerWithConnections($provider, [11 => $connection], $sender);
        $server->handleStart(new GameServerMessageVo($connection, 'start-squid', 'START', [
            'game_key' => 'squid-'.bin2hex(random_bytes(4)).'#1', 'network' => 'OK', 'ante' => 0,
            'small_blind' => 3, 'big_blind' => 6, 'button_seat_number' => 1,
            'gameType' => 'SQUID', 'squidMode' => 'HUNT', 'squidCost' => 24,
            'squidNumber' => 8, 'squidRound' => 1, 'squidPlayed' => 2,
            'players' => [
                ['uid' => 'hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
                ['uid' => 'villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
            ],
        ], time() * 1000));

        self::assertNotNull($provider->started);
        self::assertSame('SQUID', $provider->started->gameType);
        self::assertSame('HUNT', $provider->started->squidMode);
        self::assertSame(24, $provider->started->squidCost);
        self::assertSame(8, $provider->started->squidNumber);
        self::assertSame(2, $provider->started->squidPlayed);
        self::assertSame([], $provider->started->squidPlayers);
        $uuid = $provider->started->uuid;

        $server->handlePlayerHasSquid(new GameServerMessageVo($connection, 'has-squid-hero', 'PLAYER_HAS_SQUID', [
            'game_uuid' => $uuid, 'uid' => 'hero', 'count' => 2,
        ], time() * 1000));
        $server->handlePlayerHasSquid(new GameServerMessageVo($connection, 'has-squid-villain', 'PLAYER_HAS_SQUID', [
            'game_uuid' => $uuid, 'uid' => 'villain', 'count' => 0,
        ], time() * 1000));

        $server->handleGotSquid(new GameServerMessageVo($connection, 'award-squid', 'GOT_SQUID', [
            'game_uuid' => $uuid, 'uid' => 'hero', 'count' => 2,
        ], time() * 1000));
        $server->handleOver(new GameServerMessageVo($connection, 'over-squid', 'OVER', [
            'game_uuid' => $uuid, 'winners' => [['uid' => 'hero', 'amount' => 20]],
            'squid' => [
                ['type' => 'penaly', 'uid' => 'villain', 'amount' => 48],
                ['type' => 'payout', 'uid' => 'hero', 'amount' => 48],
            ],
        ], time() * 1000));

        self::assertSame(['START.ACK', 'PLAYER_HAS_SQUID.ACK', 'PLAYER_HAS_SQUID.ACK', 'GOT_SQUID.ACK', 'OVER.ACK'], array_column($sender->messages, 'type'));
        self::assertNotNull($provider->overEvent);
        self::assertSame(['PLAYER_HAS_SQUID', 'PLAYER_HAS_SQUID', 'GOT_SQUID', 'OVER'], array_map(
            static fn (GameEventVo $event): string => $event->type->name,
            $provider->overEvent->game->events->all(),
        ));
        self::assertSame('penaly', $provider->overEvent->payload['squid'][0]['type']);
    }

    public function test_wpk_classic_round_from_captured_hand_events_reaches_proto_http(): void
    {
        $fixtureJson = file_get_contents(__DIR__.'/../../Fixtures/wpk_classic_round_10_replay.json');
        if ($fixtureJson === false) {
            throw new RuntimeException('Could not read the WPK classic replay fixture.');
        }
        $fixture = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
        $commands = $this->replayWpkSquidHands(array_values($fixture['hands']));
        $partial = array_values(array_filter($commands, static fn (array $command): bool => $command['structType'] === 'gameEvents'));
        $complete = array_values(array_filter($commands, static fn (array $command): bool => $command['structType'] === 'fullGameLog'));

        self::assertCount(5, $partial);
        self::assertCount(4, $complete, 'The captured first hand has no terminal event and must remain open.');
        self::assertSame([0, 1, 1, 1, 2], array_map(static fn (array $command): int => $command['game']['squidPlayed'], $partial));
        self::assertSame([1, 1, 2, 3], array_map(static fn (array $command): int => $command['game']['squidPlayed'], $complete));
        foreach ($complete as $index => $command) {
            self::assertSame('NLSQ', $command['game']['gameType']);
            self::assertSame('STAND_UP', $command['game']['squidMode']);
            self::assertSame([1, 1, 1, 2][$index], count(array_filter($command['events'],
                static fn (array $event): bool => $event['eventType'] === 'playerHasSquid')));
            self::assertSame('gameOver', $command['events'][array_key_last($command['events'])]['eventType']);
        }
        $last = end($complete);
        self::assertIsArray($last);
        $last = $last['events'];
        self::assertSame(1, count(array_filter($last, static fn (array $event): bool => $event['eventType'] === 'playerGotSquid')));
        self::assertSame(1, count(array_filter($last, static fn (array $event): bool => $event['eventType'] === 'squidPenalty')));
        self::assertSame(3, count(array_filter($last, static fn (array $event): bool => $event['eventType'] === 'squidPayment')));
        self::assertSame(900, array_values(array_filter($last, static fn (array $event): bool => $event['eventType'] === 'squidPenalty'))[0]['amount']);
    }

    public function test_wpk_blood_round_reaches_proto_http_with_multiple_squid_awards(): void
    {
        // HAR confirms HUNT mode and a 2+1 fish distribution; native frame order is not present.
        $commands = $this->replayWpkSquidHands($this->wpkHuntRoundHands('blood', 'p2', [
            ['uid' => 'p1', 'type' => 'payout', 'amount' => 1200],
            ['uid' => 'p2', 'type' => 'payout', 'amount' => 600],
            ['uid' => 'p3', 'type' => 'penaly', 'amount' => 900],
            ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900],
        ]));
        $complete = array_values(array_filter($commands, static fn (array $command): bool => $command['structType'] === 'fullGameLog'));

        self::assertCount(2, $complete);
        self::assertSame([2, 3], array_map(static fn (array $command): int => $command['game']['squidPlayed'], $complete));
        self::assertSame([2, 1], array_map(static fn (array $command): int => count(array_filter($command['events'],
            static fn (array $event): bool => $event['eventType'] === 'playerGotSquid')), $complete));
        self::assertSame('HUNT', $complete[1]['game']['squidMode']);
        self::assertSame([1200, 600], array_column(array_values(array_filter($complete[1]['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPayment')), 'amount'));
        self::assertSame([900, 900], array_column(array_values(array_filter($complete[1]['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPenalty')), 'amount'));
    }

    public function test_wpk_doubling_round_forwards_platform_settlement_without_repricing(): void
    {
        // WPK's 3-fish multiplier is not in the shared protocol; the platform's signed settlement is authoritative.
        $commands = $this->replayWpkSquidHands($this->wpkHuntRoundHands('doubling', 'p1', [
            ['uid' => 'p1', 'type' => 'payout', 'amount' => 5400],
            ['uid' => 'p2', 'type' => 'penaly', 'amount' => 1800],
            ['uid' => 'p3', 'type' => 'penaly', 'amount' => 1800],
            ['uid' => 'p4', 'type' => 'penaly', 'amount' => 1800],
        ]), requestAction: false);
        $complete = array_values(array_filter($commands, static fn (array $command): bool => $command['structType'] === 'fullGameLog'));

        self::assertCount(2, $complete);
        self::assertCount(2, $commands, 'WPK disables automatic action advice for multiplier rooms.');
        self::assertSame([2, 3], array_map(static fn (array $command): int => $command['game']['squidPlayed'], $complete));
        self::assertSame('HUNT', $complete[1]['game']['squidMode']);
        self::assertSame(300, $complete[1]['game']['squidCost']);
        self::assertSame(5400, array_values(array_filter($complete[1]['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPayment'))[0]['amount']);
        self::assertSame([1800, 1800, 1800], array_column(array_values(array_filter($complete[1]['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPenalty')), 'amount'));
    }

    public function test_wpk_three_rounds_through_local_proto_http_peer(): void
    {
        $url = getenv('WPK_PROTO_REPLAY_URL');
        $capture = getenv('WPK_PROTO_REPLAY_CAPTURE');
        if (! is_string($url) || $url === '' || ! is_string($capture) || $capture === '') {
            self::markTestSkipped('Start the local Proto HTTP peer and set WPK_PROTO_REPLAY_URL and WPK_PROTO_REPLAY_CAPTURE.');
        }
        $fixtureJson = file_get_contents(__DIR__.'/../../Fixtures/wpk_classic_round_10_replay.json');
        if ($fixtureJson === false) {
            throw new RuntimeException('Could not read the WPK classic replay fixture.');
        }
        $fixture = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
        $this->replayWpkSquidHands(array_values($fixture['hands']), httpUrl: $url);
        $this->replayWpkSquidHands($this->wpkHuntRoundHands('blood', 'p2', [
            ['uid' => 'p1', 'type' => 'payout', 'amount' => 1200],
            ['uid' => 'p2', 'type' => 'payout', 'amount' => 600],
            ['uid' => 'p3', 'type' => 'penaly', 'amount' => 900],
            ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900],
        ]), httpUrl: $url);
        $this->replayWpkSquidHands($this->wpkHuntRoundHands('doubling', 'p1', [
            ['uid' => 'p1', 'type' => 'payout', 'amount' => 5400],
            ['uid' => 'p2', 'type' => 'penaly', 'amount' => 1800],
            ['uid' => 'p3', 'type' => 'penaly', 'amount' => 1800],
            ['uid' => 'p4', 'type' => 'penaly', 'amount' => 1800],
        ]), requestAction: false, httpUrl: $url);

        $lines = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Could not read the Proto HTTP replay capture.');
        }
        $records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        $commands = array_values(array_filter(array_column($records, 'payload')));
        self::assertCount(8, array_filter($commands, static fn (array $command): bool => $command['structType'] === 'fullGameLog'));
        self::assertCount(7, array_filter($commands, static fn (array $command): bool => $command['structType'] === 'gameEvents'));
        self::assertCount(7, array_filter($commands, static fn (array $command): bool => $command['structType'] === 'getAnswer'));
        self::assertSame([3, 3, 3], array_values(array_map(static fn (array $command): int => $command['game']['squidPlayed'],
            array_filter($commands, static fn (array $command): bool => $command['structType'] === 'fullGameLog'
                && $command['game']['squidPlayed'] === 3))));
    }

    public function test_wpk_three_rounds_through_game_server_and_proto_websocket_peer(): void
    {
        $url = getenv('WPK_PROTO_WS_REPLAY_URL');
        $capture = getenv('WPK_PROTO_WS_REPLAY_CAPTURE');
        if (! is_string($url) || $url === '' || ! is_string($capture) || $capture === '') {
            self::markTestSkipped('Start the local Proto WebSocket peer and set WPK_PROTO_WS_REPLAY_URL and WPK_PROTO_WS_REPLAY_CAPTURE.');
        }

        Coroutine\run(function () use ($url): void {
            // 临时表属于数据库连接；协程会使用独立连接，必须在协程内建立测试表。
            $this->createTemporaryTables();
            try {
                $provider = new ProtoProvider([
                    'url' => $url, 'token' => 'wpk-replay-token', 'player_id' => 'wpk-replay',
                    'network' => 'WE', 'request_timeout' => 2, 'delay' => 0,
                ], ApplicationContext::getContainer()->get(Redis::class));
                $fixtureJson = file_get_contents(__DIR__.'/../../Fixtures/wpk_classic_round_10_replay.json');
                if ($fixtureJson === false) {
                    throw new RuntimeException('Could not read the WPK classic replay fixture.');
                }
                $fixture = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
                $this->replayWpkSquidHands(array_values($fixture['hands']), selectedProvider: $provider);
                $this->replayWpkSquidHands($this->wpkHuntRoundHands('blood', 'p2', [
                    ['uid' => 'p1', 'type' => 'payout', 'amount' => 1200],
                    ['uid' => 'p2', 'type' => 'payout', 'amount' => 600],
                    ['uid' => 'p3', 'type' => 'penaly', 'amount' => 900],
                    ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900],
                ]), selectedProvider: $provider);
                $this->replayWpkSquidHands($this->wpkHuntRoundHands('doubling', 'p1', [
                    ['uid' => 'p1', 'type' => 'payout', 'amount' => 5400],
                    ['uid' => 'p2', 'type' => 'penaly', 'amount' => 1800],
                    ['uid' => 'p3', 'type' => 'penaly', 'amount' => 1800],
                    ['uid' => 'p4', 'type' => 'penaly', 'amount' => 1800],
                ]), requestAction: false, selectedProvider: $provider);
            } finally {
                $this->dropTemporaryTables();
            }
        });

        $lines = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Could not read the Proto WebSocket replay capture.');
        }
        $commands = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        self::assertCount(8, array_filter($commands, static fn (array $command): bool => ($command['structType'] ?? null) === 'fullGameLog'));
        self::assertCount(7, array_filter($commands, static fn (array $command): bool => ($command['structType'] ?? null) === 'gameEvents'));
        self::assertCount(7, array_filter($commands, static fn (array $command): bool => ($command['structType'] ?? null) === 'getAnswer'));
        foreach ($commands as $command) {
            foreach ($command['events'] ?? [] as $event) {
                if (($event['eventType'] ?? null) === 'playerHasSquid') {
                    self::assertGreaterThan(0, $event['count']);
                }
            }
        }
    }

    public function test_wpk_classic_and_blood_decisions_through_configured_proto_websocket(): void
    {
        if (getenv('WPK_PROTO_WS_REAL') !== '1') {
            self::markTestSkipped('Set WPK_PROTO_WS_REAL=1 to exercise the configured Proto WebSocket service.');
        }
        $options = ApplicationContext::getContainer()->get(ConfigInterface::class)->get('poker.proto');
        self::assertIsArray($options);
        self::assertNotEmpty($options['url'] ?? null);
        self::assertNotEmpty($options['token'] ?? null);

        Coroutine\run(function () use ($options): void {
            $this->createTemporaryTables();
            try {
                $provider = new ProtoProvider([
                    ...$options, 'request_timeout' => 20, 'delay' => 0,
                ], ApplicationContext::getContainer()->get(Redis::class));
                $fixtureJson = file_get_contents(__DIR__.'/../../Fixtures/wpk_classic_round_10_replay.json');
                if ($fixtureJson === false) {
                    throw new RuntimeException('Could not read the WPK classic replay fixture.');
                }
                $fixture = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
                $this->replayWpkSquidHands(array_slice(array_values($fixture['hands']), 1), selectedProvider: $provider);
                $this->replayWpkSquidHands($this->wpkHuntRoundHands('blood', 'p2', [
                    ['uid' => 'p1', 'type' => 'payout', 'amount' => 1200],
                    ['uid' => 'p2', 'type' => 'payout', 'amount' => 600],
                    ['uid' => 'p3', 'type' => 'penaly', 'amount' => 900],
                    ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900],
                ]), selectedProvider: $provider);
                $this->replayWpkSquidHands($this->wpkHuntRoundHands('doubling', 'p1', [
                    ['uid' => 'p1', 'type' => 'payout', 'amount' => 5400],
                    ['uid' => 'p2', 'type' => 'penaly', 'amount' => 1800],
                    ['uid' => 'p3', 'type' => 'penaly', 'amount' => 1800],
                    ['uid' => 'p4', 'type' => 'penaly', 'amount' => 1800],
                ]), requestAction: false, selectedProvider: $provider);
            } finally {
                $this->dropTemporaryTables();
            }
        });
    }

    public function test_start_accepts_player_names_up_to_128_characters(): void
    {
        $provider = new class extends BaseProvider
        {
            public ?GameVo $started = null;

            public function start(GameVo $game): void
            {
                $this->started = $game;
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
        $connection = new GameServerConnectionVo(11, $user, 'client-a', (new GameProviderManager)->getDefaultProvider());
        $server = $this->gameServerWithConnections($provider, [11 => $connection], $sender);
        $payload = [
            'game_key' => 'name-'.bin2hex(random_bytes(8)), 'network' => 'OK', 'ante' => 0,
            'small_blind' => 1, 'big_blind' => 2, 'button_seat_number' => 1,
            'players' => [
                ['uid' => 'hero', 'name' => str_repeat('玩', 128), 'seat' => 1, 'stack' => 100, 'hero' => true],
                ['uid' => 'villain', 'name' => 'Opponent', 'seat' => 2, 'stack' => 100, 'hero' => false],
            ],
        ];

        $server->handleStart(new GameServerMessageVo($connection, 'start-name-128', 'START', $payload, time() * 1000));

        self::assertNotNull($provider->started);
        $player = $provider->started->players->first();
        self::assertNotNull($player);
        self::assertSame(str_repeat('玩', 128), $player->name);

        $payload['players'][0]['name'] = str_repeat('玩', 129);
        try {
            $server->handleStart(new GameServerMessageVo($connection, 'start-name-129', 'START', $payload, time() * 1000));
            self::fail('Player names longer than 128 characters must be rejected');
        } catch (ValidationException) {
            self::assertNotNull($provider->started);
        }
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

    public function test_each_connection_uses_its_selected_provider_for_game_events_and_disconnect(): void
    {
        $defaultProvider = new class extends BaseProvider
        {
            /** @var list<GameServerConnectionVo> */
            public array $connected = [];

            /** @var list<GameServerConnectionVo> */
            public array $disconnected = [];

            /** @var list<GameVo> */
            public array $started = [];

            /** @var list<GameEventVo> */
            public array $posted = [];

            public function connect(GameServerConnectionVo $connection): void
            {
                $this->connected[] = $connection;
            }

            public function disconnect(GameServerConnectionVo $connection): void
            {
                $this->disconnected[] = $connection;
            }

            public function start(GameVo $game): void
            {
                $this->started[] = $game;
            }

            public function blindPosted(GameEventVo $event): void
            {
                $this->posted[] = $event;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $selectedProvider = clone $defaultProvider;
        $sender = new FakeGameServerSender;
        $server = $this->gameServerWithConnections($defaultProvider, [], $sender, additionalProviders: ['test_mode' => $selectedProvider]);
        $socketServer = new class
        {
            /** @var list<int> */
            public array $disconnected = [];

            public function disconnect(int $fd): void
            {
                $this->disconnected[] = $fd;
            }
        };
        $token = $this->token($this->user());
        $server->onOpen($socketServer, $this->webSocketRequest(11, $token));
        $server->onOpen($socketServer, $this->webSocketRequest(12, $token, provider: 'test_mode'));

        self::assertSame((new GameProviderManager)->getDefaultProvider(), $defaultProvider->connected[0]->provider);
        self::assertSame('test_mode', $selectedProvider->connected[0]->provider);
        self::assertSame(['ACCEPT', 'ACCEPT'], array_column($sender->messages, 'type'));

        foreach ([$defaultProvider->connected[0], $selectedProvider->connected[0]] as $connection) {
            $server->handleStart(new GameServerMessageVo($connection, 'start-'.$connection->fd, 'START', [
                'game_key' => 'mode-'.bin2hex(random_bytes(8)), 'network' => 'OK', 'ante' => 0,
                'small_blind' => 1, 'big_blind' => 2, 'button_seat_number' => 1,
                'players' => [
                    ['uid' => 'hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
                    ['uid' => 'villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
                ],
            ], time() * 1000));
        }

        self::assertCount(1, $defaultProvider->started);
        self::assertCount(1, $selectedProvider->started);
        self::assertSame($defaultProvider->connected[0]->clientId, $defaultProvider->started[0]->clientId);
        self::assertSame($selectedProvider->connected[0]->clientId, $selectedProvider->started[0]->clientId);

        foreach ([$defaultProvider, $selectedProvider] as $gameProvider) {
            $connection = $gameProvider->connected[0];
            $server->handleBlindPosted(new GameServerMessageVo($connection, 'blind-'.$connection->fd, 'BLIND_POSTED', [
                'game_uuid' => $gameProvider->started[0]->uuid, 'uid' => 'hero', 'type' => 'BB', 'amount' => 2,
            ], time() * 1000));
        }
        self::assertCount(1, $defaultProvider->posted);
        self::assertCount(1, $selectedProvider->posted);
        self::assertSame($defaultProvider->started[0]->uuid, $defaultProvider->posted[0]->game->uuid);
        self::assertSame($selectedProvider->started[0]->uuid, $selectedProvider->posted[0]->game->uuid);

        $server->onClose($socketServer, 11, 0);
        $server->onClose($socketServer, 12, 0);
        self::assertSame($defaultProvider->connected, $defaultProvider->disconnected);
        self::assertSame($selectedProvider->connected, $selectedProvider->disconnected);
        self::assertSame([], $socketServer->disconnected);
    }

    public function test_unknown_provider_disconnects_without_accepting_connection(): void
    {
        $provider = new class extends BaseProvider
        {
            public int $connections = 0;

            public function connect(GameServerConnectionVo $connection): void
            {
                $this->connections++;
            }

            public function requestAction(GameVo $game, Closure $callback): void {}
        };
        $sender = new FakeGameServerSender;
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

        $server->onOpen($socketServer, $this->webSocketRequest(11, $this->token($this->user()), provider: 'missing_test_mode'));

        self::assertSame([11], $socketServer->disconnected);
        self::assertSame([], $sender->messages);
        self::assertSame(0, $provider->connections);
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
            11 => new GameServerConnectionVo(11, $user, 'client-id', (new GameProviderManager)->getDefaultProvider()),
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

    /**
     * @param  list<array{uid:string,type:string,amount:int}>  $settlement
     * @return list<list<array{type:string,data:array<string,mixed>}>>
     */
    private function wpkHuntRoundHands(string $room, string $lastWinner, array $settlement): array
    {
        $players = [
            ['uid' => 'p1', 'seat' => 1, 'stack' => 20000, 'hero' => true],
            ['uid' => 'p2', 'seat' => 2, 'stack' => 20000, 'hero' => false],
            ['uid' => 'p3', 'seat' => 3, 'stack' => 20000, 'hero' => false],
            ['uid' => 'p4', 'seat' => 4, 'stack' => 20000, 'hero' => false],
        ];
        $hands = [];
        foreach ([0, 2] as $index => $played) {
            $hands[] = [
                ['type' => 'START', 'data' => [
                    'gameKey' => $room.'#'.($index + 1), 'network' => 'WPK_CLUB',
                    'ante' => 0, 'smallBlind' => 100, 'bigBlind' => 200, 'buttonSeatNumber' => 1,
                    'players' => $players, 'playerCounts' => ['p1' => $played, 'p2' => 0, 'p3' => 0, 'p4' => 0],
                    'gameType' => 'SQUID', 'squidMode' => 'HUNT', 'squidCost' => 300,
                    'squidNumber' => 3, 'squidRound' => 1, 'squidPlayed' => $played,
                ]],
                ['type' => 'BLIND_POSTED', 'data' => ['uid' => 'p2', 'type' => 'SB', 'amount' => 100]],
                ['type' => 'BLIND_POSTED', 'data' => ['uid' => 'p3', 'type' => 'BB', 'amount' => 200]],
                ['type' => 'STAGE', 'data' => ['stage' => 'PREFLOP', 'cards' => []]],
                ['type' => 'DEALT', 'data' => ['cards' => ['As', 'Kd']]],
                ['type' => 'ACTION', 'data' => ['uid' => 'p4', 'action' => 'CALL', 'amount' => 200]],
                ['type' => 'ACTION', 'data' => ['uid' => 'p1', 'action' => 'CALL', 'amount' => 200]],
                ['type' => 'GOT_SQUID', 'data' => ['uid' => $index === 0 ? 'p1' : $lastWinner,
                    'count' => $index === 0 ? 2 : 1]],
                ['type' => 'OVER', 'data' => [
                    'winners' => [['uid' => $index === 0 ? 'p1' : $lastWinner, 'amount' => 300]],
                    ...($index === 1 ? ['squid' => $settlement] : []),
                ]],
            ];
        }

        return $hands;
    }

    /**
     * @param  list<list<array{type:string,data:array<string,mixed>}>>  $hands
     * @return list<array<string,mixed>>
     */
    private function replayWpkSquidHands(
        array $hands,
        bool $requestAction = true,
        ?string $httpUrl = null,
        ?BaseProvider $selectedProvider = null,
    ): array {
        $client = new class implements ClientInterface
        {
            /** @var list<array<string,mixed>> */
            public array $commands = [];

            /** @param array<string,mixed> $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param array<string,list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['token'])) {
                    return new RawResponse(200, [], '{"result":true,"sessionId":"wpk-replay"}', $version);
                }
                $this->commands[] = $body;
                $reply = $body['structType'] === 'getAnswer'
                    ? ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'check']
                    : ['result' => true];

                return new RawResponse(200, [], json_encode($reply, JSON_THROW_ON_ERROR), $version);
            }
        };
        $provider = $selectedProvider ?? new ProtoHttpProvider([
            'url' => $httpUrl ?? 'https://proto.example', 'token' => 'test-token', 'player_id' => 'wpk-replay',
            'network' => 'WE',
        ], $httpUrl === null
            ? static fn (string $host, int $port, bool $ssl): ClientInterface => $client
            : static fn (string $host, int $port, bool $ssl): ClientInterface => new ProtoHttpLoopbackClient($host, $port, $ssl),
            new FakeProtoHttpRedis);
        $user = $this->user('wpk-'.bin2hex(random_bytes(4)).'@example.test');
        $providerName = $selectedProvider instanceof ProtoProvider ? 'proto' : 'proto_http';
        $connection = new GameServerConnectionVo(11, $user, 'wpk-replay-'.bin2hex(random_bytes(4)), $providerName);
        $connection->ready = true;
        $sender = new FakeGameServerSender;
        $server = $this->gameServerWithConnections(new IdelProvider, [11 => $connection], $sender,
            additionalProviders: [$providerName => $provider]);
        $socketServer = new class {};
        $index = 0;
        $roomSuffix = '-'.bin2hex(random_bytes(4));
        $timestamp = (int) floor(microtime(true) * 1000);
        $send = static function (string $type, array $payload) use ($server, $socketServer, $sender, &$index, $timestamp): array {
            $frame = new Frame;
            $frame->fd = 11;
            $frame->data = json_encode([
                'id' => 'replay-'.$index, 'type' => $type, 'timestamp' => $timestamp + $index++, 'payload' => $payload,
            ], JSON_THROW_ON_ERROR);
            $server->onMessage($socketServer, $frame);
            $reply = end($sender->messages);
            self::assertIsArray($reply);
            self::assertSame($type.'.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));

            return $reply['payload'] ?? [];
        };
        if ($selectedProvider instanceof ProtoProvider) {
            $provider->connect($connection);
        }
        try {
            foreach ($hands as $hand) {
                $uuid = null;
                $heroUid = null;
                $requestedAction = false;
                foreach ($hand as $event) {
                    $type = $event['type'];
                    $data = $event['data'];
                    if ($type === 'START') {
                        $counts = $data['playerCounts'];
                        $players = $data['players'];
                        $heroUid = current(array_column(array_filter($players,
                            static fn (array $player): bool => $player['hero']), 'uid'));
                        $reply = $send('START', [
                            'game_key' => str_replace('#', $roomSuffix.'#', $data['gameKey']),
                            'network' => $data['network'],
                            'ante' => $data['ante'], 'small_blind' => $data['smallBlind'],
                            'big_blind' => $data['bigBlind'], 'button_seat_number' => $data['buttonSeatNumber'],
                            'players' => $players, 'gameType' => $data['gameType'], 'squidMode' => $data['squidMode'],
                            'squidCost' => $data['squidCost'], 'squidNumber' => $data['squidNumber'],
                            'squidRound' => $data['squidRound'], 'squidPlayed' => $data['squidPlayed'],
                        ]);
                        $uuid = $reply['game_uuid'];
                        foreach ($players as $player) {
                            $send('PLAYER_HAS_SQUID', [
                                'game_uuid' => $uuid, 'uid' => $player['uid'], 'count' => $counts[$player['uid']] ?? 0,
                            ]);
                        }

                        continue;
                    }
                    self::assertNotNull($uuid);
                    if ($type === 'ACTION' && $data['uid'] === $heroUid && $requestAction && ! $requestedAction) {
                        $send('REQUEST_ACTION', ['game_uuid' => $uuid]);
                        $requestedAction = true;
                    }
                    $send($type, ['game_uuid' => $uuid, ...$data]);
                }
            }
        } finally {
            if ($selectedProvider instanceof ProtoProvider) {
                $provider->disconnect($connection);
            }
        }

        return $client->commands;
    }

    /**
     * @param  array<int, GameServerConnectionVo>  $connections
     * @param  array<string, BaseProvider>  $additionalProviders
     */
    private function gameServerWithConnections(
        BaseProvider $provider,
        array $connections,
        ?Sender $sender = null,
        ?GameService $gameService = null,
        ?UserGameConfigService $configs = null,
        array $additionalProviders = [],
    ): GameServer {
        $container = ApplicationContext::getContainer();
        $manager = new GameProviderManager;
        $manager->extend($manager->getDefaultProvider(), static fn (): ProviderInterface => $provider);
        foreach ($additionalProviders as $name => $additionalProvider) {
            $manager->extend($name, static fn (): ProviderInterface => $additionalProvider);
        }
        $server = new GameServer(
            $sender ?? $container->get(Sender::class),
            $manager,
            $container->get(UserTokenService::class),
            $gameService ?? $container->get(GameService::class),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
            $container->get(Encrypter::class),
            $configs ?? $container->get(UserGameConfigService::class),
        );
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, $connections);

        return $server;
    }

    private function webSocketRequest(int $fd, string $token, ?string $clientId = null, ?string $provider = null): SwooleRequest
    {
        $request = new SwooleRequest;
        $request->fd = $fd;
        $request->get = ['token' => $token];
        if ($clientId !== null) {
            $request->get['client_id'] = $clientId;
        }
        if ($provider !== null) {
            $request->get['provider'] = $provider;
        }

        return $request;
    }
}
