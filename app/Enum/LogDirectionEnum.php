<?php

declare(strict_types=1);

namespace App\Enum;

use App\Enum\Concerns\EnumHelper;

enum LogDirectionEnum
{
    use EnumHelper;

    case CLIENT_IN;
    case CLIENT_OUT;
    case PROVIDER_OUT;
    case PROVIDER_IN;
    case INTERNAL;

    public function isClientIn(): bool
    {
        return $this->is(self::CLIENT_IN);
    }

    public function isClientOut(): bool
    {
        return $this->is(self::CLIENT_OUT);
    }

    public function isProviderOut(): bool
    {
        return $this->is(self::PROVIDER_OUT);
    }

    public function isProviderIn(): bool
    {
        return $this->is(self::PROVIDER_IN);
    }

    public function isInternal(): bool
    {
        return $this->is(self::INTERNAL);
    }
}
