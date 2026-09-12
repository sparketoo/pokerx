<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\SeatTypeEnum;
use App\Vo\Vo;
use Hyperf\Collection\Collection;

final class PlayerVo extends Vo
{
    /** @param  Collection<int, string>  $cards */
    public function __construct(
        public readonly int $seat,
        public readonly string $name,
        public readonly bool $isHero,
        public readonly int $stack,
        public readonly SeatTypeEnum $seatType,
        public readonly ?int $blindAmount,
        public readonly int $ante,
        public readonly Collection $cards,
        public readonly PlayerStateVo $state
    ) {}

    /** @return array{seat: int, name: string, hero: bool, stack: int, seat_type: string, amount: ?int} */
    public function initialState(): array
    {
        return [
            'seat' => $this->seat, 'name' => $this->name, 'hero' => $this->isHero, 'stack' => $this->stack,
            'seat_type' => $this->seatType->name, 'amount' => $this->blindAmount,
        ];
    }
}
