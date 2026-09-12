<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum AckStatusEnum
{
    use EnumHelper;

    case ACCEPTED;
    case DUPLICATE;

    public function isAccepted(): bool
    {
        return $this->is(self::ACCEPTED);
    }

    public function isDuplicate(): bool
    {
        return $this->is(self::DUPLICATE);
    }
}
