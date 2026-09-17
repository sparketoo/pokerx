<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Exception\PokerException;
use App\Game\PokerManager;
use App\Game\PokerServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\ProviderInterface;
use App\Model\Game;
use App\Model\UserToken;
use App\Service\CreditService;
use App\Service\GameService;
use App\Service\UserTokenService;
use App\Vo\Game\PokerServerMessageVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\NullLogger;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Tests\Support\TestData;

use function App\Support\di;
use function Tests\run;

final class SenderSpy extends Sender
{
    /** @var list<array{fd: int, message: array<string, mixed>}> */
    public array $messages = [];

    public function __construct() {}

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'push') {
            if (! is_int($arguments[0] ?? null) || ! is_string($arguments[1] ?? null)) {
                throw new RuntimeException('Invalid WebSocket response.');
            }
            $this->messages[] = [
                'fd' => $arguments[0],
                'message' => json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR),
            ];

            return true;
        }

        return null;
    }
}

function websocketOpenRequest(int $fd, string $token): Request
{
    $request = new Request;
    $request->fd = $fd;
    $request->get = ['token' => $token];

    return $request;
}

function websocketFrame(int $fd, string $data): Frame
{
    $frame = new Frame;
    $frame->fd = $fd;
    $frame->data = $data;

    return $frame;
}

afterEach(function (): void {
    Mockery::close();
});

it('dispatches hand_start, persists it and acknowledges the client', function (): void {
    run(function (): void {
        $calls = [];
        $provider = new class($calls) implements ProviderInterface
        {
            /** @param array<int, string> $calls */
            public function __construct(public array &$calls) {}

            public function start(Game $game): void
            {
                $this->calls[] = 'start';
            }

            public function stage(Game $game): void
            {
                $this->calls[] = 'stage';
            }

            public function playerActed(Game $game): void
            {
                $this->calls[] = 'acted';
            }

            public function knownPlayerCards(Game $game): void
            {
                $this->calls[] = 'cards';
            }

            public function requestAction(Game $game, Closure $callback): void
            {
                $this->calls[] = 'request';
            }

            public function over(Game $game, ?Closure $onError = null): void
            {
                $this->calls[] = 'over';
            }
        };
        $manager = new PokerManager;
        $manager->extend($manager->getDefaultProvider(), fn (): ProviderInterface => $provider);
        $sender = new SenderSpy;
        $server = new PokerServer(
            $sender,
            $manager,
            new UserTokenService,
            new GameService(new CreditService, $manager),
            di(ValidatorFactoryInterface::class),
            new NullLogger,
        );
        $user = TestData::user();
        $token = TestData::token($user);
        $swoole = Mockery::mock();
        $swoole->shouldReceive('disconnect')->never();
        $server->onOpen($swoole, websocketOpenRequest(91, $token));
        $id = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(91, json_encode([
            'id' => $id, 'type' => GameEvent::HAND_START, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => [
                'room_number' => 'ws-room', 'hand_number' => 1, 'provider' => 'client-supplied-provider', 'network' => 'WE', 'players' => [
                    ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
                    ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        expect($calls)->toBe([])
            ->and($sender->messages)->toHaveCount(1)
            ->and($sender->messages[0]['fd'])->toBe(91)
            ->and($sender->messages[0]['message']['type'])->toBe('hand_start.ack')
            ->and($sender->messages[0]['message']['reply_to'])->toBe($id)
            ->and(Game::query()->where('room_number', 'ws-room')->where('user_id', $user->id)->count())->toBe(1)
            ->and(Game::query()->where('room_number', 'ws-room')->where('user_id', $user->id)->value('provider'))->toBe($manager->getDefaultProvider());

        /** @var Game $game */
        $game = Game::query()->where('room_number', 'ws-room')->where('user_id', $user->id)->firstOrFail();
        $forceBetId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(91, json_encode([
            'id' => $forceBetId, 'type' => GameEvent::FORCE_BET, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $game->uuid, 'ante' => 5, 'small_blind' => 50, 'big_blind' => 100],
        ], JSON_THROW_ON_ERROR)));
        $handCardId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(91, json_encode([
            'id' => $handCardId, 'type' => GameEvent::HAND_CARD, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $game->uuid, 'cards' => ['As', 'Qd']],
        ], JSON_THROW_ON_ERROR)));
        $stageId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(91, json_encode([
            'id' => $stageId, 'type' => GameEvent::STAGE_START, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $game->uuid, 'stage' => 'preflop', 'cards' => []],
        ], JSON_THROW_ON_ERROR)));

        $game->refresh()->load('players');
        expect($calls)->toBe(['start', 'stage'])
            ->and($sender->messages)->toHaveCount(4)
            ->and($sender->messages[1]['message']['type'])->toBe('force_bet.ack')
            ->and($sender->messages[1]['message']['reply_to'])->toBe($forceBetId)
            ->and($sender->messages[2]['message']['type'])->toBe('hand_card.ack')
            ->and($sender->messages[2]['message']['reply_to'])->toBe($handCardId)
            ->and($sender->messages[3]['message']['type'])->toBe('stage_start.ack')
            ->and($sender->messages[3]['message']['reply_to'])->toBe($stageId)
            ->and($game->big_blind)->toBe(100)
            ->and($game->small_blind)->toBe(50)
            ->and($game->ante)->toBe(5)
            ->and($game->pot)->toBe(160)
            ->and($game->bet_amount)->toBe(55)
            ->and($game->hero()->cards)->toBe(['As', 'Qd'])
            ->and($game->events()->count())->toBe(4);

        $invalidEvents = [
            [GameEvent::HAND_START, ['room_number' => 'fractional', 'hand_number' => 1, 'network' => 'OK', 'players' => [['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1.25, 'seat_type' => 'SB']]]],
            [GameEvent::FORCE_BET, ['hand_uuid' => $game->uuid, 'small_blind' => 0.5, 'big_blind' => 1]],
            [GameEvent::FORCE_BET, ['hand_uuid' => $game->uuid, 'extra_bets' => [['name' => 'Hero', 'type' => 'post', 'amount' => 0.02]]]],
            [GameEvent::PLAYER_ACTED, ['hand_uuid' => $game->uuid, 'name' => 'Hero', 'action' => 'raise', 'amount' => 1.25]],
            [GameEvent::PLAYER_ACTED, ['hand_uuid' => $game->uuid, 'name' => 'Hero', 'action' => 'raise', 'amount' => '2']],
            [GameEvent::HAND_OVER, ['hand_uuid' => $game->uuid, 'winners' => [['name' => 'Hero', 'amount' => 2.5]]]],
            [GameEvent::FORCE_BET, ['hand_uuid' => $game->uuid, 'small_blind' => 50, 'big_blind' => 100]],
            [GameEvent::HAND_CARD, ['hand_uuid' => $game->uuid, 'cards' => ['As', 'Qd']]],
            [GameEvent::STAGE_START, ['hand_uuid' => $game->uuid, 'stage' => 'invalid', 'cards' => []]],
            [GameEvent::PLAYER_ACTED, ['hand_uuid' => $game->uuid, 'name' => 'Hero', 'action' => 'raise']],
            [GameEvent::KNOWN_PLAY_CARDS, ['hand_uuid' => $game->uuid, 'name' => 'Hero', 'cards' => ['As']]],
            [GameEvent::REQUEST_ACTION, ['hand_uuid' => 'not-a-uuid']],
            [GameEvent::HAND_OVER, ['hand_uuid' => $game->uuid, 'winners' => [['name' => 'Hero']]]],
        ];
        foreach ([[], [['name' => 'Hero', 'amount' => 1], ['name' => 'Hero', 'amount' => 2]], [['name' => 'Hero', 'amount' => -1]], [['name' => 'Hero', 'amount' => '2']], [['name' => 'Hero', 'amount' => 9007199254740992]], ['named' => ['name' => 'Hero', 'amount' => 1]]] as $winners) {
            $invalidEvents[] = [GameEvent::HAND_OVER, ['hand_uuid' => $game->uuid, 'winners' => $winners]];
        }
        foreach ($invalidEvents as [$type, $payload]) {
            $server->onMessage($swoole, websocketFrame(91, json_encode([
                'id' => (string) Str::uuid(), 'type' => $type, 'timestamp' => (int) floor(microtime(true) * 1000),
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR)));
        }

        expect($calls)->toBe(['start', 'stage'])
            ->and($sender->messages)->toHaveCount(4 + count($invalidEvents))
            ->and($game->events()->count())->toBe(4);
        foreach (array_slice($sender->messages, 4) as $response) {
            expect($response['message']['type'])->toBe('error')
                ->and($response['message']['payload']['code'])->toBe('event_invalid');
        }
    });
});

it('returns the provider action in the request_action acknowledgement', function (): void {
    run(function (): void {
        $provider = new class implements ProviderInterface
        {
            /** @var list<string> */
            public array $calls = [];

            public ?Closure $settlementError = null;

            public function start(Game $game): void
            {
                $this->calls[] = 'start';
            }

            public function stage(Game $game): void
            {
                $this->calls[] = 'stage';
            }

            public function playerActed(Game $game): void
            {
                $this->calls[] = 'acted';
            }

            public function knownPlayerCards(Game $game): void
            {
                $this->calls[] = 'cards';
            }

            public function requestAction(Game $game, Closure $callback): void
            {
                $this->calls[] = 'request';
                $callback(RequestActionResultVo::success(ActionEnum::ALL_IN, 900));
            }

            public function over(Game $game, ?Closure $onError = null): void
            {
                $this->calls[] = 'over';
                $this->settlementError = $onError;
            }
        };
        $manager = new PokerManager;
        $manager->extend($manager->getDefaultProvider(), fn (): ProviderInterface => $provider);
        $sender = new SenderSpy;
        $server = new PokerServer($sender, $manager, new UserTokenService, new GameService(new CreditService, $manager), di(ValidatorFactoryInterface::class), new NullLogger);
        $user = TestData::user();
        $token = TestData::token($user);
        $swoole = Mockery::mock();
        $swoole->shouldReceive('disconnect')->never();
        $server->onOpen($swoole, websocketOpenRequest(92, $token));

        $startId = (string) Str::uuid();
        $start = ['id' => $startId, 'type' => GameEvent::HAND_START, 'timestamp' => (int) floor(microtime(true) * 1000), 'payload' => [
            'room_number' => 'ws-action-room', 'hand_number' => 1, 'network' => 'WE',
            'players' => [
                ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
                ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
            ],
        ]];
        $server->onMessage($swoole, websocketFrame(92, json_encode($start, JSON_THROW_ON_ERROR)));
        $uuid = $sender->messages[0]['message']['payload']['hand_uuid'];
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => (string) Str::uuid(), 'type' => GameEvent::FORCE_BET, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $uuid, 'small_blind' => 50, 'big_blind' => 100],
        ], JSON_THROW_ON_ERROR)));
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => (string) Str::uuid(), 'type' => GameEvent::HAND_CARD, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $uuid, 'cards' => ['As', 'Qd']],
        ], JSON_THROW_ON_ERROR)));
        $actedId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $actedId, 'type' => GameEvent::PLAYER_ACTED, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $uuid, 'name' => 'Hero', 'action' => 'raise', 'amount' => 100],
        ], JSON_THROW_ON_ERROR)));
        $requestId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $requestId, 'type' => GameEvent::REQUEST_ACTION, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['hand_uuid' => $uuid],
        ], JSON_THROW_ON_ERROR)));
        $overId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $overId, 'type' => GameEvent::HAND_OVER, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => [
                'hand_uuid' => $uuid,
                'winners' => [['name' => 'Villain', 'amount' => 546], ['name' => 'Hero', 'amount' => 547]],
                'shown' => [['name' => 'Hero', 'cards' => ['As', 'Qd']]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $game = Game::query()->where('uuid', $uuid)->firstOrFail();

        expect($provider->calls)->toBe(['start', 'acted', 'request', 'over'])
            ->and($sender->messages[3]['message']['type'])->toBe('player_acted.ack')
            ->and($sender->messages[3]['message']['reply_to'])->toBe($actedId)
            ->and($sender->messages)->toHaveCount(6)
            ->and($sender->messages[4]['message']['type'])->toBe('request_action.ack')
            ->and($sender->messages[4]['message']['reply_to'])->toBe($requestId)
            ->and($sender->messages[4]['message']['payload'])->toMatchArray(['hand_uuid' => $uuid, 'action' => 'all-in', 'amount' => 900])
            ->and($sender->messages[5]['message']['type'])->toBe('hand_over.ack')
            ->and($sender->messages[5]['message']['reply_to'])->toBe($overId)
            ->and($game->pot)->toBe(250)
            ->and($game->bet_amount)->toBe(150)
            ->and($game->status)->toBe(GameStatusEnum::CLOSED)
            ->and($game->winnings)->toBe(547)
            ->and($game->profit)->toBe(397)
            ->and($game->events()->count())->toBe(6);
        ($provider->settlementError)('upstream settlement rejected');
        expect($sender->messages)->toHaveCount(7)
            ->and($sender->messages[6]['message']['type'])->toBe('hand_over.error')
            ->and($sender->messages[6]['message']['reply_to'])->toBeNull()
            ->and($sender->messages[6]['message']['payload'])->toBe([
                'hand_uuid' => $uuid, 'event_id' => $overId,
                'code' => 'settlement_rejected', 'message' => 'upstream settlement rejected',
            ]);
        $server->onClose($swoole, 92, 0);
        $server->onOpen($swoole, websocketOpenRequest(92, $token));
        ($provider->settlementError)('late failure for closed connection');
        expect($sender->messages)->toHaveCount(7);

    });
});

it('upserts a hand refresh and returns the stable hand UUID in its acknowledgement', function (): void {
    run(function (): void {
        $provider = new class extends BaseProvider
        {
            /** @var list<string> */
            public array $calls = [];

            public function requestAction(Game $game, Closure $callback): void {}

            public function over(Game $game, ?Closure $onError = null): void
            {
                $this->calls[] = 'over';
            }
        };
        $manager = new PokerManager;
        $manager->extend($manager->getDefaultProvider(), fn (): ProviderInterface => $provider);
        $sender = new SenderSpy;
        $server = new PokerServer(
            $sender,
            $manager,
            new UserTokenService,
            new GameService(new CreditService, $manager),
            di(ValidatorFactoryInterface::class),
            new NullLogger,
        );
        $user = TestData::user();
        $token = TestData::token($user);
        $swoole = Mockery::mock();
        $swoole->shouldReceive('disconnect')->never();
        $server->onOpen($swoole, websocketOpenRequest(93, $token));

        $payload = [
            'room_number' => 'full-ws-room', 'hand_number' => 1, 'network' => 'WE',
            'players' => [
                ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
                ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
            ],
            'events' => [
                ['type' => GameEvent::FORCE_BET, 'payload' => ['small_blind' => 50, 'big_blind' => 100]],
                ['type' => GameEvent::HAND_CARD, 'payload' => ['cards' => ['As', 'Qd']]],
                ['type' => GameEvent::PLAYER_ACTED, 'payload' => ['name' => 'Hero', 'action' => 'raise', 'amount' => 100]],
            ],
        ];
        $firstId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(93, json_encode([
            'id' => $firstId, 'type' => GameEvent::HAND_REFRESH, 'timestamp' => (int) floor(microtime(true) * 1000), 'payload' => $payload,
        ], JSON_THROW_ON_ERROR)));
        $uuid = $sender->messages[0]['message']['payload']['hand_uuid'];

        $payload['events'][] = [
            'type' => GameEvent::HAND_OVER,
            'payload' => ['winners' => [['name' => 'Villain', 'amount' => 124], ['name' => 'Hero', 'amount' => 126]]],
        ];
        $secondId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(93, json_encode([
            'id' => $secondId, 'type' => GameEvent::HAND_REFRESH, 'timestamp' => (int) floor(microtime(true) * 1000), 'payload' => $payload,
        ], JSON_THROW_ON_ERROR)));

        $game = Game::query()->where('uuid', $uuid)->firstOrFail();
        expect($sender->messages[0]['message']['type'])->toBe('hand_refresh.ack')
            ->and($sender->messages[0]['message']['reply_to'])->toBe($firstId)
            ->and($sender->messages[1]['message']['type'])->toBe('hand_refresh.ack')
            ->and($sender->messages[1]['message']['reply_to'])->toBe($secondId)
            ->and($sender->messages[1]['message']['payload']['hand_uuid'])->toBe($uuid)
            ->and(Game::query()->where('user_id', $user->id)->where('room_number', 'full-ws-room')->count())->toBe(1)
            ->and($game->status)->toBe(GameStatusEnum::CLOSED)
            ->and($game->events()->count())->toBe(5)
            ->and($game->winnings)->toBe(126)
            ->and($game->profit)->toBe(-24)
            ->and($provider->calls)->toBe(['over']);
    });
});

it('correlates provider failures and preserves their error codes', function (): void {
    run(function (): void {
        $provider = new class extends BaseProvider
        {
            public function requestAction(Game $game, Closure $callback): void
            {
                $callback(RequestActionResultVo::failure(PokerException::solveTimeout()));
            }
        };
        $manager = new PokerManager;
        $manager->extend($manager->getDefaultProvider(), fn (): ProviderInterface => $provider);
        $service = new GameService(new CreditService, $manager);
        $sender = new SenderSpy;
        $server = new PokerServer($sender, $manager, new UserTokenService, $service, di(ValidatorFactoryInterface::class), new NullLogger);
        $user = TestData::user();
        TestData::token($user);
        $token = UserToken::query()->where('user_id', $user->id)->firstOrFail();
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $game = $service->create($user, 'failed-action-room', 1, $players, (string) Str::uuid(), [], NetworkEnum::OK);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['small_blind' => 50, 'big_blind' => 100]);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, ['cards' => ['As', 'Qd']]);
        $id = (string) Str::uuid();
        $server->handleRequestAction(new PokerServerMessageVo(99, $user, $token, $id, GameEvent::REQUEST_ACTION, ['hand_uuid' => $game->uuid], 0));
        expect($sender->messages)->toHaveCount(1)
            ->and($sender->messages[0]['message']['reply_to'] ?? null)->toBe($id)
            ->and($sender->messages[0]['message']['payload']['code'])->toBe('solve_timeout');
    });
});
