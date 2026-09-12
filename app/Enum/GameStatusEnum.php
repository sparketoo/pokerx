<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum GameStatusEnum
{
    use EnumHelper;

    case OPEN;
    case CLOSED;
    case SETTLED;
    case INCOMPLETE;

    public function isOpen(): bool
    {
        return $this->is(self::OPEN);
    }

    public function isClosed(): bool
    {
        return $this->is(self::CLOSED);
    }

    public function isSettled(): bool
    {
        return $this->is(self::SETTLED);
    }

    public function isIncomplete(): bool
    {
        return $this->is(self::INCOMPLETE);
    }
}
