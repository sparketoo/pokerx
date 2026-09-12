<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Vo\Vo;

final class WinnerVo extends Vo
{
    public function __construct(public readonly string $name, public readonly int $amount) {}
}
