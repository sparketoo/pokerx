<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use Psr\Log\LoggerInterface;

use function App\Support\di;

abstract class BaseProvider implements ProviderInterface
{
    public function connect(GameServerConnectionVo $connection): void
    {
        //
    }

    public function disconnect(GameServerConnectionVo $connection): void
    {
        //
    }

    public function start(GameVo $game): void {}

    public function blindPosted(GameEventVo $event): void {}

    public function stage(GameEventVo $event): void {}

    public function dealt(GameEventVo $event): void {}

    public function action(GameEventVo $event): void {}

    public function show(GameEventVo $event): void {}

    public function abort(GameEventVo $event): void {}

    public function over(GameEventVo $event): void {}

    protected function logger(): LoggerInterface
    {
        return di(LoggerInterface::class);
    }
}
