<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;

final class IdelProvider extends BaseProvider
{
    public function requestAction(GameVo $game, Closure $callback): void
    {
        $callback($this->actionFor($game));
    }

    private function actionFor(GameVo $game): RequestActionResultVo
    {
        $hero = $game->hero();
        foreach ($hero->events() as $event) {
            if ($event->type->isAction()) {
                return RequestActionResultVo::success(ActionEnum::FOLD, 0);
            }
        }

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
            if (! $event->type->isAction() || $event->player === null) {
                continue;
            }

            $uid = $event->player->uid;
            $action = ActionEnum::fromName($event->payload['action'] ?? null);
            if ($action === null || $action->isFold() || $action->isCheck()) {
                continue;
            }

            $amount = max(0, (int) ($event->payload['amount'] ?? 0));
            $paid = min($amount, $remainingStacks[$uid]);
            $roundBets[$uid] += $paid;
            $remainingStacks[$uid] -= $paid;
        }

        $highestRoundBet = $roundBets === [] ? 0 : max($roundBets);
        $call = max(0, $highestRoundBet - $roundBets[$hero->uid]);
        if ($call === 0) {
            return RequestActionResultVo::success(ActionEnum::CHECK, 0);
        }

        $remainingStack = $remainingStacks[$hero->uid];
        if ($call < $remainingStack) {
            return RequestActionResultVo::success(ActionEnum::CALL, $call);
        }

        return RequestActionResultVo::success(ActionEnum::ALL_IN, $remainingStack);
    }
}
