<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum GameStatusEnum
{
    use EnumHelper;

    // 游戏进行中
    case OPEN;
    // 弃牌后终止
    case ABORT;
    // 无法确定状态，超时后关闭
    case CLOSED;
    // 正常结束
    case OVER;

    public function isOpen(): bool
    {
        return $this->is(self::OPEN);
    }

    public function isOver(): bool
    {
        return $this->is(self::OVER);
    }

    public function isAbort(): bool
    {
        return $this->is(self::ABORT);
    }

    public function isClosed(): bool
    {
        return $this->is(self::CLOSED);
    }
}
