<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum NetworkEnum
{
    use EnumHelper;

    case OK;
    case WE;

    public function isOk(): bool
    {
        return $this->is(self::OK);
    }

    public function isWe(): bool
    {
        return $this->is(self::WE);
    }
}
