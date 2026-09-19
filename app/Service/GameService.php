<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Enum\SeatTypeEnum;
use App\Exception\GameException;
use App\Exception\GatewayException;
use App\Game\PokerManager;
use App\Model\Event;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Model\User;
use Hyperf\DbConnection\Db as DB;
use Hyperf\Stringable\Str;
use Illuminate\Support\Collection;
use Throwable;

final class GameService
{
    public function __construct(
        protected readonly CreditService $creditService,
        protected readonly PokerManager $pokerManager,
    ) {}

    /**
     * @param  list<array{seat: int, name: string, hero: bool, stack: int, seat_type: string}>  $players
     * @param  array<string, mixed>  $eventPayload
     */
    public function create(
        User $user,
        string $roomNumber,
        int $handNumber,
        array $players,
        string $eventUuid,
        array $eventPayload,
        NetworkEnum $network,
    ): Game {
        $uuid = Str::uuid()->toString();
        DB::beginTransaction();
        try {
            if ($this->exists($user->id, $network, $roomNumber, $handNumber)) {
                throw GameException::gameAlreadyExists();
            }

            if (! $user->is_vip) {
                $this->creditService->consume($user, 1, 'poker', $uuid);
            }

            $players = collect($players)->map(function (array $player) {
                $seatType = SeatTypeEnum::fromNameOrFail($player['seat_type']);

                return [
                    'seat' => $player['seat'],
                    'name' => $player['name'],
                    'is_hero' => $player['hero'],
                    'stack' => $player['stack'],
                    'seat_type' => $seatType,
                    'blind_amount' => 0,
                    'bet_amount' => 0,
                    'cards' => [],
                ];
            });
            $hero = $players->firstWhere('is_hero', true);
            if (empty($hero)) {
                throw GameException::heroNotFound();
            }

            $provider = $this->pokerManager->getDefaultProvider();
            $game = Game::query()->create([
                'uuid' => $uuid,
                'user_id' => $user->id,
                'room_number' => $roomNumber,
                'hand_number' => $handNumber,
                'provider' => $provider,
                'network' => $network,
                'big_blind' => 0,
                'small_blind' => 0,
                'ante' => 0,
                'bet_amount' => 0,
                'pot' => 0,
            ]);

            $game->players()->createMany($players->toArray());
            $game->events()->create([
                'uuid' => $eventUuid,
                'user_id' => $user->id,
                'seq' => 1,
                'type' => GameEvent::HAND_START,
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
     * Only game_abort supports replay with the same event ID and payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function append(User $user, string $handUuid, string $eventUuid, string $type, array $payload): Game
    {
        return DB::transaction(function () use ($user, $handUuid, $eventUuid, $type, $payload): Game {
            /** @var Game $game */
            $game = Game::query()
                ->where('uuid', $handUuid)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($type === GameEvent::GAME_ABORT) {
                if (array_keys($payload) !== ['hand_uuid'] || $payload['hand_uuid'] !== $handUuid) {
                    throw GatewayException::eventInvalid();
                }
                $previous = $game->events()->where('uuid', $eventUuid)->first();
                if ($previous !== null) {
                    if (! $game->status->isAbort() || $previous->type !== $type || $previous->payload !== $payload) {
                        throw GatewayException::eventInvalid();
                    }

                    return $game->load(['players', 'events']);
                }
            }
            $seq = (int) Event::query()->where('game_id', $game->id)->max('seq') + 1;
            $this->assertEventSequence($game, $type, $seq, $payload);
            $game->events()->create([
                'uuid' => $eventUuid,
                'user_id' => $user->id,
                'seq' => $seq,
                'type' => $type,
                'payload' => $payload,
            ]);
            if ($type === GameEvent::FORCE_BET) {
                $this->applyForceBet($game, $payload);
            }
            if ($type === GameEvent::HAND_CARD) {
                $this->applyHandCard($game, $payload);
            }
            if ($type === GameEvent::PLAYER_ACTED) {
                $amount = $this->actedAmount($payload);
                $game->pot = $game->pot + $amount;
                if (is_string($payload['name'] ?? null)) {
                    $game->players()->where('name', $payload['name'])->increment('bet_amount', $amount);
                }

                /** @var string $heroName */
                $heroName = $this->heroName($game);
                if (($payload['name'] ?? null) === $heroName) {
                    $game->bet_amount = $game->bet_amount + $amount;
                }
                $game->saveOrFail();
            }
            if ($type === GameEvent::GAME_ABORT) {
                $game->winnings = 0;
                $game->profit = -$game->bet_amount;
                $game->status = GameStatusEnum::ABORT;
                $game->saveOrFail();
            }
            if ($type === GameEvent::HAND_OVER && $game->status->isOpen()) {
                $game->winnings = $this->winningsFor($game, $payload);
                $game->profit = $game->winnings - $game->bet_amount;
                $game->status = GameStatusEnum::CLOSED;
                $game->saveOrFail();
            }
            $game->load(['players', 'events']);

            return $game;
        });
    }

    public function exists(int $userId, NetworkEnum $network, string $roomNumber, int $handNumber): bool
    {
        return Game::query()
            ->where('user_id', $userId)
            ->where('network', $network->name)
            ->where('room_number', $roomNumber)
            ->where('hand_number', $handNumber)
            ->exists();
    }

    /**
     * Replace a hand with the authoritative history supplied by a reconnecting
     * client.  The natural hand key is deliberately used here rather than a
     * client-held hand UUID: a client that missed hand_start.ack has no UUID to
     * send back.
     *
     * @param  list<array{type: string, payload: array<string, mixed>, id?: string}>  $events
     * @param  list<array{seat: int, name: string, hero: bool, stack: int, seat_type: string}>  $players
     * @param  array<string, mixed>  $startPayload
     */
    public function upsertHandRefresh(
        User $user,
        string $roomNumber,
        int $handNumber,
        array $players,
        string $eventUuid,
        array $startPayload,
        array $events,
        NetworkEnum $network,
    ): Game {
        return DB::transaction(function () use (
            $user,
            $roomNumber,
            $handNumber,
            $players,
            $eventUuid,
            $startPayload,
            $events,
            $network,
        ): Game {
            $forceBet = $events[0]['payload'];
            $bigBlind = (int) $forceBet['big_blind'];
            $smallBlind = (int) $forceBet['small_blind'];
            $ante = (int) ($forceBet['ante'] ?? 0);
            $preparedPlayers = $this->preparePlayers($players, $smallBlind, $bigBlind, $ante);
            $hero = $preparedPlayers->firstWhere('is_hero', true);
            if (empty($hero)) {
                throw GameException::heroNotFound();
            }
            $handCard = collect($events)->firstWhere('type', GameEvent::HAND_CARD);
            if ($handCard === null) {
                throw GatewayException::eventInvalid();
            }
            $hero['cards'] = $handCard['payload']['cards'];
            $preparedPlayers = $preparedPlayers->map(function (array $player) use ($hero): array {
                return $player['is_hero'] ? $hero : $player;
            });

            /** @var Game|null $game */
            $game = Game::query()
                ->where('user_id', $user->id)
                ->where('network', $network->name)
                ->where('room_number', $roomNumber)
                ->where('hand_number', $handNumber)
                ->lockForUpdate()
                ->first();

            if ($game !== null && ($game->status->isAbort()
                || (! $game->status->isOpen() && collect($events)->contains('type', GameEvent::GAME_ABORT)))) {
                throw GatewayException::eventInvalid();
            }
            if ($game === null) {
                $uuid = Str::uuid()->toString();
                if (! $user->is_vip) {
                    $this->creditService->consume($user, 1, 'poker', $uuid);
                }
                $game = new Game(['uuid' => $uuid, 'user_id' => $user->id]);
            } else {
                // A full report is a snapshot, not a collection of missing
                // events.  Removing dependent rows first makes a replay
                // converge instead of duplicating actions and pot amounts.
                $game->events()->delete();
                $game->players()->delete();
            }

            $betAmount = $ante + match ($hero['seat_type']) {
                SeatTypeEnum::BB => $bigBlind,
                SeatTypeEnum::SB => $smallBlind,
                default => 0,
            };
            $pot = $ante * $preparedPlayers->count() + (int) $preparedPlayers->sum('blind_amount');
            $heroName = $hero['name'];
            $winnings = 0;
            $status = GameStatusEnum::OPEN;

            $heroFolded = false;
            foreach ($events as $event) {
                if (! $status->isOpen()) {
                    throw GatewayException::eventInvalid();
                }
                $payload = $event['payload'];
                if ($event['type'] === GameEvent::FORCE_BET) {
                    foreach ($event['payload']['extra_bets'] ?? [] as $bet) {
                        if (! $preparedPlayers->contains('name', $bet['name'])) {
                            throw GatewayException::eventInvalid();
                        }
                        $pot = $pot + (int) $bet['amount'];
                        $preparedPlayers = $this->addPreparedPlayerBet(
                            $preparedPlayers,
                            (string) $bet['name'],
                            (int) $bet['amount'],
                        );
                        if ($bet['name'] === $heroName) {
                            $betAmount = $betAmount + (int) $bet['amount'];
                        }
                    }
                }
                if ($event['type'] === GameEvent::PLAYER_ACTED) {
                    $amount = $this->actedAmount($payload);
                    $pot = $pot + $amount;
                    if (is_string($payload['name'] ?? null)) {
                        $preparedPlayers = $this->addPreparedPlayerBet(
                            $preparedPlayers,
                            $payload['name'],
                            $amount,
                        );
                    }
                    if (($payload['name'] ?? null) === $heroName) {
                        $heroFolded = $heroFolded || (($payload['action'] ?? null) === 'fold' && $amount === 0);
                        $betAmount = $betAmount + $amount;
                    }
                }
                if ($event['type'] === GameEvent::GAME_ABORT) {
                    if (! $heroFolded || array_diff(array_keys($payload), ['hand_uuid']) !== []) {
                        throw GatewayException::eventInvalid();
                    }
                    $status = GameStatusEnum::ABORT;
                }
                if ($event['type'] === GameEvent::HAND_OVER) {
                    $winnings = $this->winningsForName($heroName, array_map(static fn (array $player): string => $player['name'], $players), $payload);
                    $status = GameStatusEnum::CLOSED;
                }
            }

            $provider = $this->pokerManager->getDefaultProvider();
            $game->fill([
                'room_number' => $roomNumber,
                'hand_number' => $handNumber,
                'provider' => $provider,
                'network' => $network,
                'big_blind' => $bigBlind,
                'small_blind' => $smallBlind,
                'ante' => $ante,
                'status' => $status,
                'bet_amount' => $betAmount,
                'winnings' => $winnings,
                'profit' => ! $status->isOpen() ? $winnings - $betAmount : null,
                'pot' => $pot,
            ]);
            $game->saveOrFail();
            $game->players()->createMany($preparedPlayers->all());
            $game->events()->create([
                'uuid' => $eventUuid,
                'user_id' => $user->id,
                'seq' => 1,
                'type' => GameEvent::HAND_START,
                'payload' => $startPayload,
            ]);
            foreach ($events as $index => $event) {
                $payload = $event['payload'];
                $payload['hand_uuid'] = $game->uuid;
                $game->events()->create([
                    'uuid' => $event['id'] ?? Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'seq' => $index + 2,
                    'type' => $event['type'],
                    'payload' => $payload,
                ]);
            }
            $game->load(['players', 'events']);

            return $game;
        });
    }

    /** @param  array<string, mixed>  $payload */
    private function actedAmount(array $payload): int
    {
        $amount = $payload['amount'] ?? null;
        if (! is_int($amount) || $amount < 0) {
            throw GameException::invalidPlayerActed();
        }

        return $amount;
    }

    /** @param  array<string, mixed>  $payload */
    private function winningsFor(Game $game, array $payload): int
    {
        return $this->winningsForName($this->heroName($game), array_map(static fn (GamePlayer $player): string => $player->name, $game->players()->get()->all()), $payload);
    }

    /**
     * @param  array<int, string>  $playerNames
     * @param  array<string, mixed>  $payload
     */
    private function winningsForName(string $heroName, array $playerNames, array $payload): int
    {
        $winners = $payload['winners'] ?? null;
        if (! is_array($winners) || ! array_is_list($winners) || $winners === []) {
            throw GameException::invalidGameOver();
        }

        $seen = [];
        $winnings = 0;
        foreach ($winners as $winner) {
            $name = is_array($winner) ? $winner['name'] ?? null : null;
            $amount = is_array($winner) ? $winner['amount'] ?? null : null;
            if (! is_string($name) || ! in_array($name, $playerNames, true) || in_array($name, $seen, true)
                || ! is_int($amount) || $amount < 0 || $amount > 9007199254740991) {
                throw GameException::invalidGameOver();
            }
            $seen[] = $name;
            if ($name === $heroName) {
                // Total actual awards from all pots, already aggregated per player by the adapter.
                $winnings = $amount;
            }
        }

        return $winnings;
    }

    /**
     * @param  list<array{seat: int, name: string, hero: bool, stack: int, seat_type: string}>  $players
     * @return Collection<int, array<string, mixed>>
     */
    private function preparePlayers(array $players, int $smallBlind, int $bigBlind, int $ante): Collection
    {
        return collect($players)->map(function (array $player) use ($smallBlind, $bigBlind, $ante) {
            $seatType = SeatTypeEnum::fromNameOrFail($player['seat_type']);
            $blindAmount = match ($seatType) {
                SeatTypeEnum::BB => $bigBlind,
                SeatTypeEnum::SB => $smallBlind,
                default => 0,
            };

            return [
                'seat' => $player['seat'],
                'name' => $player['name'],
                'is_hero' => $player['hero'],
                'stack' => $player['stack'],
                'seat_type' => $seatType,
                'blind_amount' => $blindAmount,
                'bet_amount' => $ante + $blindAmount,
                'cards' => [],
            ];
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $players
     * @return Collection<int, array<string, mixed>>
     */
    private function addPreparedPlayerBet(Collection $players, string $name, int $amount): Collection
    {
        return $players->map(function (array $player) use ($name, $amount): array {
            if ($player['name'] === $name) {
                $player['bet_amount'] = (int) $player['bet_amount'] + $amount;
            }

            return $player;
        });
    }

    /** @param array<string, mixed> $payload */
    private function applyForceBet(Game $game, array $payload): void
    {
        if (isset($payload['big_blind'])) {
            $bigBlind = (int) $payload['big_blind'];
            $smallBlind = (int) $payload['small_blind'];
            $ante = (int) ($payload['ante'] ?? 0);
            $game->players()->where('seat_type', SeatTypeEnum::SB->name)->update(['blind_amount' => $smallBlind]);
            $game->players()->where('seat_type', SeatTypeEnum::BB->name)->update(['blind_amount' => $bigBlind]);
            $game->players()->increment('bet_amount', $ante);
            $game->players()->where('seat_type', SeatTypeEnum::SB->name)->increment('bet_amount', $smallBlind);
            $game->players()->where('seat_type', SeatTypeEnum::BB->name)->increment('bet_amount', $bigBlind);
            $hero = $game->players()->where('is_hero', true)->firstOrFail();
            $heroBlind = match ($hero->seat_type) {
                SeatTypeEnum::BB => $bigBlind,
                SeatTypeEnum::SB => $smallBlind,
                default => 0,
            };
            $game->big_blind = $bigBlind;
            $game->small_blind = $smallBlind;
            $game->ante = $ante;
            $game->bet_amount = $ante + $heroBlind;
            $game->pot = $ante * $game->players()->count() + $smallBlind + $bigBlind;
        }
        $heroName = $this->heroName($game);
        foreach ($payload['extra_bets'] ?? [] as $bet) {
            if (! $game->players()->where('name', $bet['name'])->exists()) {
                throw GatewayException::eventInvalid();
            }
            $amount = (int) $bet['amount'];
            $game->pot = $game->pot + $amount;
            $game->players()->where('name', $bet['name'])->increment('bet_amount', $amount);
            if ($bet['name'] === $heroName) {
                $game->bet_amount = $game->bet_amount + $amount;
            }
        }
        $game->saveOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function applyHandCard(Game $game, array $payload): void
    {
        $hero = $game->players()->where('is_hero', true)->firstOrFail();
        $hero->cards = $payload['cards'];
        $hero->saveOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function assertEventSequence(Game $game, string $type, int $seq, array $payload): void
    {
        $events = $game->events()->orderBy('seq')->get();
        $dealt = $events->contains('type', GameEvent::HAND_CARD);
        $valid = match ($type) {
            GameEvent::GAME_ABORT => $dealt && $events->contains(fn (Event $event): bool => $event->type === GameEvent::PLAYER_ACTED
                && ($event->payload['name'] ?? null) === $this->heroName($game)
                && ($event->payload['action'] ?? null) === 'fold'
                && ($event->payload['amount'] ?? null) === 0),
            GameEvent::FORCE_BET => $seq === 2
                ? isset($payload['small_blind'], $payload['big_blind'])
                : $seq > 2 && ! empty($payload['extra_bets'])
                    && array_intersect(['small_blind', 'big_blind', 'ante'], array_keys($payload)) === []
                    && ! $events->contains(fn (Event $event): bool => $event->type === GameEvent::STAGE_START && ($event->payload['stage'] ?? null) !== 'preflop'),
            GameEvent::HAND_CARD => $seq >= 3 && ! $dealt,
            default => $dealt,
        };
        if (! $valid || ! $game->status->isOpen()) {
            throw GatewayException::eventInvalid();
        }
    }

    private function heroName(Game $game): string
    {
        $name = $game->players()
            ->where('is_hero', true)
            ->value('name');

        return is_string($name) ? $name : throw GameException::heroNotFound();
    }
}
