<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;

class GameServerMessageVo extends Vo
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly GameServerConnectionVo $connection,
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $timestamp,
    ) {}
}
