<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Enum\SeatTypeEnum;
use App\Exception\GameException;
use App\Model\Event;
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
     * @param  array<string, mixed>  $eventPayload
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
        string $eventUuid,
        array $eventPayload,
        string $gameType = 'NL',
    ): Game {
        $uuid = Str::uuid()->toString();
        DB::beginTransaction();
        try {
            if ($this->exists($user->id, $gameType, $roomNumber, $handNumber)) {
                throw GameException::gameAlreadyExists();
            }

            if (! $user->is_vip) {
                $this->creditService->consume($user, 1, 'poker', $uuid);
            }

            $players = collect($players)->map(function (array $player) use ($smallBlind, $bigBlind) {
                $seatType = SeatTypeEnum::fromNameOrFail($player['seat_type']);
                $blindAmount = match ($seatType) {
                    SeatTypeEnum::BB => $bigBlind,
                    SeatTypeEnum::SB => $smallBlind,
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
            $hero = $players->firstWhere('is_hero', true);
            if (empty($hero)) {
                throw GameException::heroNotFound();
            }

            $betAmount = match ($hero['seat_type']) {
                SeatTypeEnum::BB => $bigBlind,
                SeatTypeEnum::SB => $smallBlind,
                default => 0
            };

            $totalBlinds = (float) $players->sum('blind_amount');
            $game = Game::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'room_number' => $roomNumber,
                'hand_number' => $handNumber,
                'provider' => $provider,
                'game_type' => $gameType,
                'big_blind' => $bigBlind,
                'small_blind' => $smallBlind,
                'ante' => $ante,
                // 我的投注金额
                'bet_amount' => $ante + $betAmount,
                // 总池
                'pot' => $ante * $players->count() + $totalBlinds,
            ]);

            $game->players()->createMany($players->toArray());
            $game->events()->create([
                'uuid' => $eventUuid,
                'user_id' => $user->id,
                'seq' => 1,
                'type' => GameEvent::GAME_START,
                'payload' => $eventPayload,
            ]);
            DB::commit();

            return $game;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

    }

    /**
     * Persist an accepted follow-up event and derive the game lifecycle state.
     * Idempotent replay is deliberately not handled here yet.
     *
     * @param  array<string, mixed>  $payload
     */
    public function append(User $user, string $gameUuid, string $eventUuid, string $type, array $payload): Game
    {
        return DB::transaction(function () use ($user, $gameUuid, $eventUuid, $type, $payload): Game {
            /** @var Game $game */
            $game = Game::query()
                ->where('uuid', $gameUuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();
            $seq = (int) Event::query()->where('game_id', $game->id)->max('seq') + 1;
            $game->events()->create([
                'uuid' => $eventUuid,
                'user_id' => $user->id,
                'seq' => $seq,
                'type' => $type,
                'payload' => $payload,
            ]);
            if ($type === GameEvent::GAME_PLAY_ACTED) {
                $amount = $this->actedAmount($payload);
                $game->pot = round($game->pot + $amount, 4);

                /** @var string $heroName */
                $heroName = $this->heroName($game);
                if (($payload['name'] ?? null) === $heroName) {
                    $game->bet_amount = round($game->bet_amount + $amount, 4);
                }
                $game->saveOrFail();
            }
            if ($type === GameEvent::GAME_OVER && $game->status->isOpen()) {
                $game->winnings = $this->winningsFor($game, $payload);
                $game->profit = round($game->winnings - $game->bet_amount, 4);
                $game->status = GameStatusEnum::CLOSED;
                $game->saveOrFail();
            }
            $game->load(['players', 'events']);

            return $game;
        });
    }

    public function exists(int $userId, string $gameType, string $roomNumber, int $handNumber): bool
    {
        return Game::query()
            ->where('user_id', $userId)
            ->where('game_type', $gameType)
            ->where('room_number', $roomNumber)
            ->where('hand_number', $handNumber)
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function actedAmount(array $payload): float
    {
        $amount = $payload['amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount < 0) {
            throw GameException::invalidPlayerActed();
        }

        return round((float) $amount, 4);
    }

    /** @param array<string, mixed> $payload */
    private function winningsFor(Game $game, array $payload): float
    {
        $winner = $payload['winner'] ?? null;
        $amount = is_array($winner) ? $winner['amount'] ?? null : null;
        if (! is_array($winner) || ! is_string($winner['name'] ?? null) || ! is_numeric($amount) || (float) $amount < 0) {
            throw GameException::invalidGameOver();
        }

        return $winner['name'] === $this->heroName($game) ? round((float) $amount, 4) : 0.0;
    }

    private function heroName(Game $game): string
    {
        $name = $game->players()
            ->where('is_hero', true)
            ->value('name');

        return is_string($name) ? $name : throw GameException::heroNotFound();
    }
}
