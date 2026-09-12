<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum UserStatusEnum
{
    use EnumHelper;

    case NORMAL;
    case FROZEN;
    case DISABLED;

    public function isNormal(): bool
    {
        return $this->is(self::NORMAL);
    }

    public function isFrozen(): bool
    {
        return $this->is(self::FROZEN);
    }

    public function isDisabled(): bool
    {
        return $this->is(self::DISABLED);
    }
}
