<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\StageEnum;
use Hyperf\Collection\Collection;

final class StageStartedVo extends EventPayloadVo
{
    /** @param  Collection<int, string>  $cards */
    public function __construct(
        public readonly string $handId,
        public readonly StageEnum $stage,
        public readonly Collection $cards
    ) {}

}
