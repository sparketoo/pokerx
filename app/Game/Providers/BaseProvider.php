<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Model\Game;

abstract class BaseProvider implements ProviderInterface
{
    public function start(Game $game): void {}

    public function stage(Game $game): void {}

    public function playerActed(Game $game): void {}

    public function knownPlayerCards(Game $game): void {}

    public function over(Game $game): void {}
}
