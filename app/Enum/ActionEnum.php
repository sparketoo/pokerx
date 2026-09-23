<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum ActionEnum
{
    use EnumHelper;

    case FOLD;
    case CHECK;
    case CALL;
    case BET;
    case RAISE;
    case ALL_IN;

    public function isFold(): bool
    {
        return $this->is(self::FOLD);
    }

    public function isCheck(): bool
    {
        return $this->is(self::CHECK);
    }

    public function isCall(): bool
    {
        return $this->is(self::CALL);
    }

    public function isBet(): bool
    {
        return $this->is(self::BET);
    }

    public function isRaise(): bool
    {
        return $this->is(self::RAISE);
    }

    public function isAllIn(): bool
    {
        return $this->is(self::ALL_IN);
    }

    /** Wire representation used by the GameServer protocol. */
    public function wire(): string
    {
        return $this->name;
    }
}
