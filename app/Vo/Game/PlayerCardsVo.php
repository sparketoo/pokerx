<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;
use Hyperf\Collection\Collection;

final class PlayerCardsVo extends Vo
{
    /** @param  Collection<int, string>  $cards */
    public function __construct(public readonly string $name, public readonly Collection $cards) {}
}
