<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Model\Game;
use Closure;

interface ProviderInterface
{
    public function start(Game $game): void;

    public function stage(Game $game): void;

    public function playerActed(Game $game): void;

    public function knownPlayerCards(Game $game): void;

    public function requestAction(Game $game, Closure $callback): void;

    public function abort(Game $game): void;

    public function over(Game $game, ?Closure $onError = null): void;
}
