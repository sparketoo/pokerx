<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;

final class PlayerStateVo extends Vo
{
    public function __construct(
        public readonly int $remainingStack,
        public readonly int $roundBet,
        public readonly int $invested,
        public readonly bool $folded,
        public readonly bool $allIn
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['remaining_stack'], $data['round_bet'], $data['invested'], $data['folded'],
            $data['all_in']);
    }
}
