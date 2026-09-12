<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;

final class GameEventVo extends Vo
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $seq,
        public readonly EventPayloadVo $payload,
        public readonly string $createdAt
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['type'], $data['seq'],
            EventPayloadVo::fromEvent($data['type'], $data['payload']), $data['created_at']);
    }
}
