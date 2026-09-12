<?php

declare(strict_types=1);

namespace App\Vo\Game;

use Hyperf\Collection\Collection;

final class KnownPlayerCardsVo extends EventPayloadVo
{
    /** @param  Collection<int, string>  $cards */
    public function __construct(
        public readonly string $handId,
        public readonly string $name,
        public readonly Collection $cards
    ) {}

}
