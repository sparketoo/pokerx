<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Exception\PokerException;
use App\Model\Game;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Swoole\Timer;

final class MockProvider extends BaseProvider
{
    public function __construct(private readonly int $delayMs = 50, private readonly bool $failure = false) {}

    /** @var array<string, int> */
    private array $timers = [];

    public function requestAction(Game $game, Closure $callback): void
    {
        if (isset($this->timers[$game->uuid])) {
            $callback(RequestActionResultVo::failure(PokerException::solveInProgress()));

            return;
        }
        $ms = random_int(100, max(100, $this->delayMs));
        $this->timers[$game->uuid] = Timer::after($ms, function () use ($game, $callback) {
            unset($this->timers[$game->uuid]);
            if ($this->failure) {
                $callback(RequestActionResultVo::failure(PokerException::providerRejected(), '模拟供应方失败'));

                return;
            }
            $callback($this->actionFor($game));
        });
    }

    public function over(Game $game): void
    {
        $timer = $this->timers[$game->uuid] ?? null;
        if ($timer === null) {
            return;
        }

        Timer::clear($timer);
        unset($this->timers[$game->uuid]);
    }

    private function actionFor(Game $game): RequestActionResultVo
    {
        $roundBets = [];
        $remainingStacks = [];
        foreach ($game->players as $player) {
            $ante = min($player->stack, $game->ante);
            $blind = min($player->stack - $ante, $player->blind_amount ?? 0);
            $roundBets[$player->name] = $blind;
            $remainingStacks[$player->name] = $player->stack - $ante - $blind;
        }

        foreach ($game->events->sortBy('seq') as $event) {
            if ($event->type === GameEvent::GAME_STAGE) {
                if (($event->payload['stage'] ?? null) !== 'preflop') {
                    $roundBets = array_fill_keys(array_keys($roundBets), 0);
                }

                continue;
            }
            if ($event->type !== GameEvent::GAME_PLAY_ACTED) {
                continue;
            }

            $name = $event->payload['name'] ?? null;
            $action = is_string($event->payload['action'] ?? null)
                ? ActionEnum::fromName(strtoupper($event->payload['action']))
                : null;
            if (! is_string($name) || $action === null || ! isset($roundBets[$name])) {
                continue;
            }

            $amount = (int) ($event->payload['amount'] ?? 0);
            $toCall = max($roundBets) - $roundBets[$name];
            $paid = match ($action) {
                ActionEnum::FOLD, ActionEnum::CHECK => 0,
                ActionEnum::CALL, ActionEnum::BET => $amount,
                ActionEnum::RAISE => $toCall + $amount,
                ActionEnum::ALL_IN => $remainingStacks[$name],
            };
            $paid = min(max(0, $paid), $remainingStacks[$name]);
            $roundBets[$name] += $paid;
            $remainingStacks[$name] -= $paid;
        }

        $hero = $game->hero();
        $call = max($roundBets) - $roundBets[$hero->name];
        $remainingStack = $remainingStacks[$hero->name];

        return $call === 0
            ? RequestActionResultVo::success(ActionEnum::CHECK, 0)
            : ($call < $remainingStack
                ? RequestActionResultVo::success(ActionEnum::CALL, (int) $call)
                : RequestActionResultVo::success(ActionEnum::ALL_IN, (int) $remainingStack));
    }
}
