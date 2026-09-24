<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use Closure;

interface ProviderInterface
{
    public function start(GameVo $game): void;

    public function postBlind(GameEventVo $event): void;

    public function dealt(GameEventVo $event): void;

    public function stage(GameEventVo $event): void;

    public function action(GameEventVo $event): void;

    public function show(GameEventVo $event): void;

    public function requestAction(GameVo $game, Closure $callback): void;

    public function abort(GameEventVo $event): void;

    public function over(GameEventVo $event): void;
}
