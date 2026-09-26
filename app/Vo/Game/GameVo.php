<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\BusinessException;
use App\Exception\GameException;
use App\Vo\Vo;
use Hyperf\Collection\Collection;

use function Hyperf\Translation\__;

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

    /**
     * @param  list<array{uid:string,name?:string,seat:int,stack:int,hero:bool}>  $players
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $uuid,
        public readonly NetworkEnum $network,
        public readonly string $gameKey,
        public readonly int $bigBlind,
        public readonly int $smallBlind,
        public readonly int $ante,
        array $players,
        public readonly int $buttonSeatNumber,
        public readonly string $clientId,
    ) {

        if (count($players) < 2) {
            throw new GameException(__('messages.game.players_less_than_two'));
        }

        if ($this->buttonSeatNumber < 1 || $this->buttonSeatNumber > 10) {
            throw new GameException(__('messages.game.button_seat_not_found'));
        }

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
            throw new GameException(__('messages.game.players_empty'), ErrorCode::BUSINESS_ERROR);
        }
        $this->hero();
        $this->stage = StageEnum::PREFLOP;
        $this->status = GameStatusEnum::OPEN;
        $this->createdAtMs = (int) (microtime(true) * 1000);
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
            throw new GameException(__('messages.game.hero_not_found'), ErrorCode::BUSINESS_ERROR);
        }

        return $hero;
    }

    /**
     * 获取玩家
     */
    public function player(string $uid): ?GamePlayerVo
    {
        return $this->players->where(fn (GamePlayerVo $playerVo) => strcasecmp($playerVo->uid, $uid) === 0)->first();
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
            throw new GameException(__('messages.game.player_not_found'), ErrorCode::BUSINESS_ERROR, ['name' => $uid]);
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
            throw new BusinessException(__('messages.common.status_invalid'), ErrorCode::BUSINESS_ERROR);
        }

        $uid = $payload['uid'] ?? null;
        $player = is_string($uid) ? $this->player($uid) : null;
        if ($player !== null) {
            $uid = $player->uid;
            $payload['uid'] = $uid;
        }
        if ($type->isBlindPosted()) {
            $blindType = $payload['type'] ?? null;
            $amount = $payload['amount'] ?? null;
            $player = $this->playerOrFail(is_string($uid) ? $uid : '');
            if (! in_array($blindType, ['SB', 'BB', 'POST', 'STRADDLE'], true)
                || ! is_int($amount) || $amount <= 0 || $amount > $player->stack - $player->total()
                || $this->stage !== StageEnum::PREFLOP) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            // 普通盲注和补盲在其他事件前；自愿盲注可在 PREFLOP 后到达。
            if ($blindType !== 'STRADDLE' && $this->events->contains(fn (GameEventVo $event): bool => ! $event->type->isBlindPosted() || ($event->payload['type'] ?? null) === 'STRADDLE')) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            if (($blindType === 'SB' || $blindType === 'BB')
                && $this->events->contains(fn (GameEventVo $event): bool => $event->type->isBlindPosted()
                    && (($event->payload['type'] ?? null) === $blindType
                        || ($event->player === $player && in_array($event->payload['type'] ?? null, ['SB', 'BB'], true))))) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            if ($blindType === 'POST' && $this->events->contains(fn (GameEventVo $event): bool => $event->type->isBlindPosted()
                && ($event->payload['type'] ?? null) === 'POST' && $event->player === $player)) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            $player->blind += $amount;
        }
        if ($type->isOver()) {
            $seen = [];
            foreach ($payload['returns'] ?? [] as &$return) {
                $returnPlayer = $this->playerOrFail($return['uid']);
                $amount = $return['amount'] ?? null;
                if (isset($seen[$returnPlayer->uid]) || ! is_int($amount) || $amount <= 0
                    || $amount > $returnPlayer->total() + $returnPlayer->returned) {
                    throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
                }
                $seen[$returnPlayer->uid] = true;
                $return['uid'] = $returnPlayer->uid;
            }
            unset($return);
            foreach ($payload['winners'] as &$winner) {
                $winner['uid'] = $this->playerOrFail($winner['uid'])->uid;
            }
            unset($winner);
            if (isset($payload['shown'])) {
                foreach ($payload['shown'] as &$shown) {
                    $shown['uid'] = $this->playerOrFail($shown['uid'])->uid;
                }
                unset($shown);
            }
        }
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
            // 退回不属于奖金，从玩家净投入和最终底池中扣除。
            foreach ($payload['returns'] ?? [] as $return) {
                $this->playerOrFail($return['uid'])->returned += $return['amount'];
            }
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
