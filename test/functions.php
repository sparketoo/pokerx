<?php

declare(strict_types=1);

namespace Tests;

/** @param callable(): mixed $callback */
function run(callable $callback): void
{
    $error = null;
    \Hyperf\Coroutine\run(function () use ($callback, &$error): void {
        try {
            $callback();
        } catch (\Throwable $caught) {
            $error = $caught;
        }
    });
    if ($error !== null) {
        throw $error;
    }
}
