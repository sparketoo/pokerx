<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum SeatTypeEnum
{
    use EnumHelper;

    case SB;
    case BB;
    case BTN;
    case UTG;
    case UTG1;
    case UTG2;
    case MP;
    case HJ;
    case CO;

    public function isSb(): bool
    {
        return $this->is(self::SB);
    }

    public function isBb(): bool
    {
        return $this->is(self::BB);
    }

    public function isBtn(): bool
    {
        return $this->is(self::BTN);
    }

    public function isUtg(): bool
    {
        return $this->is(self::UTG);
    }

    public function isUtg1(): bool
    {
        return $this->is(self::UTG1);
    }

    public function isUtg2(): bool
    {
        return $this->is(self::UTG2);
    }

    public function isMp(): bool
    {
        return $this->is(self::MP);
    }

    public function isHj(): bool
    {
        return $this->is(self::HJ);
    }

    public function isCo(): bool
    {
        return $this->is(self::CO);
    }
}
