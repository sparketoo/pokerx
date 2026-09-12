<?php

declare(strict_types=1);

namespace App\Exception;

final class GatewayException extends AppException
{
    /** @param  array<string, mixed>  $details */
    public static function eventInvalid(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function eventSequenceGap(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function eventConflict(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function gameInUse(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function stateRecovering(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }
}
