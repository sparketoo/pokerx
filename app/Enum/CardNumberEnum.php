<?php

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum CardNumberEnum
{
    use EnumHelper;

    case SPADES;
    case HEARTS;
    case DIAMONDS;
    case CLUBS;

    public function isSpades(): bool
    {
        return $this->is(self::SPADES);
    }

    public function isHearts(): bool
    {
        return $this->is(self::HEARTS);
    }

    public function isDiamonds(): bool
    {
        return $this->is(self::DIAMONDS);
    }

    public function isClubs(): bool
    {
        return $this->is(self::CLUBS);
    }
}
