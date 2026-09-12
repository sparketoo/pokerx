<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;

final class PendingSolveVo extends Vo
{
    public function __construct(
        public readonly string $requestId,
        public readonly int $revision,
        public readonly float $deadline,
        public readonly int $reservedCost
    ) {}
}
