<?php

declare(strict_types=1);

namespace App\Exception;

final class PokerException extends AppException
{
    /** @param  array<string, mixed>  $details */
    public static function handNotStarted(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function handClosed(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function insufficientPoints(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function solveInProgress(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function solveTimeout(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function solveStale(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function providerUnavailable(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    /** @param  array<string, mixed>  $details */
    public static function providerRejected(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }
}
