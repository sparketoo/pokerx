<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use App\Enum\ActionEnum;
use App\Exception\PokerException;
use App\Vo\Game\GameContextVo;
use App\Vo\Game\GetSolveVo;
use App\Vo\Game\PlayerVo;
use Closure;
use Swoole\Timer;

final class MockProvider extends BaseProvider
{
    public function __construct(private readonly int $delayMs = 50, private readonly bool $failure = false) {}

    private ?int $timer = null;

    public function getSolve(GameContextVo $context, Closure $completed): void
    {
        if ($this->timer !== null) {
            $completed(GetSolveVo::failure(PokerException::solveInProgress()));

            return;
        }
        $this->timer = Timer::after(max(1, $this->delayMs), function () use ($context, $completed) {
            $this->timer = null;
            if ($this->failure) {
                $completed(GetSolveVo::failure(PokerException::providerRejected(), '模拟供应方失败'));

                return;
            }
            $hero = $context->hero()->state;
            $call = $context->players->max(fn (PlayerVo $player) => $player->state->roundBet) - $hero->roundBet;
            $completed($call === 0 ? GetSolveVo::success(ActionEnum::CHECK,
                0) : ($call < $hero->remainingStack ? GetSolveVo::success(ActionEnum::CALL,
                    $call) : GetSolveVo::success(ActionEnum::ALL_IN, $hero->remainingStack)));
        });
    }

    public function close(): void
    {
        if ($this->timer) {
            Timer::clear($this->timer);
            $this->timer = null;
        }
    }
}
