<?php

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum GameEventTypeEnum
{
    use EnumHelper;

    // 游戏开始
    case START;
    // 玩家补交盲注
    case POST_BLIND;
    // 阶段开始
    case STAGE;
    // 获得手牌
    case DEALT;
    // 玩家行动
    case ACTION;
    // 已知玩家底牌
    case SHOW;
    // 游戏异常终止
    case ABORT;
    // 游戏正常结束
    case OVER;

    public function isStart(): bool
    {
        return $this->is(self::START);
    }

    public function isPostBlind(): bool
    {
        return $this->is(self::POST_BLIND);
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

    public function isAbort(): bool
    {
        return $this->is(self::ABORT);
    }

    public function isOver(): bool
    {
        return $this->is(self::OVER);
    }
}
