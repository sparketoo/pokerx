<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use App\Enum\ActionEnum;
use App\Exception\PokerException;
use App\Vo\Game\GameContextVo;
use App\Vo\Game\GameOverVo;
use App\Vo\Game\GetSolveRequestVo;
use App\Vo\Game\GetSolveVo;
use App\Vo\Game\KnownPlayerCardsVo;
use App\Vo\Game\PlayerActedVo;
use App\Vo\Game\StageStartedVo;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use LogicException;
use Throwable;

final class ProtoProvider extends SocketProvider
{
    /**
     * @return array<string, mixed>
     */
    protected function authentication(): array
    {
        return ['token' => $this->options['token'] ?? '', 'descr' => 'PokerX'];
    }

    public function start(GameContextVo $context): void
    {
        $this->context = $context;
        $this->ensureConnected();
    }

    public function stageStarted(GameContextVo $context): void
    {
        $this->sync($context);
    }

    public function playerActed(GameContextVo $context): void
    {
        $this->sync($context);
    }

    public function KnownPlayerCards(GameContextVo $context): void
    {
        if ($context->game->stage !== null) {
            $this->sync($context);
        } else {
            $this->context = $context;
        }
    }

    private function sync(GameContextVo $context): void
    {
        $this->context = $context;
        $this->send($this->history($context));
    }

    public function getSolve(GameContextVo $context, Closure $completed): void
    {
        $this->context = $context;
        try {
            $this->send($this->history($context));
            $p = $context->events->last()?->payload;
            if (! $p instanceof GetSolveRequestVo) {
                throw new LogicException('Missing solve request');
            }

            $this->request([
                'structType' => 'getAnswer', 'gameId' => $context->game->id, 'potForAlpha' => $p->potForAlpha,
                'delay' => $p->delay ?? 9000,
            ], ($p->delay ?? 9000) + (int) (($this->options['timeout_margin'] ?? 5) * 1000), $completed);
        } catch (Throwable) {
            $completed(GetSolveVo::failure(PokerException::providerUnavailable()));
        }
    }

    public function over(GameContextVo $context): void
    {
        $this->context = $context;
        $this->sendFinal($this->history($context, true));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function decodeSolve(array $message): ?GetSolveVo
    {
        if (isset($message['error'])) {
            return GetSolveVo::failure(PokerException::providerRejected());
        } // Raw reason stays in redacted provider log.
        if (($message['structType'] ?? null) !== 'playerAction') {
            return null;
        }
        if (($message['gameId'] ?? null) !== $this->context?->game->id || ($message['name'] ?? null) !== $this->context?->hero()->name) {
            return null;
        }
        $action = is_string($message['action'] ?? null) ? ActionEnum::fromName(strtoupper(str_replace('-', '_',
            $message['action']))) : null;
        if (! $action || ! is_int($message['amount'] ?? null)) {
            return GetSolveVo::failure(PokerException::providerRejected());
        }
        try {
            return GetSolveVo::success($action, $message['amount']);
        } catch (InvalidArgumentException) {
            return GetSolveVo::failure(PokerException::providerRejected());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function history(GameContextVo $context, bool $over = false): array
    {
        $button = null;
        $events = [];
        foreach ($context->players as $player) {
            if ($player->seatType->name === (count($context->players) === 2 ? 'SB' : 'BTN')) {
                $button = $player->seat;
            }
            $events[] = [
                'eventType' => 'playerSeated', 'seat' => $player->seat, 'name' => $player->name,
                'stack' => $player->stack,
            ];
        }
        foreach ($context->players as $player) {

            if (in_array($player->seatType->name, ['SB', 'BB'], true)) {
                $events[] = [
                    'eventType' => 'blindPosted', 'name' => $player->name, 'blindType' => $player->seatType->name,
                    'amount' => $player->blindAmount,
                ];
            }
        }
        foreach ($context->events as $event) {
            $p = $event->payload;
            switch (true) {
                case $p instanceof StageStartedVo:
                    $events[] = [
                        'eventType' => 'stageStarted', 'stage' => $p->stage->wire(), 'cards' => $p->cards->implode(','),
                    ];
                    if ($p->stage->isPreflop()) {
                        $events[] = [
                            'eventType' => 'handDealt', 'name' => $context->hero()->name,
                            'cards' => $context->hero()->cards->implode(','),
                        ];
                    }
                    break;
                case $p instanceof PlayerActedVo:
                    $events[] = [
                        'eventType' => 'playerActed', 'name' => $p->name,
                        'action' => str_replace('_', '-', $p->action->wire()), 'amount' => $p->amount,
                    ];
                    break;
                case $p instanceof KnownPlayerCardsVo:
                    $events[] = [
                        'eventType' => 'knownPlayerCards', 'name' => $p->name, 'cards' => $p->cards->implode(','),
                    ];
                    break;
                case $p instanceof GameOverVo:
                    if ($over) {
                        foreach ($p->shown as $shown) {
                            $events[] = [
                                'eventType' => 'handShown', 'name' => $shown->name,
                                'cards' => $shown->cards->implode(','),
                            ];
                        }
                        $events[] = [
                            'eventType' => 'playerWon', 'name' => $p->winner->name,
                            'amount' => $p->winner->amount,
                        ];
                        $events[] = ['eventType' => 'gameOver'];
                    }
                    break;
            }
        }

        return [
            'structType' => $over ? 'fullGameLog' : 'gameEvents',
            'game' => [
                'gameId' => $context->game->id,
                'pokerNetwork' => $this->options['network'] ?? 'WE',
                'gameType' => 'NL',
                'bigBlind' => $context->game->bigBlind,
                'ante' => $context->game->ante,
                'currency' => 'USDT',
                'gameDate' => (string) CarbonImmutable::parse($context->game->createdAt, 'UTC')->getTimestampMs(),
                'numPlayers' => count($context->players),
                'buttonSetToSeat' => $button,
            ],
            'events' => $events,
        ];
    }
}
