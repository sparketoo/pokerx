<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Model\Event;
use App\Model\Game;
use App\Model\User;
use App\Service\UserTokenService;
use Hyperf\Stringable\Str;

final class TestData
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function inCoroutine(callable $callback): mixed
    {
        $result = null;
        \Tests\run(function () use (&$result, $callback): void {
            $result = $callback();
        });

        return $result ?? throw new \LogicException('Coroutine callback must return a value.');
    }

    /** @param array<string, mixed> $attributes */
    public static function user(array $attributes = []): User
    {
        $suffix = str_replace('-', '', Str::uuid()->toString());

        return User::query()->create($attributes + [
            'account' => 'test_'.substr($suffix, 0, 24),
            'nickname' => 'Test player',
            'password' => 'CorrectHorseBatteryStaple',
            'language' => 'zh-CN',
            'is_vip' => true,
            'credit_balance' => 1000,
        ]);
    }

    public static function token(User $user): string
    {
        return (new UserTokenService)->createToken($user, 'test', ['*'])->plainTextToken;
    }

    /** @param array<string, mixed> $attributes */
    public static function game(User $user, array $attributes = []): Game
    {
        $suffix = substr(str_replace('-', '', Str::uuid()->toString()), 0, 12);

        return Game::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'room_number' => 'room-'.$suffix,
            'hand_number' => random_int(1, 4_294_967_295),
            'provider' => 'mock',
            'network' => NetworkEnum::WE,
            'big_blind' => 100,
            'small_blind' => 50,
            'ante' => 0,
            'status' => GameStatusEnum::OPEN,
            'bet_amount' => 50,
            'pot' => 150,
        ]);
    }

    public static function players(Game $game): void
    {
        $game->players()->createMany([
            ['seat' => 1, 'name' => 'Hero', 'is_hero' => true, 'stack' => 1000, 'seat_type' => 'SB', 'blind_amount' => 50, 'cards' => ['As', 'Qd']],
            ['seat' => 2, 'name' => 'Villain', 'is_hero' => false, 'stack' => 1000, 'seat_type' => 'BB', 'blind_amount' => 100, 'cards' => []],
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function event(Game $game, string $type = GameEvent::HAND_START, array $payload = [], ?int $seq = null): Event
    {
        return $game->events()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $game->user_id,
            'seq' => $seq ?? ((int) Event::query()->where('game_id', $game->id)->max('seq') + 1),
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
