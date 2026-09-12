<?php

declare(strict_types=1);

namespace App\Exception;

final class AuthException extends AppException
{
    /** @param  array<string, mixed>  $details */
    public static function authFailed(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function authRequired(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function authExpired(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function twoFactorRequired(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function twoFactorInvalid(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function twoFactorAlreadyEnabled(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function setupExpired(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }
}
