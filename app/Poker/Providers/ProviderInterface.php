<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use App\Vo\Game\GameContextVo;
use App\Vo\Game\GetSolveVo;
use Closure;

interface ProviderInterface
{
    public function start(GameContextVo $context): void;

    public function stageStarted(GameContextVo $context): void;

    public function playerActed(GameContextVo $context): void;

    public function KnownPlayerCards(GameContextVo $context): void;

    /** @param  Closure(GetSolveVo): void  $completed */
    public function getSolve(GameContextVo $context, Closure $completed): void;

    public function over(GameContextVo $context): void;

    public function close(): void;
}
