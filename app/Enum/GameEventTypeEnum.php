<?php

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum GameEventTypeEnum
{
    use EnumHelper;

    // 游戏开始
    case START;
    // 玩家实际支付的盲注
    case BLIND_POSTED;
    // 阶段开始
    case STAGE;
    // 获得手牌
    case DEALT;
    // 玩家行动
    case ACTION;
    // 已知玩家底牌
    case SHOW;
    // 本人确认购买保险
    case INSURANCE_PURCHASED;
    // 开局时玩家当前持有鱿鱼
    case PLAYER_HAS_SQUID;
    // 本手获得鱿鱼
    case GOT_SQUID;
    // 游戏异常终止
    case ABORT;
    // 游戏正常结束
    case OVER;

    public function isStart(): bool
    {
        return $this->is(self::START);
    }

    public function isBlindPosted(): bool
    {
        return $this->is(self::BLIND_POSTED);
    }

    public function isStage(): bool
    {
        return $this->is(self::STAGE);
    }

    public function isDealt(): bool
    {
        return $this->is(self::DEALT);
    }

    public function isAction(): bool
    {
        return $this->is(self::ACTION);
    }

    public function isShow(): bool
    {
        return $this->is(self::SHOW);
    }

    public function isInsurancePurchased(): bool
    {
        return $this->is(self::INSURANCE_PURCHASED);
    }

    public function isPlayerHasSquid(): bool
    {
        return $this->is(self::PLAYER_HAS_SQUID);
    }

    public function isGotSquid(): bool
    {
        return $this->is(self::GOT_SQUID);
    }

    public function isAbort(): bool
    {
        return $this->is(self::ABORT);
    }

    public function isOver(): bool
    {
        return $this->is(self::OVER);
    }
}
