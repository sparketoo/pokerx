<?php

declare(strict_types=1);

namespace App\Exception;

final class FoundationException extends AppException
{
    /** @param  array<string, mixed>  $details */
    public static function storageUnavailable(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details)->error();
    }

    public static function dataNotFound(): self
    {
        return self::create(__FUNCTION__);
    }

    /** @param  array<string, mixed>  $details */
    public static function notFound(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function rateLimited(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function serverError(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details)->error();
    }
}
