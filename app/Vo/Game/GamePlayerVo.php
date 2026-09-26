<?php

namespace App\Vo\Game;

use App\Enum\ActionEnum;
use App\Vo\Vo;
use Hyperf\Collection\Collection;

class GamePlayerVo extends Vo
{
    /**
     * 前注
     */
    public readonly int $ante;

    /** 本手实际支付的全部盲注。 */
    public int $blind = 0;

    /** 未被跟注而退回的下注，不计入最终底池。 */
    public int $returned = 0;

    /**
     * 主动下注总额（不含前注和盲注）
     */
    public int $bet;

    /**
     * 赢得的奖金
     */
    public int $winnings = 0;

    public bool $isFold = false;

    /**
     * @var list<CardVo|string>
     */
    public array $cards = [];

    /**
     * @param  GameVo  $game  本手游戏
     * @param  string  $uid  玩家UID
     * @param  string  $name  玩家昵称
     * @param  int  $seatNumber  玩家座位号
     * @param  int  $stack  玩家初始筹码(本手开始时的筹码，前注之前)
     * @param  bool  $isHero  是否本人
     */
    public function __construct(
        public readonly GameVo $game,
        public readonly string $uid,
        public readonly string $name,
        public readonly int $seatNumber,
        public readonly int $stack,
        public readonly bool $isHero,
    ) {

        // 前注
        $this->ante = $this->game->ante;
        $this->bet = 0;
    }

    /**
     * 获取玩家本手净投入：所有盲注及下注减去退回金额。
     */
    public function total(): int
    {
        return $this->ante + $this->blind + $this->bet - $this->returned;
    }

    /**
     * 该玩家的完整事件
     *
     * @return Collection<int, GameEventVo>
     */
    public function events(): Collection
    {
        return $this->game->events->where(fn (GameEventVo $event) => $event->player === $this)->values();
    }

    public function action(ActionEnum $action, ?int $amount = null): static
    {
        if ($this->isFold) {
            return $this;
        }
        if ($action->isFold()) {
            $this->isFold = true;

            return $this;
        }
        if (! $action->isCheck()) {
            $this->bet += $amount;
        }

        return $this;
    }

    /**
     * @param  list<CardVo>  $cards
     * @return $this
     */
    public function knownCards(array $cards): static
    {
        $this->cards = $cards;

        return $this;
    }

    public function winnings(int $amount): static
    {
        if ($this->isFold) {
            return $this;
        }
        $this->winnings = $amount;

        return $this;
    }
}
