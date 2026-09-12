<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum CreditRecordTypeEnum
{
    use EnumHelper;

    case GRANT;
    case CONSUME;

    public function isGrant(): bool
    {
        return $this->is(self::GRANT);
    }

    public function isConsume(): bool
    {
        return $this->is(self::CONSUME);
    }
}
