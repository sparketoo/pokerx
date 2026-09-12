<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Poker\Providers\ProviderInterface;

final class GatewayState
{
    /** @var array<int,array{id:string, token: ?string, user: ?int, opened: int}> */
    public array $clients = [];

    /** @var array<string,ProviderInterface> */
    public array $providers = [];

    /** @var array<string, string> */
    public array $providerOwners = [];

    public function isCurrent(int $fd, string $generation): bool
    {
        return ($this->clients[$fd]['id'] ?? null) === $generation;
    }
}
