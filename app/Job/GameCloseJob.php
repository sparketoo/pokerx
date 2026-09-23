<?php

declare(strict_types=1);

namespace App\Job;

use App\Enum\GameStatusEnum;
use App\Exception\GameException;
use App\Service\GameService;
use Hyperf\AsyncQueue\Job;
use function App\Support\di;

/**
 * 某些情况下，客户端没有上报OVER或ABORT事件，需要兜底处理，兜底处理时，games状态为CLOSED
 */
class GameCloseJob extends Job
{
    public function __construct(public readonly string $uuid) {}

    public function handle()
    {
        try {
            $game = di(GameService::class)->find($this->uuid);
        } catch (GameException $e) {
            // 游戏不存在时说明已经被处理，可以忽略
            return;
        }

        $game->status = GameStatusEnum::CLOSED;
        di(GameService::class)->store($game);
    }
}
