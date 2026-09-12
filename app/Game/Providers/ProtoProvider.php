<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Exception\PokerException;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Vo\Game\RequestActionResultVo;
use Carbon\CarbonImmutable;
use Hyperf\Stringable\Str;

final class ProtoProvider extends SocketJsonProvider
{
    protected function onOpen(): void
    {
        $this->send(['token' => $this->options['token'] ?? '', 'descr' => 'PokerX']);
    }

    protected function onMessage(array $message, int $opcode): void
    {
        if (empty($message['gameId']) || empty($message['structType'])) {
            return;
        }
        $type = $message['structType'];
        $method = 'handle'.Str::studly($type);
        if (! method_exists($this, $method)) {
            return;
        }

        $this->$method($message);
    }

    /**
     * @param  array<string,mixed>  $message
     */
    public function handlePlayerAction(array $message): void
    {
        if (! $this->hasRequestActionCallback($message['gameId']) || empty($message['action'])) {
            return;
        }

        if (isset($message['error'])) {
            $result = RequestActionResultVo::failure(PokerException::providerRejected());
            $this->callRequestActionCallback($message['gameId'], $result);

            return;
        }
        $action = match ($message['action']) {
            'fold' => ActionEnum::FOLD,
            'call' => ActionEnum::CALL,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            'check' => ActionEnum::CHECK,
            default => null,
        };
        if (empty($action)) {
            return;
        }

        $this->callRequestActionCallback(
            $message['gameId'],
            RequestActionResultVo::success($action, $message['amount'] ?? 0),
        );
    }

    public function requestAction(Game $game, \Closure $callback): void
    {
        if ($this->hasRequestActionCallback($game->uuid)) {
            throw PokerException::solveInProgress();
        }
        $this->setRequestActionCallback($game->uuid, $callback);
        $this->send($this->gameEvents($game));
        $this->send([
            'structType' => 'getAnwser',
            'gameId' => $game->uuid,
            'potForAlpha' => $game->getAllPot(),
            'delay' => (int) ($this->options['delay'] ?? 9000),
        ]);
    }

    public function over(Game $game): void
    {
        $this->send($this->gameEvents($game, true));
    }

    /** @return array<string, mixed> */
    public function gameEvents(Game $game, bool $over = false): array
    {
        $events = [];
        foreach ($game->players as $player) {
            $events[] = [
                'eventType' => 'playerSeated',
                'seat' => $player->seat,
                'name' => $player->name,
                'stack' => $player->stack,
            ];
        }
        foreach ($game->players as $player) {

            if ($player->seat_type->isBlind()) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $player->name,
                    'blindType' => $player->seat_type->name,
                    'amount' => $player->blind_amount,
                ];
            }
        }

        foreach ($game->events as $event) {
            $payload = $event->payload;
            switch ($event->type) {
                case GameEvent::GAME_STAGE:
                    $stage = StageEnum::fromNameOrFail(strtoupper($event->payload['stage']));
                    $events[] = [
                        'eventType' => 'stageStarted',
                        'stage' => strtolower($stage->name),
                        'cards' => implode(',', $event->payload['cards'] ?? []),
                    ];
                    if ($stage->isPreflop()) {
                        $events[] = [
                            'eventType' => 'handDealt',
                            'name' => $game->hero()->name,
                            'cards' => implode(',', $game->hero()->cards ?? []),
                        ];
                    }
                    break;
                case GameEvent::GAME_PLAY_ACTED:
                    $action = ActionEnum::fromNameOrFail(strtoupper($payload['action']));
                    $events[] = [
                        'eventType' => 'playerActed',
                        'name' => $payload['name'],
                        'action' => $action->wire(),
                        'amount' => $payload['amount'],
                    ];
                    break;
                case GameEvent::GAME_KNOWN_PLAY_CARDS:
                    $events[] = [
                        'eventType' => 'knownPlayerCards',
                        'name' => $payload['name'],
                        'cards' => implode(',', $payload['cards'] ?? []),
                    ];
                    break;
                case GameEvent::GAME_OVER:
                    if ($over) {
                        foreach ($game->players as $player) {
                            if (! empty($player->cards)) {
                                $events[] = [
                                    'eventType' => 'handShown',
                                    'name' => $player->name,
                                    'cards' => implode(',', $player->cards),
                                ];
                            }

                        }
                        $events[] = [
                            'eventType' => 'playerWon',
                            'name' => $payload['winner']['name'] ?? '',
                            'amount' => $payload['winner']['amount'] ?? 0,
                        ];
                        $events[] = ['eventType' => 'gameOver'];
                    }
                    break;
            }
        }

        $button = $game->players->first(function (GamePlayer $player) use ($game) {
            if ($game->players->count() === 2) {
                return $player->seat_type->isSb();
            }

            return $player->seat_type->isBtn();
        })?->seat;

        return [
            'structType' => $over ? 'fullGameLog' : 'gameEvents',
            'game' => [
                'gameId' => $game->uuid,
                'pokerNetwork' => $this->options['network'] ?? 'WE',
                'gameType' => 'NL',
                'bigBlind' => $game->big_blind,
                'ante' => $game->ante,
                'currency' => 'USDT',
                'gameDate' => (string) CarbonImmutable::parse($game->created_at, 'UTC')->getTimestampMs(),
                'numPlayers' => count($game->players),
                'buttonSetToSeat' => $button,
            ],
            'events' => $events,
        ];
    }
}
