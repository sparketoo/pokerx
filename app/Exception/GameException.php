<?php

declare(strict_types=1);

namespace App\Exception;

final class GameException extends AppException
{
    /** @param  array<string, mixed>  $details */
    public static function gameAlreadyExists(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    public static function heroNotFound(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function invalidPlayerActed(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function invalidGameOver(): self
    {
        return self::create(__FUNCTION__);
    }
}
