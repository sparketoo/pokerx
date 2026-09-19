<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\NetworkEnum;
use App\Exception\GatewayException;
use App\Game\PokerManager;
use App\Game\PokerServer;
use App\Game\Providers\MockProvider;
use App\Game\Providers\ProtoProvider;
use App\Model\UserToken;
use App\Service\CreditService;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use App\Service\UserTokenService;
use App\Vo\Game\PokerServerMessageVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\NullLogger;
use Swoole\Coroutine\Channel;
use Tests\Support\TestData;

use function Tests\run;

it('records posted blinds and incremental straddles without replaying base blinds', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new GameService(new CreditService, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Small', 'hero' => false, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Big', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
            ['seat' => 3, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'BTN'],
        ];
        $game = $service->create($user, 'forced-room', 1, $players, (string) Str::uuid(), [], NetworkEnum::OK);
        $initial = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, [
            'small_blind' => 50, 'big_blind' => 100, 'ante' => 5,
            'extra_bets' => [['name' => 'Hero', 'type' => 'post', 'amount' => 100]],
        ]);
        expect($initial->pot)->toBe(265)
            ->and($initial->bet_amount)->toBe(105)
            ->and($initial->players->sortBy('seat')->pluck('bet_amount')->all())->toBe([55, 105, 105]);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, ['cards' => ['As', 'Qd']]);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::STAGE_START, ['stage' => 'preflop', 'cards' => []]);
        $game = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, [
            'extra_bets' => [['name' => 'Hero', 'type' => 'straddle', 'amount' => 200]],
        ]);
        expect($game->pot)->toBe(465)
            ->and($game->bet_amount)->toBe(305)
            ->and($game->big_blind)->toBe(100)
            ->and($game->players->sortBy('seat')->pluck('bet_amount')->all())->toBe([55, 105, 305]);
        $report = (new ProtoProvider)->gameEvents($game);
        expect($report['game'])->toMatchArray(['gameType' => 'NL', 'network' => 'OK']);
        $allIn = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::PLAYER_ACTED, [
            'name' => 'Hero', 'action' => 'all-in', 'amount' => 695,
        ]);
        $actionEvents = array_values(array_filter((new ProtoProvider)->gameEvents($allIn)['events'], fn (array $event): bool => $event['eventType'] === 'playerActed'));
        expect($actionEvents[0]['action'])->toBe('all-in');
        $settlement = (new ProtoProvider)->gameEvents($allIn, true);
        expect($settlement['structType'])->toBe('fullGameLog')
            ->and(array_values(array_filter($settlement['events'], fn (array $event): bool => $event['eventType'] === 'playerActed'))[0]['action'])->toBe('all-in');
        $history = $report['events'];
        $forced = array_values(array_filter($history, fn (array $event): bool => $event['eventType'] === 'blindPosted'));
        expect(array_column($forced, 'blindType'))->toBe(['ANTE', 'ANTE', 'ANTE', 'SB', 'BB', 'POST', 'STRADDLE'])
            ->and(array_sum(array_column($forced, 'amount')))->toBe(465);
        expect(fn () => $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['small_blind' => 50, 'big_blind' => 100]))->toThrow(GatewayException::class);
        expect(fn () => $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['extra_bets' => [['name' => 'Unknown', 'type' => 'post', 'amount' => 100]]]))->toThrow(GatewayException::class);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::STAGE_START, ['stage' => 'flop', 'cards' => ['2s', '3d', '4h']]);
        expect(fn () => $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['extra_bets' => [['name' => 'Hero', 'type' => 'straddle', 'amount' => 200]]]))->toThrow(GatewayException::class);
    });
});

it('validates extra bet payloads at the websocket boundary before storing them', function (): void {
    run(function (): void {
        $user = TestData::user();
        TestData::token($user);
        $token = UserToken::query()->where('user_id', $user->id)->firstOrFail();
        $manager = new PokerManager;
        $service = new GameService(new CreditService, $manager);
        $server = new PokerServer(
            \App\Support\di(Sender::class), $manager,
            new UserTokenService, $service,
            \App\Support\di(ValidatorFactoryInterface::class), new NullLogger,
            new InsuranceService(\App\Support\di(ValidatorFactoryInterface::class), new UserGameConfigService),
        );
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $game = $service->create($user, 'forced-validation', 1, $players, (string) Str::uuid(), [], NetworkEnum::OK);
        $message = fn (array $payload) => new PokerServerMessageVo(1, $user, $token, (string) Str::uuid(), GameEvent::FORCE_BET, ['hand_uuid' => $game->uuid] + $payload, 0);
        foreach ([
            ['name' => 'Hero', 'type' => 'call', 'amount' => 100],
            ['name' => 'Hero', 'type' => 'post', 'amount' => -1],
            ['name' => 'Hero', 'type' => 'post', 'amount' => 0],
            ['name' => 'Hero', 'type' => 'straddle', 'amount' => 1.12345],
            ['name' => 'Hero', 'type' => 'straddle', 'amount' => 'invalid'],
            ['name' => 'Hero', 'type' => 'straddle', 'amount' => 100, 'unexpected' => true],
        ] as $bet) {
            expect(fn () => $server->handleForceBet($message(['small_blind' => 50, 'big_blind' => 100, 'extra_bets' => [$bet]])))->toThrow(ValidationException::class);
        }
        expect(fn () => $server->handleForceBet($message(['extra_bets' => [['name' => 'Hero', 'type' => 'post', 'amount' => 100]]])))->toThrow(GatewayException::class);
        $server->handleForceBet($message(['small_blind' => 50, 'big_blind' => 100]));
        $increment = $server->handleForceBet($message(['extra_bets' => [['name' => 'Hero', 'type' => 'straddle', 'amount' => 200]]]));
        expect($increment->pot)->toBe(350)->and($increment->events)->toHaveCount(3);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, ['cards' => ['As', 'Qd']]);
        expect($game->events()->count())->toBe(4);
        Mockery::close();
    });
});

it('replays posted blinds and pre-card straddles with the same totals', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new GameService(new CreditService, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $events = [
            ['type' => GameEvent::FORCE_BET, 'payload' => ['small_blind' => 50, 'big_blind' => 100, 'ante' => 5, 'extra_bets' => [['name' => 'Hero', 'type' => 'post', 'amount' => 100]]]],
            ['type' => GameEvent::FORCE_BET, 'payload' => ['extra_bets' => [['name' => 'Hero', 'type' => 'straddle', 'amount' => 200]]]],
            ['type' => GameEvent::HAND_CARD, 'payload' => ['cards' => ['As', 'Qd']]],
            ['type' => GameEvent::STAGE_START, 'payload' => ['stage' => 'preflop', 'cards' => []]],
        ];
        $game = $service->upsertHandRefresh($user, 'forced-refresh', 1, $players, (string) Str::uuid(), [], $events, NetworkEnum::OK);
        expect($game->pot)->toBe(460)
            ->and($game->bet_amount)->toBe(355)
            ->and($game->hero()->cards)->toBe(['As', 'Qd'])
            ->and($game->players->sortBy('seat')->pluck('bet_amount')->all())->toBe([355, 105]);
        $again = $service->upsertHandRefresh($user, 'forced-refresh', 1, $players, (string) Str::uuid(), [], $events, NetworkEnum::OK);
        expect($again->pot)->toBe(460)->and($again->bet_amount)->toBe(355)->and($again->uuid)->toBe($game->uuid);
    });
});

it('sends only newly dealt community cards to Proto while preserving complete stored boards', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new GameService(new CreditService, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $game = $service->create($user, 'proto-board-room', 1, $players, (string) Str::uuid(), [], NetworkEnum::OK);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['small_blind' => 50, 'big_blind' => 100]);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, ['cards' => ['As', 'Qd']]);
        $boards = [
            'preflop' => [],
            'flop' => ['2c', '3d', '4h'],
            'turn' => ['2c', '3d', '4h', '5s'],
            'river' => ['2c', '3d', '4h', '5s', '6c'],
        ];
        foreach ($boards as $stage => $cards) {
            $game = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::STAGE_START, ['stage' => $stage, 'cards' => $cards]);
        }
        $report = (new ProtoProvider)->gameEvents($game);
        $stages = array_values(array_filter($report['events'], fn (array $event): bool => $event['eventType'] === 'stageStarted'));
        expect(array_column($stages, 'cards'))->toBe(['', '2c,3d,4h', '5s', '6c'])
            ->and($game->events->where('type', GameEvent::STAGE_START)->map(fn ($event) => $event->payload['cards'])->values()->all())->toBe(array_values($boards));
    });
});

it('preserves integer bets recommendations and settlement in storage and Proto', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new GameService(new CreditService, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 10000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 10000, 'seat_type' => 'BB'],
        ];
        $game = $service->create($user, 'integer-money-room', 1, $players, (string) Str::uuid(), [], NetworkEnum::OK);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, ['small_blind' => 50, 'big_blind' => 100]);
        $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, ['cards' => ['As', 'Qd']]);
        $game = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::PLAYER_ACTED, ['name' => 'Villain', 'action' => 'raise', 'amount' => 125]);
        $reply = new Channel(1);
        (new MockProvider)->requestAction($game, function (RequestActionResultVo $result) use ($reply): void {
            $reply->push($result);
        });
        $advice = $reply->pop(1);
        expect($advice)->toBeInstanceOf(RequestActionResultVo::class);
        if ($advice instanceof RequestActionResultVo) {
            expect($advice->amount)->toBe(175);
        }
        $game = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::PLAYER_ACTED, ['name' => 'Hero', 'action' => 'call', 'amount' => 175]);
        $game = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_OVER, ['winners' => [['name' => 'Hero', 'amount' => 443]]]);
        $data = $game->toArray();
        expect($data['pot'])->toBe(450)->and($data['bet_amount'])->toBe(225)
            ->and($data['winnings'])->toBe(443)->and($data['profit'])->toBe(218);
        $report = (new ProtoProvider)->gameEvents($game, true);
        $actions = array_values(array_filter($report['events'], fn (array $event): bool => $event['eventType'] === 'playerActed'));
        expect(array_column($actions, 'amount'))->toBe([125, 175])
            ->and($report['game']['bigBlind'])->toBe(100);
    });
});
