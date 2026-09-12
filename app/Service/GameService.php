<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\SeatTypeEnum;
use App\Exception\GameException;
use App\Model\Game;
use App\Model\User;
use Hyperf\DbConnection\Db as DB;
use Hyperf\Stringable\Str;
use Throwable;

final class GameService
{
    public function __construct(protected readonly CreditService $creditService) {}

    /**
     * @param  list<array{seat: int, name: string, hero: bool, stack: float, seat_type: string, amount: ?float, cards?: list<string>|null}>  $players
     */
    public function create(
        User $user,
        string $roomNumber,
        int $handNumber,
        string $provider,
        float $bigBlind,
        float $smallBlind,
        float $ante,
        array $players,
    ): Game {
        $uuid = Str::uuid()->toString();
        DB::beginTransaction();
        try {
            if ($this->exists($user->id, $roomNumber, $handNumber)) {
                throw GameException::gameAlreadyExists();
            }

            if (! $user->is_vip) {
                $this->creditService->consume($user, 1, 'poker', $uuid);
            }

            $game = Game::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'room_number' => $roomNumber,
                'hand_number' => $handNumber,
                'provider' => $provider,
                'big_blind' => $bigBlind,
                'small_blind' => $smallBlind,
                'ante' => $ante,
            ]);

            $players = collect($players)->map(function (array $player) use ($game) {
                $seatType = SeatTypeEnum::fromNameOrFail($player['seat_type']);
                $blindAmount = match ($seatType) {
                    SeatTypeEnum::BB => $game->big_blind,
                    SeatTypeEnum::SB => $game->small_blind,
                    default => 0
                };

                return [
                    'seat' => $player['seat'],
                    'name' => $player['name'],
                    'is_hero' => $player['hero'],
                    'stack' => $player['stack'],
                    'seat_type' => $seatType,
                    'blind_amount' => $blindAmount,
                    'cards' => $player['cards'] ?? [],
                ];
            });

            $game->players()->createMany($players->toArray());
            DB::commit();

            return $game;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

    }

    public function exists(int $userId, string $roomNumber, int $handNumber): bool
    {
        return Game::query()
            ->where('user_id', $userId)
            ->where('room_number', $roomNumber)
            ->where('hand_number', $handNumber)
            ->exists();
    }
}
