<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Enum\NetworkEnum;
use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\MockProvider;
use App\Model\User;
use App\Model\UserToken;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use Closure;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Cache\Cache;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Swoole\Event;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeGameServerSender;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameServerTest extends DatabaseTestCase
{
    private const DATABASE_TEST_METHODS = [
        'test_valid_token_receives_accept_without_sending_ping',
        'test_missing_or_invalid_token_never_receives_accept',
        'test_uppercase_request_action_returns_uppercase_ack',
        'test_each_message_uses_its_validated_payload_and_success_or_error_reply',
    ];

    protected function setUp(): void
    {
        if (in_array($this->name(), self::DATABASE_TEST_METHODS, true)) {
            parent::setUp();
        }
    }

    protected function tearDown(): void
    {
        if (in_array($this->name(), self::DATABASE_TEST_METHODS, true)) {
            parent::tearDown();
        }
    }

    public function test_valid_token_receives_accept_without_sending_ping(): void
    {
        [$gameServer, $sender] = $this->newServer();
        $user = $this->user();
        $request = $this->requestWithToken(1, $this->token($user));

        $gameServer->onOpen($sender, $request);

        self::assertCount(1, $sender->messages);
        $accept = $sender->messages[0];
        self::assertIsString($accept['id']);
        self::assertNotSame('', $accept['id']);
        self::assertSame('ACCEPT', $accept['type']);
        self::assertIsInt($accept['timestamp']);
        self::assertNull($accept['reply_to']);
        self::assertSame([], $accept['payload']);
        self::assertSame([], $sender->disconnected);
    }

    public function test_missing_or_invalid_token_never_receives_accept(): void
    {
        [$gameServer, $sender] = $this->newServer();
        $this->user();
        $gameServer->onOpen($sender, $this->requestWithToken(1));
        $gameServer->onOpen($sender, $this->requestWithToken(2, 'invalid'));

        self::assertSame([1, 2], $sender->disconnected);
        self::assertSame([], $sender->messages);
    }

    /** @return array{GameServer, FakeGameServerSender} */
    private function newServer(): array
    {
        $sender = new FakeGameServerSender;
        $providers = new GameProviderManager;
        $container = ApplicationContext::getContainer();
        $gameServer = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, new FakeProtoHttpRedis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );

        return [$gameServer, $sender];
    }

    private function requestWithToken(int $fd, ?string $token = null): Request
    {
        $request = new Request;
        $request->fd = $fd;
        $request->get = $token === null ? [] : ['token' => $token];

        return $request;
    }

    public function test_uppercase_request_action_returns_uppercase_ack(): void
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
        $redis = new FakeProtoHttpRedis;
        $game = new GameVo(1, '12345678-90ab-4cde-8f01-23456789abcd', NetworkEnum::WE, 'room#1', 100, 50, 0, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 60, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1);
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));

        $providers = new GameProviderManager;
        $providers->extend('proto', static fn (): MockProvider => new MockProvider(1));
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, $redis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        $user = new User(['language' => 'zh-CN']);
        $token = new UserToken;
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, $user, $token),
        ]);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = json_encode([
            'id' => 'client-1',
            'type' => 'REQUEST_ACTION',
            'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['game_uuid' => $game->uuid],
        ], JSON_THROW_ON_ERROR);
        $server->onMessage(null, $frame);
        Event::wait();

        self::assertCount(1, $sender->messages);
        self::assertSame('REQUEST_ACTION.ACK', $sender->messages[0]['type'], json_encode($sender->messages[0], JSON_THROW_ON_ERROR));
        self::assertSame('client-1', $sender->messages[0]['reply_to']);
        self::assertSame([
            'game_uuid' => $game->uuid,
            'action' => 'ALL_IN',
            'amount' => 10,
        ], $sender->messages[0]['payload']);
    }

    public function test_each_message_uses_its_validated_payload_and_success_or_error_reply(): void
    {
        $container = ApplicationContext::getContainer();
        if (! $container instanceof Container) {
            throw new \LogicException('Hyperf container required');
        }
        $originalDriverFactory = $container->get(DriverFactory::class);
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('push')->andReturn(true);
        $driverFactory = Mockery::mock(DriverFactory::class);
        $driverFactory->shouldReceive('get')->with('default')->andReturn($driver);
        $container->set(DriverFactory::class, $driverFactory);

        try {
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
            $redis = new FakeProtoHttpRedis;
            $providers = new GameProviderManager;
            $providers->extend($providers->getDefaultProvider(), static fn (): MockProvider => new MockProvider(1));
            $server = new GameServer(
                $sender,
                $providers,
                new UserTokenService,
                new GameService($providers, $redis),
                $container->get(InsuranceService::class),
                $container->get(ValidatorFactoryInterface::class),
                $container->get(LoggerInterface::class),
            );
            $user = new User(['id' => 1, 'language' => 'zh-CN']);
            (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
                1 => new GameServerConnectionVo(1, $user, new UserToken),
            ]);

            $number = 0;
            $send = static function (string $type, array $payload = []) use ($server, $sender, &$number): array {
                $number++;
                $id = 'client-'.$number;
                $before = count($sender->messages);
                $frame = new Frame;
                $frame->fd = 1;
                $frame->data = json_encode([
                    'id' => $id,
                    'type' => $type,
                    'timestamp' => (int) floor(microtime(true) * 1000),
                    'payload' => $payload,
                ], JSON_THROW_ON_ERROR);
                $server->onMessage(null, $frame);
                if ($type === 'REQUEST_ACTION' && isset($payload['game_uuid'])) {
                    Event::wait();
                }
                self::assertCount($before + 1, $sender->messages);
                $reply = $sender->messages[$before];
                self::assertSame($id, $reply['reply_to']);

                return $reply;
            };
            $start = [
                'game_key' => 'table-42#1', 'network' => 'WE',
                'ante' => 0, 'big_blind' => 100, 'small_blind' => 50,
                'button_seat_number' => 1,
                'players' => [
                    ['seat' => 1, 'uid' => 'Aa12345678901234', 'name' => 'Hero', 'stack' => 1000, 'hero' => true],
                    ['seat' => 10, 'uid' => 'Bb2002', 'name' => 'Villain', 'stack' => 1000, 'hero' => false],
                ],
            ];

            $invalidStart = $send('START', ['network' => 'WE']);
            self::assertSame('event_invalid', $invalidStart['payload']['code'], json_encode($invalidStart, JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('game_key', $invalidStart['payload']['details']);
            $tooLongKey = $send('START', [...$start, 'game_key' => str_repeat('x', 33)]);
            self::assertSame('event_invalid', $tooLongKey['payload']['code']);
            self::assertArrayHasKey('game_key', $tooLongKey['payload']['details']);
            $spacedKey = $send('START', [...$start, 'game_key' => 'table-42#1 ']);
            self::assertSame('error', $spacedKey['type']);
            self::assertSame('event_invalid', $spacedKey['payload']['code']);
            self::assertArrayHasKey('game_key', $spacedKey['payload']['details']);
            foreach (['hero-1', '12345678901234567', 123] as $invalidUid) {
                $reply = $send('START', [...$start, 'players' => [
                    [...$start['players'][0], 'uid' => $invalidUid],
                    $start['players'][1],
                ]]);
                self::assertSame('error', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
                self::assertSame('event_invalid', $reply['payload']['code'], json_encode($reply, JSON_THROW_ON_ERROR));
                self::assertArrayHasKey('players.0.uid', $reply['payload']['details']);
            }
            $longName = $send('START', [...$start, 'players' => [
                [...$start['players'][0], 'name' => str_repeat('N', 17)],
                $start['players'][1],
            ]]);
            self::assertSame('error', $longName['type'], json_encode($longName, JSON_THROW_ON_ERROR));
            self::assertSame('event_invalid', $longName['payload']['code'], json_encode($longName, JSON_THROW_ON_ERROR));
            self::assertArrayHasKey('players.0.name', $longName['payload']['details']);
            $duplicateUid = $send('START', [...$start, 'players' => [
                $start['players'][0],
                [...$start['players'][1], 'uid' => 'aA12345678901234'],
            ]]);
            self::assertSame('event_invalid', $duplicateUid['payload']['code']);
            self::assertArrayHasKey('players.1.uid', $duplicateUid['payload']['details']);
            $onePlayer = $send('START', [...$start, 'players' => [$start['players'][0]]]);
            self::assertSame('event_invalid', $onePlayer['payload']['code'], json_encode($onePlayer, JSON_THROW_ON_ERROR));
            $noHero = $send('START', [...$start, 'players' => [
                [...$start['players'][0], 'hero' => false],
                $start['players'][1],
            ]]);
            self::assertSame('hero_not_found', $noHero['payload']['code'], json_encode($noHero, JSON_THROW_ON_ERROR));
            $started = $send('START', $start);
            self::assertSame('START.ACK', $started['type']);
            $gameUuid = $started['payload']['game_uuid'];
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $gameUuid);
            self::assertSame('game_already_exists', $send('START', $start)['payload']['code']);
            $providers->extend($providers->getDefaultProvider(), static fn (): BaseProvider => new class extends BaseProvider
            {
                public function start(GameVo $game): void
                {
                    throw new RuntimeException('Provider start failed');
                }

                public function requestAction(GameVo $game, Closure $callback): void {}
            });
            $failedStart = $send('START', [...$start, 'game_key' => 'retry-after-error']);
            self::assertSame('server_error', $failedStart['payload']['code']);
            $providers->extend($providers->getDefaultProvider(), static fn (): MockProvider => new MockProvider(1));
            self::assertSame('START.ACK', $send('START', [...$start, 'game_key' => 'retry-after-error'])['type']);

            self::assertSame('event_invalid', $send('STAGE', [
                'game_uuid' => $gameUuid, 'stage' => 'FLOP', 'cards' => ['As', 'Kh'],
            ])['payload']['code']);
            $stage = $send('STAGE', [
                'game_uuid' => $gameUuid, 'stage' => 'PREFLOP', 'cards' => [],
            ]);
            self::assertSame('STAGE.ACK', $stage['type'], json_encode($stage, JSON_THROW_ON_ERROR));
            self::assertSame([], $stage['payload']);
            foreach ([
                ['stage' => 'FLOP', 'cards' => ['2c', '3d', '4h']],
                ['stage' => 'TURN', 'cards' => ['5s']],
                ['stage' => 'RIVER', 'cards' => ['6c']],
            ] as $street) {
                $streetReply = $send('STAGE', ['game_uuid' => $gameUuid, ...$street]);
                self::assertSame('STAGE.ACK', $streetReply['type']);
                self::assertSame([], $streetReply['payload']);
            }
            self::assertSame('event_invalid', $send('DEALT', [
                'game_uuid' => $gameUuid, 'cards' => ['As'],
            ])['payload']['code']);
            $dealt = $send('DEALT', [
                'game_uuid' => $gameUuid, 'cards' => ['As', 'Kh'],
            ]);
            self::assertSame('DEALT.ACK', $dealt['type']);
            self::assertSame([], $dealt['payload']);
            $invalidActionUid = $send('ACTION', [
                'game_uuid' => $gameUuid, 'uid' => 'villain-1', 'action' => 'CHECK', 'amount' => 0,
            ]);
            self::assertSame('event_invalid', $invalidActionUid['payload']['code']);
            self::assertArrayHasKey('uid', $invalidActionUid['payload']['details']);
            self::assertSame('event_invalid', $send('ACTION', [
                'game_uuid' => $gameUuid, 'uid' => 'Bb2002', 'action' => 'CHECK', 'amount' => '0',
            ])['payload']['code']);
            $acted = $send('ACTION', [
                'game_uuid' => $gameUuid, 'uid' => 'bB2002', 'action' => 'CHECK', 'amount' => 0,
            ]);
            self::assertSame('ACTION.ACK', $acted['type']);
            self::assertSame([], $acted['payload']);
            $invalidShowUid = $send('SHOW', [
                'game_uuid' => $gameUuid, 'uid' => 'villain-1', 'cards' => ['Qs', 'Qh'],
            ]);
            self::assertSame('event_invalid', $invalidShowUid['payload']['code']);
            self::assertArrayHasKey('uid', $invalidShowUid['payload']['details']);
            self::assertSame('event_invalid', $send('SHOW', [
                'game_uuid' => $gameUuid, 'uid' => 'Bb2002', 'cards' => ['Qs'],
            ])['payload']['code']);
            $shown = $send('SHOW', [
                'game_uuid' => $gameUuid, 'uid' => 'bB2002', 'cards' => ['Qs', 'Qh'],
            ]);
            self::assertSame('SHOW.ACK', $shown['type']);
            self::assertSame([], $shown['payload']);
            self::assertSame('event_invalid', $send('REQUEST_ACTION')['payload']['code']);
            $action = $send('REQUEST_ACTION', ['game_uuid' => $gameUuid]);
            self::assertSame('REQUEST_ACTION.ACK', $action['type']);
            self::assertSame(['game_uuid' => $gameUuid, 'action' => 'CHECK', 'amount' => 0], $action['payload']);
            $providers->extend($providers->getDefaultProvider(), static fn (): MockProvider => new MockProvider(1, true));
            $failedAction = $send('REQUEST_ACTION', ['game_uuid' => $gameUuid]);
            self::assertSame('error', $failedAction['type']);
            self::assertSame('provider_failed', $failedAction['payload']['code']);
            self::assertSame('event_invalid', $send('OVER', ['game_uuid' => $gameUuid])['payload']['code']);
            $over = $send('OVER', [
                'game_uuid' => $gameUuid, 'winners' => [['uid' => 'aA12345678901234', 'amount' => 200]],
                'shown' => [['uid' => 'aA12345678901234', 'cards' => ['As', 'Kh']]],
            ]);
            self::assertSame('OVER.ACK', $over['type']);
            self::assertSame([], $over['payload']);
            foreach ([
                ['winners' => [['uid' => 'hero-1', 'amount' => 200]]],
                ['shown' => [['uid' => 'hero-1', 'cards' => ['As', 'Kh']]]],
            ] as $invalidResult) {
                $invalidOverUid = $send('OVER', [
                    'game_uuid' => $gameUuid,
                    'winners' => [['uid' => 'Aa12345678901234', 'amount' => 200]],
                    ...$invalidResult,
                ]);
                self::assertSame('event_invalid', $invalidOverUid['payload']['code']);
            }
            self::assertSame('status_invalid', $send('ACTION', [
                'game_uuid' => $gameUuid, 'uid' => 'Aa12345678901234', 'action' => 'CHECK', 'amount' => 0,
            ])['payload']['code']);
            $secondGame = $send('START', [...$start, 'game_key' => 'table-43#1'])['payload']['game_uuid'];
            self::assertSame('event_invalid', $send('ABORT')['payload']['code']);
            $abort = $send('ABORT', ['game_uuid' => $secondGame]);
            self::assertSame('ABORT.ACK', $abort['type'], json_encode($abort, JSON_THROW_ON_ERROR));
            self::assertSame([], $abort['payload']);
            self::assertSame('event_type_invalid', $send('request_action')['payload']['code']);
        } finally {
            $container->set(DriverFactory::class, $originalDriverFactory);
            Mockery::close();
        }
    }

    public function test_returns_fractional_premium_in_a_correlated_ack(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_outs_2' => InsuranceService::RATIO_2],
            $this->quote(['pot' => 299, 'odds' => 16.25]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame('insurance-1', $reply['reply_to']);
        self::assertSame(['amount' => 9], $reply['payload']);
    }

    public function test_uses_quoted_breakeven_amount(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_1],
            $this->quote(['breakeven' => 7]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 7], $reply['payload']);
    }

    public function test_accepts_numeric_string_odds_from_a_quote(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_2],
            $this->quote(['odds' => '16.25']),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 9], $reply['payload']);
    }

    public function test_uses_the_minimum_without_an_insurance_configuration(): void
    {
        $reply = $this->requestInsurance(['unrelated' => 'value'], $this->quote(['min' => 3]));

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 3], $reply['payload']);
    }

    public function test_min_returns_zero_as_a_purchase_amount(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MIN],
            $this->quote(['min' => 0]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type']);
        self::assertSame(['amount' => 0], $reply['payload']);
    }

    public function test_invalid_quote_bounds_return_null_as_no_valid_decision(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MIN],
            $this->quote(['min' => 3, 'max' => 2]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type']);
        self::assertSame(['amount' => null], $reply['payload']);
    }

    public function test_rejects_an_invalid_quote_with_field_details(): void
    {
        $quote = $this->quote();
        unset($quote['breakeven']);

        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MAX],
            $quote,
        );

        self::assertSame('error', $reply['type']);
        self::assertSame('insurance-1', $reply['reply_to']);
        self::assertSame('event_invalid', $reply['payload']['code']);
        self::assertArrayHasKey('breakeven', $reply['payload']['details']);
    }

    /** @param array<string, int|float|string> $overrides
     * @return array<string, int|float|string>
     */
    private function quote(array $overrides = []): array
    {
        return array_replace([
            'game_uuid' => '11111111-1111-4111-8111-000000000001',
            'stage' => 'FLOP',
            'outs' => 2,
            'pot' => 299,
            'odds' => 16.25,
            'min' => 0,
            'max' => 18,
            'breakeven' => 9,
        ], $overrides);
    }

    /** @param array<string, string> $config
     * @param  array<string, int|float|string>  $quote
     * @return array<string, mixed>
     */
    private function requestInsurance(array $config, array $quote): array
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
        $cache = new class($config) extends Cache
        {
            /** @param array<string, string> $config */
            public function __construct(private readonly array $config) {}

            public function get($key, $default = null): mixed
            {
                return $this->config;
            }
        };
        $game = GameVoFixture::headsUp();
        $redis = new FakeProtoHttpRedis;
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers = new GameProviderManager;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, $redis),
            new InsuranceService(new UserGameConfigService($cache)),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        $user = new User(['id' => $game->userId, 'language' => 'zh-CN']);
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, $user, new UserToken),
        ]);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = json_encode([
            'id' => 'insurance-1',
            'type' => GameServer::TYPE_REQUEST_INSURANCE,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => $quote,
        ], JSON_THROW_ON_ERROR);
        $server->onMessage(null, $frame);

        self::assertCount(1, $sender->messages);

        return $sender->messages[0];
    }
}
