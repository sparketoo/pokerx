<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum StageEnum
{
    use EnumHelper;

    case PREFLOP;
    case FLOP;
    case TURN;
    case RIVER;
    case SHOWDOWN;

    public function isPreflop(): bool
    {
        return $this->is(self::PREFLOP);
    }

    public function isFlop(): bool
    {
        return $this->is(self::FLOP);
    }

    public function isTurn(): bool
    {
        return $this->is(self::TURN);
    }

    public function isRiver(): bool
    {
        return $this->is(self::RIVER);
    }

    public function isShowdown(): bool
    {
        return $this->is(self::SHOWDOWN);
    }
}
