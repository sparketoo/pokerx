<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum NetworkEnum
{
    use EnumHelper;

    case OK;
    case WE;
    case WPK;
    case WPK_CLUB;

    public function isOk(): bool
    {
        return $this->is(self::OK);
    }

    public function isWe(): bool
    {
        return $this->is(self::WE);
    }

    public function isWPK(): bool
    {
        return $this->is(self::WPK);
    }

    public function isWpkClub(): bool
    {
        return $this->is(self::WPK_CLUB);
    }
}
