<?php

declare(strict_types=1);

namespace App\Vo\Game;

final class GetSolveRequestVo extends EventPayloadVo
{
    public function __construct(
        public readonly string $handId,
        public readonly int $potForAlpha,
        public readonly ?int $delay
    ) {}

    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();
        if ($this->delay === null) {
            unset($data['delay']);
        }

        return $data;
    }
}
