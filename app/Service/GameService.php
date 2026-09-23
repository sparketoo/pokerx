<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Exception\FoundationException;
use App\Exception\GameException;
use App\Game\GameProviderManager;
use App\Job\GameCloseJob;
use App\Model\Game;
use App\Model\GameEvent;
use App\Model\GamePlayer;
use App\Vo\Game\CardVo;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use Carbon\Carbon;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use Throwable;
use function App\Support\di;

final class GameService
{
    public function __construct(
        protected readonly GameProviderManager $pokerManager,
        protected readonly Redis $redis,
    ) {}

    /**
     * @param  list<array{uid:string,name:string,seat:int,stack:int,hero:bool}>  $players
     *
     * @throws GameException
     */
    public function create(
        int $userId,
        string $roomNumber,
        int $gameNumber,
        NetworkEnum $network,
        int $ante,
        int $bigBlind,
        int $smallBlind,
        array $players,
        int $buttonSeatNumber,
    ): GameVo {
        $uuid = Str::random(16);
        $game = new GameVo(
            $userId,
            $uuid,
            $network,
            $roomNumber,
            $gameNumber,
            $bigBlind,
            $smallBlind,
            $ante,
            $players,
            $buttonSeatNumber,
        );

        $this->redis->setex('game:'.$uuid, 3600, serialize($game));
        // 30分钟后自动CLOSED
        $driver = di(DriverFactory::class)->get('default');
        $driver->push(new GameCloseJob($game->uuid)->setMaxAttempts(3), 30 * 60);

        return $game;
    }

    public function find(string $uuid): GameVo
    {
        $data = $this->redis->get('game:'.$uuid);
        try {
            $game = is_string($data) ? unserialize($data) : null;
        } catch (Throwable) {
            throw GameException::gameUuidNotFound($uuid);
        }
        if (! $game instanceof GameVo) {
            throw GameException::gameUuidNotFound($uuid);
        }

        return $game;
    }

    public function save(GameVo $game): void
    {
        $this->redis->setex('game:'.$game->uuid, 3600, serialize($game));
    }

    public function delete(string $uuid): void
    {
        $this->redis->del('game:'.$uuid);
    }

    /**
     * @param  string  $uuid  游戏UUID
     * @param  array<string, mixed>  $payload
     *
     * @throws FoundationException
     * @throws GameException
     */
    public function event(string $uuid, GameEventTypeEnum $type, array $payload, int $timestamp): GameEventVo
    {
        $game = $this->find($uuid);
        $event = $game->event(
            $type,
            $payload,
            $timestamp,
        );
        $this->save($game);

        return $event;
    }

    public function store(GameVo $game): Game
    {
        return Db::transaction(function () use ($game): Game {
            /** @var Game|null $record */
            $record = Game::query()->where('uuid', $game->uuid)->lockForUpdate()->first();
            if ($record !== null && $record->events()->count() > $game->events->count()) {
                return $record;
            }
            $record ??= new Game;
            $hero = $game->hero();
            $record->fill([
                'uuid' => $game->uuid,
                'user_id' => $game->userId,
                'network' => $game->network->name,
                'room_number' => $game->roomNumber,
                'hand_number' => $game->handNumber,
                'provider' => $this->pokerManager->getDefaultProvider(),
                'players' => $game->players->count(),
                'status' => $game->status->name,
                'big_blind' => $game->bigBlind,
                'small_blind' => $game->smallBlind,
                'ante' => $game->ante,
                'pot' => $game->pot(),
                'total' => $hero->total(),
                'winnings' => $hero->winnings,
                'profit' => $hero->winnings - $hero->total(),
            ]);
            if (! $record->exists) {
                $record->created_at = Carbon::createFromTimestampMs($game->createdAtMs);
            }
            $record->saveOrFail();

            $record->gamePlayers()->delete();
            foreach ($game->players as $player) {
                $row = new GamePlayer;
                $row->fill([
                    'game_id' => $record->id,
                    'seat' => $player->seatNumber,
                    'uid' => $player->uid,
                    'name' => $player->name,
                    'is_hero' => $player->isHero,
                    'stack' => $player->stack,
                    'ante' => $player->ante,
                    'blind' => $player->blind,
                    'bet' => $player->bet,
                    'total' => $player->total(),
                    'cards' => $player->cards === [] ? null : CardVo::cardsToShort($player->cards),
                ]);
                $row->saveOrFail();
            }

            $record->events()->delete();
            foreach ($game->events as $event) {
                $row = new GameEvent;
                $row->fill([
                    'user_id' => $game->userId,
                    'game_id' => $record->id,
                    'type' => $event->type->name,
                    'timestamp' => $event->timestamp,
                    'payload' => $event->payload,
                    'created_at' => Carbon::createFromTimestampMs($event->timestamp, 'UTC'),
                ]);
                $row->saveOrFail();
            }

            return $record;
        });
    }
}
