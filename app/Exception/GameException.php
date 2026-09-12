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
}
