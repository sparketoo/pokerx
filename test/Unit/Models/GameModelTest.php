<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Model\CreditRecord;
use App\Model\Event;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Model\UserToken;
use Hyperf\Stringable\Str;
use Tests\Support\TestData;

use function Tests\run;

it('casts persisted models and exposes their relationships', function (): void {
    run(function (): void {
        $user = TestData::user();
        $game = TestData::game($user);
        TestData::players($game);
        $event = TestData::event($game, GameEvent::HAND_START, ['room_number' => $game->room_number]);
        $token = TestData::token($user);

        $game = Game::query()->with(['user', 'players', 'events'])->findOrFail($game->id);
        $player = $game->hero();
        $firstEvent = $game->events->firstOrFail();
        $tokenModel = UserToken::query()->whereKey((int) explode('|', $token, 2)[0])->firstOrFail();
        $record = CreditRecord::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'type' => 'GRANT', 'amount' => 10, 'balance' => 1010,
        ]);

        expect($game->network)->toBe(NetworkEnum::WE)
            ->and($game->status)->toBe(GameStatusEnum::OPEN)
            ->and($game->user->id)->toBe($user->id)
            ->and($game->hero()->name)->toBe('Hero')
            ->and($player)->toBeInstanceOf(GamePlayer::class)
            ->and($player->is_hero)->toBeTrue()
            ->and($player->cards)->toBe(['As', 'Qd'])
            ->and($firstEvent)->toBeInstanceOf(Event::class)
            ->and($firstEvent->payload)->toBe(['room_number' => $game->room_number])
            ->and($record->type->name)->toBe('GRANT')
            ->and($tokenModel->user()->firstOrFail()->id)->toBe($user->id);
    });
});

it('casts the persisted pot and personal total bet amounts', function (): void {
    run(function (): void {
        $game = TestData::game(TestData::user(), ['pot' => 100050, 'bet_amount' => 45025]);
        $game = Game::query()->findOrFail($game->id);

        expect($game->pot)->toBe(100050)
            ->and($game->bet_amount)->toBe(45025);
    });
});
