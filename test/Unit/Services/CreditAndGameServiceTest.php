<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Exception\CreditException;
use App\Exception\GameException;
use App\Game\PokerManager;
use App\Model\CreditRecord;
use App\Model\Event;
use App\Model\Game;
use App\Service\CreditService;
use App\Service\GameService;
use Hyperf\Stringable\Str;
use Tests\Support\TestData;

use function Tests\run;

it('maintains a credit ledger atomically and makes a UUID retry safe', function (): void {
    run(function (): void {
        $user = TestData::user(['credit_balance' => 20]);
        $service = new CreditService;
        $uuid = (string) Str::uuid();

        $first = $service->recharge($user, 30, 'test grant', $uuid);
        $replay = $service->recharge($user, 30, 'test grant', $uuid);
        $spent = $service->consume($user, 25, 'test consume');

        expect($first->id)->toBe($replay->id)
            ->and($spent->amount)->toBe(-25)
            ->and($service->balance($user))->toBe(25)
            ->and(CreditRecord::query()->where('user_id', $user->id)->count())->toBe(2);
        expect(fn () => $service->consume($user, 26))->toThrow(CreditException::class);
        expect(fn () => $service->recharge($user, 1, null, $uuid))->toThrow(CreditException::class);
    });
});

it('creates games with their first event and closes them when hand_over arrives', function (): void {
    run(function (): void {
        $user = TestData::user(['is_vip' => false, 'credit_balance' => 5]);
        $credits = new CreditService;
        $service = new GameService($credits, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $payload = ['room_number' => 'service-room', 'hand_number' => 1, 'network' => 'WE', 'players' => $players];
        $game = $service->create($user, 'service-room', 1, $players, (string) Str::uuid(), $payload, NetworkEnum::WE);
        $forced = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::FORCE_BET, [
            'small_blind' => 50, 'big_blind' => 100,
        ]);
        $dealt = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_CARD, [
            'cards' => ['As', 'Qd'],
        ]);
        $heroActed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::PLAYER_ACTED, [
            'name' => 'Hero', 'action' => 'raise', 'amount' => 125,
        ]);
        $villainActed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::PLAYER_ACTED, [
            'name' => 'Villain', 'action' => 'call', 'amount' => 75,
        ]);
        $closed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::HAND_OVER, [
            'winner' => ['name' => 'Hero', 'amount' => 300],
        ]);

        expect($game->provider)->toBe((new PokerManager)->getDefaultProvider())
            ->and($game->players)->toHaveCount(2)
            ->and($game->pot)->toBe(0)
            ->and($game->bet_amount)->toBe(0)
            ->and($forced->pot)->toBe(150)
            ->and($forced->bet_amount)->toBe(50)
            ->and($dealt->hero()->cards)->toBe(['As', 'Qd'])
            ->and($heroActed->pot)->toBe(275)
            ->and($heroActed->bet_amount)->toBe(175)
            ->and($villainActed->pot)->toBe(350)
            ->and($villainActed->bet_amount)->toBe(175)
            ->and($credits->balance($user))->toBe(4)
            ->and($closed->status)->toBe(GameStatusEnum::CLOSED)
            ->and($closed->winnings)->toBe(300)
            ->and($closed->profit)->toBe(125)
            ->and(Event::query()->where('game_id', $game->id)->orderBy('seq')->pluck('seq')->all())->toBe([1, 2, 3, 4, 5, 6]);
        expect(fn () => $service->create($user, 'service-room', 1, $players, (string) Str::uuid(), $payload, NetworkEnum::WE))->toThrow(GameException::class);
    });
});

it('upserts an authoritative hand refresh by its network, room and hand', function (): void {
    run(function (): void {
        $user = TestData::user(['is_vip' => false, 'credit_balance' => 5]);
        $credits = new CreditService;
        $service = new GameService($credits, new PokerManager);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB'],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ];
        $start = [
            'room_number' => 'full-report-room', 'hand_number' => 7, 'network' => 'WE',
            'players' => $players,
        ];
        $first = $service->upsertHandRefresh(
            $user, 'full-report-room', 7, $players, (string) Str::uuid(), $start,
            [
                ['type' => GameEvent::FORCE_BET, 'payload' => ['small_blind' => 50, 'big_blind' => 100]],
                ['type' => GameEvent::HAND_CARD, 'payload' => ['cards' => ['As', 'Qd']]],
                ['type' => GameEvent::PLAYER_ACTED, 'payload' => ['name' => 'Hero', 'action' => 'raise', 'amount' => 100]],
            ],
            NetworkEnum::WE,
        );
        $second = $service->upsertHandRefresh(
            $user, 'full-report-room', 7, $players, (string) Str::uuid(), $start,
            [
                ['type' => GameEvent::FORCE_BET, 'payload' => ['small_blind' => 50, 'big_blind' => 100]],
                ['type' => GameEvent::HAND_CARD, 'payload' => ['cards' => ['As', 'Qd']]],
                ['type' => GameEvent::PLAYER_ACTED, 'payload' => ['name' => 'Hero', 'action' => 'raise', 'amount' => 200]],
                ['type' => GameEvent::HAND_OVER, 'payload' => ['winner' => ['name' => 'Hero', 'amount' => 350]]],
            ],
            NetworkEnum::WE,
        );

        expect($second->uuid)->toBe($first->uuid)
            ->and($second->provider)->toBe((new PokerManager)->getDefaultProvider())
            ->and(Game::query()->where('user_id', $user->id)->where('room_number', 'full-report-room')->count())->toBe(1)
            ->and($credits->balance($user))->toBe(4)
            ->and($second->status)->toBe(GameStatusEnum::CLOSED)
            ->and($second->pot)->toBe(350)
            ->and($second->bet_amount)->toBe(250)
            ->and($second->winnings)->toBe(350)
            ->and($second->profit)->toBe(100)
            ->and(Event::query()->where('game_id', $second->id)->orderBy('seq')->pluck('seq')->all())->toBe([1, 2, 3, 4, 5]);
    });
});
