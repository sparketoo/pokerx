<?php

namespace App\Vo\Game;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\FoundationException;
use App\Exception\GameException;
use App\Vo\Vo;
use Hyperf\Collection\Collection;

class GameVo extends Vo
{
    /**
     * 玩家列表，包含本人(hero)
     *
     * @var Collection<int, GamePlayerVo>
     */
    public readonly Collection $players;

    /**
     * 本手事件
     *
     * @var Collection<int, GameEventVo>
     */
    public readonly Collection $events;

    public StageEnum $stage;

    /**
     * 公共牌
     *
     * @var list<string>
     */
    public array $cards = [];

    public GameStatusEnum $status;

    public readonly int $createdAtMs;

    private readonly int $smallBlindSeatNumber;

    private readonly int $bigBlindSeatNumber;

    /**
     * @param  list<array{uid:string,name?:string,seat:int,stack:int,hero:bool}>  $players
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $uuid,
        public readonly NetworkEnum $network,
        public readonly string $roomNumber,
        public readonly int $handNumber,
        public readonly int $bigBlind,
        public readonly int $smallBlind,
        public readonly int $ante,
        array $players,
        public readonly int $buttonSeatNumber,
    ) {
        $seats = array_column($players, 'seat');
        sort($seats, SORT_NUMERIC);
        if (count($seats) < 2 || ! in_array($buttonSeatNumber, $seats, true)) {
            throw GameException::bigBlindNotFound();
        }
        $nextSeat = static function (int $seat) use ($seats): int {
            foreach ($seats as $candidate) {
                if ($candidate > $seat) {
                    return $candidate;
                }
            }

            return $seats[0];
        };
        $this->smallBlindSeatNumber = count($seats) === 2 ? $buttonSeatNumber : $nextSeat($buttonSeatNumber);
        $this->bigBlindSeatNumber = $nextSeat($this->smallBlindSeatNumber);
        $this->events = new Collection;
        $this->players = new Collection([]);
        foreach ($players as $player) {
            $this->players->push(new GamePlayerVo(
                $this,
                $player['uid'],
                $player['name'] ?? $player['uid'],
                $player['seat'],
                $player['stack'],
                $player['hero'],
            ));
        }

        if ($this->players->isEmpty()) {
            throw GameException::playersEmpty();
        }
        // 校验本人和大盲是否存在
        $this->hero();
        $this->bigBlindPlayer();
        $this->stage = StageEnum::PREFLOP;
        $this->status = GameStatusEnum::OPEN;
        $this->createdAtMs = (int) (microtime(true) * 1000);
    }

    public function smallBlindSeatNumber(): int
    {
        return $this->smallBlindSeatNumber;
    }

    public function bigBlindSeatNumber(): int
    {
        return $this->bigBlindSeatNumber;
    }

    /**
     * 获取本人
     *
     * @throws GameException
     */
    public function hero(): GamePlayerVo
    {
        /** @var GamePlayerVo|null $hero */
        $hero = $this->players->first(fn (GamePlayerVo $player) => $player->isHero);
        if (empty($hero)) {
            throw GameException::heroNotFound();
        }

        return $hero;
    }

    /**
     * 获取大盲玩家
     *
     * @throws GameException
     */
    public function bigBlindPlayer(): GamePlayerVo
    {
        /** @var GamePlayerVo|null $bb */
        $bb = $this->players->first(fn (GamePlayerVo $playerVo) => $playerVo->isBb());
        if (empty($bb)) {
            throw GameException::bigBlindNotFound();
        }

        return $bb;
    }

    /**
     * 获取玩家
     */
    public function player(string $uid): ?GamePlayerVo
    {
        return $this->players->where(fn (GamePlayerVo $playerVo) => $playerVo->uid === $uid)->first();
    }

    /**
     * 除本人以外的玩家
     *
     * @return Collection<int,GamePlayerVo>
     */
    public function anotherPlayers(): Collection
    {
        return $this->players->where(fn (GamePlayerVo $player) => ! $player->isHero)->values();
    }

    public function playerOrFail(string $uid): GamePlayerVo
    {
        $player = $this->player($uid);
        if (empty($player)) {
            throw GameException::playerNotFound($uid);
        }

        return $player;
    }

    /**
     * 获取本手总底池：主池+边池
     */
    public function pot(): int
    {
        return $this->players->sum(fn (GamePlayerVo $playerVo) => $playerVo->total());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function event(
        GameEventTypeEnum $type,
        array $payload,
        int $timestamp,
    ): GameEventVo {

        if (! $this->status->isOpen()) {
            throw FoundationException::statusInvalid();
        }

        $uid = $payload['uid'] ?? null;
        $player = $uid ? $this->player($uid) : null;
        $event = new GameEventVo(
            $this,
            $type,
            $payload,
            $timestamp,
            $player,
        );
        $this->events->push($event);

        if ($type->isAction()) {
            // 玩家操作
            $action = ActionEnum::fromNameOrFail($payload['action']);
            $this->playerOrFail($uid ?? '')->action($action, $payload['amount'] ?? null);
        } elseif ($type->isShow()) {
            // 已知玩家手牌
            $this->playerOrFail($uid ?? '')->knownCards($payload['cards']);
        } elseif ($type->isDealt()) {
            // 本人获得手牌
            $this->hero()->knownCards($payload['cards']);
        } elseif ($type->isStage()) {
            // 阶段开始
            $this->stage = StageEnum::fromNameOrFail($payload['stage']);
            if ($this->stage->isFlop() || $this->stage->isTurn() || $this->stage->isRiver()) {
                /** @var list<string> $cards * */
                $cards = $payload['cards'] ?? [];
                $this->cards = array_merge($this->cards, $cards);
            }
        } elseif ($type->isOver()) {
            // 牌局结束
            $this->status = GameStatusEnum::OVER;
            foreach ($payload['winners'] as $winner) {
                $player = $this->playerOrFail($winner['uid']);
                $player->winnings($winner['amount'] ?? 0);
            }
            foreach ($payload['shown'] ?? [] as $shown) {
                $player = $this->playerOrFail($shown['uid']);
                $player->knownCards($shown['cards']);
            }
        } elseif ($type->isAbort()) {
            // 牌局终止
            $this->status = GameStatusEnum::ABORT;
        }

        return $event;
    }
}
