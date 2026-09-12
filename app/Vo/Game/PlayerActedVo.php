<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\ActionEnum;

final class PlayerActedVo extends EventPayloadVo
{
    public function __construct(
        public readonly string $handId,
        public readonly string $name,
        public readonly ActionEnum $action,
        public readonly int $amount
    ) {}

}
