<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum SolveStatusEnum
{
    use EnumHelper;

    case PENDING;
    case SUCCEEDED;
    case FAILED;
    case TIMED_OUT;
    case STALE;

    public function isPending(): bool
    {
        return $this->is(self::PENDING);
    }

    public function isSucceeded(): bool
    {
        return $this->is(self::SUCCEEDED);
    }

    public function isFailed(): bool
    {
        return $this->is(self::FAILED);
    }

    public function isTimedOut(): bool
    {
        return $this->is(self::TIMED_OUT);
    }

    public function isStale(): bool
    {
        return $this->is(self::STALE);
    }
}
