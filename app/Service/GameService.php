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
use RuntimeException;
use Throwable;

use function App\Support\di;

final class GameService
{
    private const int GAME_TTL = 3600;

    private const string REFRESH_GAME_KEY_SCRIPT = <<<'LUA'
        local owner = redis.call('get', KEYS[1])
        if owner and owner ~= ARGV[1] then return 0 end
        redis.call('set', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]) + 1)
        redis.call('set', KEYS[2], ARGV[3], 'EX', ARGV[2])
        return 1
        LUA;

    private const string RELEASE_GAME_KEY_SCRIPT = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then
            return redis.call('del', KEYS[1])
        end
        return 0
        LUA;

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
        string $gameKey,
        NetworkEnum $network,
        int $ante,
        int $bigBlind,
        int $smallBlind,
        array $players,
        int $buttonSeatNumber,
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
        );

        if (Game::query()->where('user_id', $userId)
            ->where('network', $network->name)
            ->where('game_key', $gameKey)->exists()) {
            throw GameException::gameAlreadyExists();
        }

        $indexKey = $this->gameKeyIndex($game);
        if ($this->redis->set($indexKey, $uuid, ['NX', 'EX' => self::GAME_TTL + 1]) !== true) {
            throw GameException::gameAlreadyExists();
        }

        try {
            $this->save($game);
            // 30分钟后自动CLOSED
            $driver = di(DriverFactory::class)->get('default');
            if (! $driver->push(new GameCloseJob($uuid)->setMaxAttempts(3), 30 * 60)) {
                throw new RuntimeException('Failed to schedule game close job.');
            }
        } catch (Throwable $error) {
            $this->redis->del('game:'.$uuid);
            $this->releaseGameKey($game);

            throw $error;
        }

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
        if ($this->redis->eval(self::REFRESH_GAME_KEY_SCRIPT,
            [$this->gameKeyIndex($game), 'game:'.$game->uuid, $game->uuid, self::GAME_TTL, serialize($game)], 2) !== 1) {
            throw GameException::gameAlreadyExists();
        }
    }

    public function delete(string $uuid): void
    {
        try {
            $game = $this->find($uuid);
        } catch (GameException) {
            $game = null;
        }
        $this->redis->del('game:'.$uuid);
        if ($game !== null) {
            $this->releaseGameKey($game);
        }
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

    private function gameKeyIndex(GameVo $game): string
    {
        return 'game:key:'.$game->userId.':'.$game->network->name.':'.hash('sha256', $game->gameKey);
    }

    private function releaseGameKey(GameVo $game): void
    {
        $this->redis->eval(self::RELEASE_GAME_KEY_SCRIPT, [$this->gameKeyIndex($game), $game->uuid], 1);
    }
}
