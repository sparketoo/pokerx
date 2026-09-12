<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Hyperf\Context\ApplicationContext;

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

function now(?string $timezone = 'UTC'): Carbon
{
    return Carbon::now($timezone);
}
