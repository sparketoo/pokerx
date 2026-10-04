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
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

use function App\Support\appTimezone;
use function Hyperf\Translation\__;

final class GameService
{
    private const int STORE_TRANSACTION_ATTEMPTS = 3;

    private const int EVENT_UPDATE_ATTEMPTS = 12;

    private const int SQUID_ROUND_TTL = 86400;

    private const string UPDATE_GAME_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    redis.call('SETEX', KEYS[1], 3600, ARGV[2])
    return 1
end
return 0
LUA;

    private const string UPDATE_SQUID_GAME_SCRIPT = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 0
end
local played = tonumber(redis.call('HGET', KEYS[2], '__played') or '0')
if played ~= tonumber(ARGV[6]) or played + tonumber(ARGV[4]) > tonumber(ARGV[5]) then
    return -1
end
redis.call('SETEX', KEYS[1], 3600, ARGV[2])
redis.call('HINCRBY', KEYS[2], '__played', ARGV[4])
redis.call('HINCRBY', KEYS[2], ARGV[3], ARGV[4])
redis.call('EXPIRE', KEYS[2], 86400)
return 1
LUA;

    private const string INIT_SQUID_ROUND_SCRIPT = <<<'LUA'
local current = tonumber(redis.call('HGET', KEYS[1], '__played') or '-1')
if current > tonumber(ARGV[1]) then
    return -1
end
redis.call('HSET', KEYS[1], '__played', ARGV[1])
for index = 3, #ARGV, 2 do
    redis.call('HSET', KEYS[1], ARGV[index], ARGV[index + 1])
end
redis.call('EXPIRE', KEYS[1], ARGV[2])
return 1
LUA;

    public function __construct(
        protected readonly GameProviderManager $pokerManager,
        protected readonly Redis $redis,
        protected readonly DriverFactory $driverFactory,
        protected readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<array{uid:string,name?:string,seat:int,stack:int,hero:bool,squidNumber?:int}>  $players
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
        string $gameType = 'NL',
        string $squidMode = 'STAND_UP',
        int $squidCost = 0,
        int $squidNumber = 0,
        int $squidRound = 0,
        ?int $initialSquidPlayed = null,
    ): GameVo {
        $uuid = Str::uuid()->toString();
        $squidPlayed = 0;
        $squidPlayers = [];
        $roundKey = null;
        if ($gameType === 'SQUID') {
            if ($initialSquidPlayed === null) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            $heroUid = '';
            foreach ($players as $player) {
                if ($player['hero']) {
                    $heroUid = strtolower($player['uid']);
                    break;
                }
            }
            $roundKey = $this->squidRoundKey($userId, $network, $gameKey, $squidRound, $heroUid);
            $squidPlayed = $initialSquidPlayed;
            foreach ($players as $player) {
                if (! isset($player['squidNumber'])) {
                    throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
                }
                $squidPlayers[strtolower($player['uid'])] = $player['squidNumber'];
            }
            if (array_sum($squidPlayers) > $squidPlayed) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
        } elseif ($initialSquidPlayed !== null) {
            throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
        }
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
            $gameType,
            $squidMode,
            $squidCost,
            $squidNumber,
            $squidRound,
            $squidPlayed,
            $squidPlayers,
        );
        $heroUid = $game->hero()->uid;
        $existsKey = 'game:'.$userId.':'.$network->name.':'.$gameKey.':'.$heroUid;
        $exists = $this->redis->get($existsKey);
        if (! $exists) {
            $exists = Game::query()->where('user_id', $userId)
                ->where('network', $network->name)
                ->where('game_key', $gameKey)->where('hero_uid', $heroUid)->exists();
        }

        if ($exists) {
            throw new GameException(__('messages.game.already_exists'), ErrorCode::GAME_ALREADY_EXISTS);
        }
        if ($roundKey !== null) {
            // OK 快照只能推进同一轮进度，不能被另一个 Worker 的旧 START 回退。
            $arguments = [$roundKey, $squidPlayed, self::SQUID_ROUND_TTL];
            foreach ($squidPlayers as $uid => $count) {
                $arguments[] = $uid;
                $arguments[] = $count;
            }
            if ($this->redis->eval(self::INIT_SQUID_ROUND_SCRIPT, $arguments, 1) !== 1) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
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

    public function findForClient(string $uuid, int $userId, string $clientId): GameVo
    {
        $game = $this->find($uuid);
        if ($game->userId !== $userId
            || ! array_key_exists('clientId', get_object_vars($game))
            || $game->clientId !== $clientId) {
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

    public function registerParticipant(GameVo $game, int $fd, int $workerId): void
    {
        $hero = $game->hero();
        $member = [
            'uuid' => $game->uuid,
            'fd' => $fd,
            'worker_id' => $workerId,
            'client_id' => $game->clientId,
            'uid' => $hero->uid,
            'name' => $hero->name,
            'seat' => $hero->seatNumber,
            'stack' => $hero->stack,
        ];
        $key = $this->participantsKey($game);
        $this->redis->rPush($key, json_encode($member, JSON_THROW_ON_ERROR));
        $this->redis->expire($key, 3600);
    }

    /** @return list<array{uuid:string,fd:int,worker_id:int,client_id:string,uid:string,name:string,seat:int,stack:int}> */
    public function participants(GameVo $game): array
    {
        $members = [];
        $values = $this->redis->lRange($this->participantsKey($game), 0, -1);
        if (! is_array($values)) {
            return [];
        }
        foreach ($values as $value) {
            try {
                $member = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($member) && is_string($member['uuid'] ?? null)
                && is_int($member['fd'] ?? null) && is_int($member['worker_id'] ?? null)
                && is_string($member['client_id'] ?? null) && is_string($member['uid'] ?? null)
                && is_string($member['name'] ?? null) && is_int($member['seat'] ?? null)
                && is_int($member['stack'] ?? null)) {
                $members[] = $member;
            }
        }

        return $members;
    }

    private function participantsKey(GameVo $game): string
    {
        return 'game:participants:'.$game->network->name.':'.$game->gameKey;
    }

    /**
     * @param  string  $uuid  游戏UUID
     * @param  array<string, mixed>  $payload
     *
     * @throws GameException
     */
    public function event(
        string $uuid,
        GameEventTypeEnum $type,
        array $payload,
        int $timestamp,
        int $userId,
        string $clientId,
    ): GameEventVo {
        $key = 'game:'.$uuid;
        for ($attempt = 0; $attempt < self::EVENT_UPDATE_ATTEMPTS; $attempt++) {
            $previous = $this->redis->get($key);
            if (! is_string($previous)) {
                throw new GameException(__('messages.game.not_found'), ErrorCode::GAME_NOT_FOUND, ['uuid' => $uuid]);
            }
            try {
                $game = unserialize($previous);
            } catch (Throwable) {
                throw new GameException(__('messages.game.not_found'), ErrorCode::GAME_NOT_FOUND, ['uuid' => $uuid]);
            }
            if (! $game instanceof GameVo || $game->userId !== $userId
                || $game->clientId !== $clientId) {
                throw new GameException(__('messages.game.not_found'), ErrorCode::GAME_NOT_FOUND, ['uuid' => $uuid]);
            }
            $count = $game->events->count();
            $event = $game->event($type, $payload, $timestamp);
            if ($game->events->count() === $count) {
                return $event;
            }
            // 所有事件共用 CAS，避免另一个 Worker 的普通牌局事件覆盖保险流水。
            if ($type->isGotSquid()) {
                $roundKey = $this->squidRoundKey($game->userId, $game->network, $game->gameKey,
                    $game->squidRound, $game->hero()->uid);
                $updated = $this->redis->eval(self::UPDATE_SQUID_GAME_SCRIPT, [
                    $key, $roundKey, $previous, serialize($game), $event->payload['uid'],
                    $event->payload['count'], $game->squidNumber, $game->squidPlayed,
                ], 2);
            } else {
                $updated = $this->redis->eval(self::UPDATE_GAME_SCRIPT, [$key, $previous, serialize($game)], 1);
            }
            if ($updated === 1) {
                return $event;
            }
            if ($updated === -1) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
        }

        throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
    }

    /** @param array<string, mixed> $payload */
    public function purchaseInsurance(string $uuid, array $payload, int $timestamp, int $userId, string $clientId): GameEventVo
    {
        return $this->event($uuid, GameEventTypeEnum::INSURANCE_PURCHASED, $payload, $timestamp, $userId, $clientId);
    }

    private function squidRoundKey(int $userId, NetworkEnum $network, string $gameKey, int $round, string $heroUid): string
    {
        $room = strstr($gameKey, '#', true);
        if ($room === false || $room === '' || $round < 1 || $heroUid === '') {
            throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
        }

        return 'game:squid:'.$userId.':'.$network->name.':'.$room.':'.$round.':'.strtolower($heroUid);
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
                'hero_uid' => $hero->uid,
                'provider' => $this->pokerManager->getDefaultProvider(),
                'game_type' => $game->gameType,
                'squid_mode' => $game->gameType === 'SQUID' ? $game->squidMode : null,
                'squid_cost' => $game->gameType === 'SQUID' ? $game->squidCost : null,
                'squid_number' => $game->gameType === 'SQUID' ? $game->squidNumber : null,
                'squid_round' => $game->gameType === 'SQUID' ? $game->squidRound : null,
                'squid_played' => $game->gameType === 'SQUID' ? $game->squidPlayed : null,
                'squid_players' => $game->gameType === 'SQUID' ? $game->squidPlayers : null,
                'players' => $game->players->count(),
                'status' => $game->status->name,
                'big_blind' => $game->bigBlind,
                'small_blind' => $game->smallBlind,
                'ante' => $game->ante,
                'pot' => $game->pot(),
                'total' => $hero->total(),
                'winnings' => $hero->winnings,
                'insurance_amount' => $game->insuranceAmount(),
                'insurance_payout' => $game->insurancePayout(),
                'profit' => $hero->winnings - $hero->total() - $game->insuranceAmount() + $game->insurancePayout(),
            ]);
            if (! $record->exists) {
                $record->created_at = Carbon::createFromTimestampMs($game->createdAtMs, appTimezone());
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
                'created_at' => Carbon::createFromTimestampMs($event->timestamp, appTimezone()),
                'updated_at' => $now,
            ]);
            $rows[] = $row->getAttributes();
        }

        return $rows;
    }
}
