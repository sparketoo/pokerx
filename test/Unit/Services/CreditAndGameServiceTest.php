<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Exception\CreditException;
use App\Exception\GameException;
use App\Model\CreditRecord;
use App\Model\Event;
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

it('creates games with their first event and closes them when game_over arrives', function (): void {
    run(function (): void {
        $user = TestData::user(['is_vip' => false, 'credit_balance' => 5]);
        $credits = new CreditService;
        $service = new GameService($credits);
        $players = [
            ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB', 'amount' => null, 'cards' => ['As', 'Qd']],
            ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB', 'amount' => null],
        ];
        $payload = ['room_number' => 'service-room', 'hand_number' => 1, 'players' => $players];
        $game = $service->create($user, 'service-room', 1, 'mock', 100, 50, 0, $players, (string) Str::uuid(), $payload);
        $heroActed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::GAME_PLAY_ACTED, [
            'name' => 'Hero', 'action' => 'raise', 'amount' => 125,
        ]);
        $villainActed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::GAME_PLAY_ACTED, [
            'name' => 'Villain', 'action' => 'call', 'amount' => 75,
        ]);
        $closed = $service->append($user, $game->uuid, (string) Str::uuid(), GameEvent::GAME_OVER, [
            'winner' => ['name' => 'Hero', 'amount' => 300],
        ]);

        expect($game->players)->toHaveCount(2)
            ->and($game->pot)->toBe(150.0)
            ->and($game->bet_amount)->toBe(50.0)
            ->and($heroActed->pot)->toBe(275.0)
            ->and($heroActed->bet_amount)->toBe(175.0)
            ->and($villainActed->pot)->toBe(350.0)
            ->and($villainActed->bet_amount)->toBe(175.0)
            ->and($credits->balance($user))->toBe(4)
            ->and($closed->status)->toBe(GameStatusEnum::CLOSED)
            ->and($closed->winnings)->toBe(300.0)
            ->and($closed->profit)->toBe(125.0)
            ->and(Event::query()->where('game_id', $game->id)->orderBy('seq')->pluck('seq')->all())->toBe([1, 2, 3, 4]);
        expect(fn () => $service->create($user, 'service-room', 1, 'mock', 100, 50, 0, $players, (string) Str::uuid(), $payload))->toThrow(GameException::class);
    });
});
