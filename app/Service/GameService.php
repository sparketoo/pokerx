<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\ErrorCode;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Exception\GameException;
use App\Game\GameProviderManager;
use App\Job\GameStoreJob;
use App\Model\Game;
use App\Model\GameEvent;
use App\Model\GamePlayer;
use App\Vo\Game\CardVo;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use Carbon\Carbon;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Coroutine\Coroutine;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;
use Throwable;

use function Hyperf\Translation\__;

final class GameService
{
    private const int STORE_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        protected readonly GameProviderManager $pokerManager,
        protected readonly Redis $redis,
        protected readonly DriverFactory $driverFactory,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array{uid:string,name:string,seat:int,stack:int,hero:bool}>  $players
     *
     * @throws GameException
     */
    public function create(
        int $userId,
        string $gameKey,
        NetworkEnum $network,
        int $ante,
        int $bigBlind,
        int $smallBlind,
        array $players,
        int $buttonSeatNumber,
        string $clientId,
    ): GameVo {
        $uuid = Str::uuid()->toString();
        $game = new GameVo(
            $userId,
            $uuid,
            $network,
            $gameKey,
            $bigBlind,
            $smallBlind,
            $ante,
            $players,
            $buttonSeatNumber,
            $clientId,
        );
        $existsKey = 'game:'.$userId.':'.$network->name.':'.$gameKey;
        $exists = $this->redis->get($existsKey);
        if (! $exists) {
            $exists = Game::query()->where('user_id', $userId)
                ->where('network', $network->name)
                ->where('game_key', $gameKey)->exists();
        }

        if ($exists) {
            throw new GameException(__('messages.game.already_exists'), ErrorCode::GAME_ALREADY_EXISTS);
        }
        $this->redis->setex($existsKey, 4000, '1');
        $this->save($game);
        $this->queueToStore($game, 20 * 60);

        return $game;
    }

    public function find(string $uuid): GameVo
    {
        $data = $this->redis->get('game:'.$uuid);
        try {
            $game = is_string($data) ? unserialize($data) : null;
        } catch (Throwable) {
            throw new GameException(__('messages.game.not_found'), ErrorCode::GAME_NOT_FOUND, ['uuid' => $uuid]);
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

    public function queueToStore(GameVo $game, int $delay = 0): void
    {
        $job = new GameStoreJob($game->uuid);
        $this->driverFactory->get('default')->push($job, $delay);
    }

    public function store(GameVo $game): ?Game
    {
        $playerRows = $this->playerRows($game);
        $eventRows = $this->eventRows($game);
        $attempt = 0;

        return Db::transaction(function () use ($game, $playerRows, $eventRows, &$attempt): Game {
            if ($attempt++ > 0) {
                Coroutine::sleep(random_int(20, 50) * ($attempt - 1) / 1000);
            }
            /** @var Game|null $record */
            $record = Game::query()->where('uuid', $game->uuid)->lockForUpdate()->first();
            if ($record !== null && $record->events()->count() > $game->events->count()) {
                return $record;
            }
            $isNew = $record === null;
            $record ??= new Game;
            $hero = $game->hero();
            $record->fill([
                'uuid' => $game->uuid,
                'user_id' => $game->userId,
                'network' => $game->network->name,
                'game_key' => $game->gameKey,
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

            if (! $isNew) {
                $record->gamePlayers()->delete();
                $record->events()->delete();
            }
            GamePlayer::query()->insert(array_map(
                static fn (array $row): array => ['game_id' => $record->id, ...$row],
                $playerRows,
            ));
            GameEvent::query()->insert(array_map(
                static fn (array $row): array => ['game_id' => $record->id, ...$row],
                $eventRows,
            ));

            return $record;
        }, self::STORE_TRANSACTION_ATTEMPTS);
    }

    /** @return list<array<string, mixed>> */
    private function playerRows(GameVo $game): array
    {
        $rows = [];
        $now = Carbon::now();
        foreach ($game->players as $player) {
            $row = new GamePlayer;
            $row->fill([
                'seat' => $player->seatNumber,
                'uid' => $player->uid,
                'name' => $player->name,
                'is_hero' => $player->isHero,
                'stack' => $player->stack,
                'ante' => $player->ante,
                'blind' => $player->blind,
                'post_blind' => $player->postBlind,
                'straddle_blind' => $player->straddleBlind,
                'returned' => $player->returned,
                'bet' => $player->bet,
                'total' => $player->total(),
                'cards' => $player->cards === [] ? null : CardVo::cardsToShort($player->cards),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rows[] = $row->getAttributes();
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function eventRows(GameVo $game): array
    {
        $rows = [];
        $now = Carbon::now();
        foreach ($game->events as $event) {
            $row = new GameEvent;
            $row->fill([
                'user_id' => $game->userId,
                'type' => $event->type->name,
                'timestamp' => $event->timestamp,
                'payload' => $event->payload,
                'created_at' => Carbon::createFromTimestampMs($event->timestamp, 'UTC'),
                'updated_at' => $now,
            ]);
            $rows[] = $row->getAttributes();
        }

        return $rows;
    }
}
