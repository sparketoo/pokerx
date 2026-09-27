<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use Hyperf\Context\ApplicationContext;
use InvalidArgumentException;

use function Hyperf\Support\env;

/**
 * @template T of object
 *
 * @param  class-string<T>  $id
 * @return T
 */
function di(string $id): object
{
    return ApplicationContext::getContainer()->get($id);
}

function appTimezone(): string
{
    $timezone = env('APP_TIMESTAMP');
    if (! is_string($timezone) || $timezone === '') {
        throw new InvalidArgumentException('APP_TIMESTAMP must be a non-empty timezone');
    }

    return $timezone;
}

function appTimezoneOffset(): string
{
    $timezone = new DateTimeZone(appTimezone());

    return (new DateTimeImmutable('now', $timezone))->format('P');
}

function now(?string $timezone = null): Carbon
{
    return Carbon::now($timezone);
}
