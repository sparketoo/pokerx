<?php

declare(strict_types=1);

namespace App\Vo\Game;

use Hyperf\Collection\Collection;

final class GameOverVo extends EventPayloadVo
{
    /** @param  Collection<int, PlayerCardsVo>  $shown */
    public function __construct(
        public readonly string $handId,
        public readonly Collection $shown,
        public readonly WinnerVo $winner
    ) {}

}
