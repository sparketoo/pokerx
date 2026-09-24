<?php

declare(strict_types=1);

namespace App\Exception;

final class GameException extends AppException
{
    public static function gameAlreadyExists(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function gameUuidNotFound(string $uuid): self
    {
        return self::create(__FUNCTION__)->withDetails(['uuid' => $uuid]);
    }

    /** @param  array<string, mixed>  $details */
    public static function eventInvalid(array $details = []): self
    {
        return self::create(__FUNCTION__)->withDetails($details);
    }

    public static function eventTypeInvalid(string $type): self
    {
        return self::create(__FUNCTION__, ['type' => $type]);
    }

    public static function cardInvalid(string $card): self
    {
        return self::create(__FUNCTION__, ['card' => $card]);
    }

    public static function requestActionInProgress(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function providerUnavailable(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function actionAmountInvalid(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function heroNotFound(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function bigBlindNotFound(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function playerNotFound(string $name): self
    {
        return self::create(__FUNCTION__)->withDetails(['name' => $name]);
    }

    public static function playersEmpty(): self
    {
        return self::create(__FUNCTION__);
    }

    public static function providerFailed(string $reason): self
    {
        return self::create(__FUNCTION__, ['reason' => $reason]);
    }
}
