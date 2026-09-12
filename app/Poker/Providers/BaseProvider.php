<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use App\Vo\Game\GameContextVo;

abstract class BaseProvider implements ProviderInterface
{
    public function start(GameContextVo $context): void {}

    public function stageStarted(GameContextVo $context): void {}

    public function playerActed(GameContextVo $context): void {}

    public function KnownPlayerCards(GameContextVo $context): void {}

    public function over(GameContextVo $context): void {}

    public function close(): void {}
}
