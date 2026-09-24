<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Exception\GameException;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Swoole\Timer;

final class MockProvider extends BaseProvider
{
    public function __construct(private readonly int $delayMs = 50, private readonly bool $failure = false) {}

    /** @var array<string, int> */
    private array $timers = [];

    public function requestAction(GameVo $game, Closure $callback): void
    {
        if (isset($this->timers[$game->uuid])) {
            $callback(RequestActionResultVo::failure(GameException::requestActionInProgress()));

            return;
        }
        $ms = max(1, $this->delayMs);
        $this->timers[$game->uuid] = Timer::after($ms, function () use ($game, $callback) {
            unset($this->timers[$game->uuid]);
            if ($this->failure) {
                $callback(RequestActionResultVo::failure(GameException::providerFailed('testing')));

                return;
            }
            $callback($this->actionFor($game));
        });
    }

    public function abort(GameEventVo $event): void
    {
        $this->stage($event);
    }

    public function over(GameEventVo $event): void
    {
        $this->stage($event);
    }

    public function stage(GameEventVo $event): void
    {
        $timer = $this->timers[$event->game->uuid] ?? null;
        if ($timer === null) {
            return;
        }

        Timer::clear($timer);
        unset($this->timers[$event->game->uuid]);
    }

    private function actionFor(GameVo $game): RequestActionResultVo
    {
        $roundBets = [];
        $remainingStacks = [];
        foreach ($game->players as $player) {
            $ante = min($player->stack, $player->ante);
            $blind = min($player->stack - $ante, $player->blind);
            $postBlind = min($player->stack - $ante - $blind, $player->postBlind);
            $straddle = min($player->stack - $ante - $blind - $postBlind, $player->straddleBlind);
            $roundBets[$player->uid] = $blind + $postBlind + $straddle;
            $remainingStacks[$player->uid] = $player->stack - $ante - $blind - $postBlind - $straddle;
        }

        foreach ($game->events->sortBy('timestamp') as $event) {
            if ($event->type->isStage()) {
                if (StageEnum::fromName($event->payload['stage'] ?? null) !== StageEnum::PREFLOP) {
                    $roundBets = array_fill_keys(array_keys($roundBets), 0);
                }

                continue;
            }
            if (! $event->type->isAction()) {
                continue;
            }

            $uid = $event->payload['uid'] ?? null;
            if (! is_string($uid) || ! array_key_exists($uid, $remainingStacks)) {
                continue;
            }
            $action = ActionEnum::fromName($event->payload['action'] ?? null);
            if ($action === null || $action->isFold() || $action->isCheck()) {
                continue;
            }

            $amount = max(0, (int) ($event->payload['amount'] ?? 0));
            $paid = min($amount, $remainingStacks[$uid]);
            $roundBets[$uid] += $paid;
            $remainingStacks[$uid] -= $paid;
        }

        $heroUid = $game->hero()->uid;
        $highestRoundBet = $roundBets === [] ? 0 : max($roundBets);
        $call = max(0, $highestRoundBet - $roundBets[$heroUid]);
        $remainingStack = $remainingStacks[$heroUid];

        if ($call === 0) {
            return RequestActionResultVo::success(ActionEnum::CHECK, 0);
        }
        if ($call < $remainingStack) {
            return RequestActionResultVo::success(ActionEnum::CALL, $call);
        }

        return RequestActionResultVo::success(ActionEnum::ALL_IN, $remainingStack);
    }
}
