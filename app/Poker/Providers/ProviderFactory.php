<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use Closure;

final class ProviderFactory
{
    /** @param  Closure(?Closure, array<string, mixed>): ProviderInterface  $factory */
    public function __construct(private readonly Closure $factory) {}

    /** @param  array<string, mixed>  $options */
    public function create(?Closure $logger = null, array $options = []): ProviderInterface
    {
        return ($this->factory)($logger, $options);
    }
}
