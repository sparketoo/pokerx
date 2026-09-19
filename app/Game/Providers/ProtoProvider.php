<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Exception\GatewayException;
use App\Exception\PokerException;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Vo\Game\RequestActionResultVo;
use Carbon\CarbonImmutable;
use Hyperf\Stringable\Str;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;

final class ProtoProvider extends SocketJsonProvider
{
    private bool $authenticated = false;

    private ?string $sessionId = null;

    private int $frameSequence = 0;

    private ?string $connectionId = null;

    /** @var array<string, array{callback: \Closure(string): void, timer: int}> */
    private array $settlements = [];

    /**
     * Sent on this connection, not acknowledged by the upstream.
     *
     * @var array<string, \stdClass&object{sent: bool}>
     */
    private array $sentGames = [];

    protected function onOpen(): void
    {
        $this->authenticated = false;
        $this->sentGames = [];
        $this->connectionId = (string) Str::uuid();
        $this->frameSequence = 0;
        $this->logger()->info('Proto connection opened', ['connection_id' => $this->connectionId]);
        $authentication = array_filter([
            'token' => $this->options['token'] ?? '',
            'sessionId' => $this->sessionId,
            'descr' => 'PokerX',
        ], static fn (?string $value): bool => $value !== null);
        if (! $this->send($authentication)) {
            throw new \RuntimeException('Proto authentication could not be sent');
        }
    }

    protected function onMessage(array $message, int $opcode): void
    {
        if (isset($message['gameId'])) {
            $message['gameId'] = $this->internalGameId((string) $message['gameId']);
        }
        if (array_key_exists('result', $message)) {
            $this->authenticated = ($message['result'] ?? false) === true;
            if ($this->authenticated) {
                $this->sessionId = isset($message['sessionId']) ? (string) $message['sessionId'] : null;
            }

            return;
        }
        if (isset($message['error'])) {
            $gameId = isset($message['gameId']) ? (string) $message['gameId'] : null;
            if ($gameId !== null) {
                unset($this->sentGames[$gameId]);
            }
            if ($gameId !== null && isset($this->settlements[$gameId])) {
                $this->rejectSettlement($gameId, (string) $message['error']);
            } elseif ($gameId !== null && $this->hasRequestActionCallback($gameId)) {
                $this->callRequestActionCallback($gameId, RequestActionResultVo::failure(PokerException::providerRejected(),
                    is_string($message['error']) ? $message['error'] : null));
            }

            return;
        }
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
        if (! $this->hasRequestActionCallback($message['gameId'])) {
            return;
        }

        if (isset($message['error'])) {
            $result = RequestActionResultVo::failure(PokerException::providerRejected(),
                is_string($message['error']) ? $message['error'] : null);
            $this->callRequestActionCallback($message['gameId'], $result);

            return;
        }
        if (! is_string($message['action'] ?? null) || $message['action'] === '') {
            $this->callRequestActionCallback($message['gameId'], RequestActionResultVo::failure(PokerException::providerRejected()));

            return;
        }
        // Live Proto responses use both all-in and all-In; keep our action names canonical.
        $action = match (strtolower($message['action'])) {
            'fold' => ActionEnum::FOLD,
            'call' => ActionEnum::CALL,
            'bet' => ActionEnum::BET,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            'check' => ActionEnum::CHECK,
            default => null,
        };
        if ($action === null) {
            $this->callRequestActionCallback($message['gameId'], RequestActionResultVo::failure(PokerException::providerRejected()));

            return;
        }

        try {
            $result = RequestActionResultVo::success($action, $message['amount'] ?? 0);
        } catch (\InvalidArgumentException|\TypeError) {
            $result = RequestActionResultVo::failure(PokerException::providerRejected());
        }
        $this->callRequestActionCallback($message['gameId'], $result);
    }

    public function stage(Game $game): void
    {
        if (! $game->status->isOpen()) {
            throw GatewayException::eventInvalid();
        }
        if (! $this->awaitAuthenticated((float) ($this->options['connect_timeout'] ?? 10))) {
            throw PokerException::providerUnavailable();
        }
        if (! $this->sendGameEvents($game)) {
            throw PokerException::providerUnavailable();
        }
    }

    /** Publish a complete prefix; a close/rejection during the write invalidates the marker. */
    private function sendGameEvents(Game $game): bool
    {
        $message = $this->gameEvents($game);
        $marker = $this->sentGames[$game->uuid] ??= (object) ['sent' => false];
        // Eviction only causes a safe full-prefix synchronization before settlement.
        if (count($this->sentGames) > 1024) {
            unset($this->sentGames[array_key_first($this->sentGames)]);
        }
        $sent = $this->send($message);
        if (! $sent || ! $this->authenticated || ($this->sentGames[$game->uuid] ?? null) !== $marker) {
            if (($this->sentGames[$game->uuid] ?? null) === $marker) {
                unset($this->sentGames[$game->uuid]);
            }

            return false;
        }

        $marker->sent = true;

        return true;
    }

    public function requestAction(Game $game, \Closure $callback): void
    {
        if ($this->hasRequestActionCallback($game->uuid)) {
            throw PokerException::solveInProgress();
        }
        $this->setRequestActionCallback($game->uuid, $callback);
        if (! $this->awaitAuthenticated((float) ($this->options['connect_timeout'] ?? 10))) {
            $this->callRequestActionCallback($game->uuid, RequestActionResultVo::failure(PokerException::providerRejected()));

            return;
        }
        if (($this->requestActionCallbacks[$game->uuid] ?? null) !== $callback) {
            return;
        }
        $synced = $this->sendGameEvents($game);
        $requested = $synced && $this->send([
            'structType' => 'getAnswer',
            'gameId' => $this->externalGameId($game),
            'potForAlpha' => $game->pot,
            'delay' => (int) ($this->options['delay'] ?? 9000),
        ]);
        if (! $requested) {
            $this->callRequestActionCallback($game->uuid, RequestActionResultVo::failure(PokerException::providerRejected()));

            return;
        }
    }

    /** One record per wire frame; business handlers never repeat the payload. */
    protected function onFrame(string $direction, string $data, int $opcode, ?bool $sent = null): void
    {
        $context = [
            'connection_id' => $this->connectionId,
            'sequence' => ++$this->frameSequence,
            'direction' => $direction,
            'opcode' => $opcode,
            'bytes' => strlen($data),
        ];
        if ($sent !== null) {
            $context['sent'] = $sent;
        }
        $message = $opcode === 1 ? json_decode($data, true) : null;
        if (is_array($message)) {
            $context['message'] = $this->redact($message);
        } elseif ($opcode === 1) {
            // Never log unknown raw bytes: malformed authentication may contain secrets.
            $context['invalid_json'] = true;
        }
        $level = $sent === false || (is_array($message) && (isset($message['error']) || ($message['result'] ?? null) === false))
            ? 'warning' : (in_array($opcode, [9, 10], true) ? 'debug' : 'info');
        $this->logger()->log($level, 'Proto frame', $context);
    }

    /** @param array<array-key, mixed> $message
     * @return array<array-key, mixed>
     */
    private function redact(array $message): array
    {
        foreach ($message as $key => $value) {
            if (in_array(strtolower((string) $key), ['token', 'sessionid', 'authorization', 'password', 'secret'], true)) {
                $message[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $message[$key] = $this->redact($value);
            }
        }

        return $message;
    }

    protected function onError(Throwable $error): void
    {
        $this->logger()->warning('Proto provider connection error', [
            'connection_id' => $this->connectionId,
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ]);
    }

    protected function onClose(): void
    {
        $this->logger()->info('Proto connection closed', ['connection_id' => $this->connectionId]);
        $this->authenticated = false;
        $this->sentGames = [];
        foreach (array_keys($this->settlements) as $gameId) {
            $this->rejectSettlement($gameId, 'Connection closed; settlement delivery is unknown');
        }
    }

    private function awaitAuthenticated(float $timeout): bool
    {
        if (! $this->awaitConnection($timeout)) {
            return false;
        }
        $deadline = microtime(true) + max(0, $timeout);
        while (! $this->authenticated && microtime(true) < $deadline) {
            Coroutine::sleep(0.01);
        }

        return $this->authenticated;
    }

    public function abort(Game $game): void
    {
        unset($this->sentGames[$game->uuid]);
        $this->callRequestActionCallback($game->uuid, RequestActionResultVo::failure(PokerException::solveStale()));
    }

    /** The callback reports failures only: Proto never acknowledges successful fullGameLog. */
    public function over(Game $game, ?\Closure $onError = null): void
    {
        if ($game->status->isAbort()) {
            throw GatewayException::eventInvalid();
        }
        // Errors carry only gameId; retire any solve before the settlement phase.
        $this->callRequestActionCallback($game->uuid, RequestActionResultVo::failure(PokerException::solveStale()));
        if (! $this->awaitAuthenticated((float) ($this->options['connect_timeout'] ?? 10))) {
            throw PokerException::providerUnavailable();
        }
        $gameId = $game->uuid;
        if ($onError !== null) {
            if (isset($this->settlements[$game->uuid])) {
                Timer::clear($this->settlements[$game->uuid]['timer']);
            }
            // Bound retained callbacks; expiry is NOT a confirmation of success.
            $this->settlements[$game->uuid] = [
                'callback' => $onError,
                'timer' => Timer::after(60000, function () use ($gameId): void {
                    unset($this->settlements[$gameId]);
                }),
            ];
        }
        if (! ($this->sentGames[$game->uuid]->sent ?? false) && ! $this->sendGameEvents($game)) {
            $this->rejectSettlement($game->uuid, 'Pre-settlement synchronization could not be sent');
            throw PokerException::providerUnavailable();
        }
        if (! $this->send($this->gameEvents($game, true))) {
            $this->rejectSettlement($game->uuid, 'Settlement could not be sent');
            throw PokerException::providerUnavailable();
        }
        unset($this->sentGames[$game->uuid]);
    }

    private function rejectSettlement(string $gameId, string $reason): void
    {
        $pending = $this->settlements[$gameId] ?? null;
        if ($pending === null) {
            return;
        }
        unset($this->settlements[$gameId]);
        Timer::clear($pending['timer']);
        ($pending['callback'])($reason);
    }

    private function externalGameId(Game $game): string
    {
        // Live WE settlement parsing requires a millisecond timestamp followed by a
        // numeric identifier before the underscore (verified by historical replay).
        // PROTOCOL.md documents only uniqueness; keep this quirk inside the adapter.
        $timestamp = CarbonImmutable::parse($game->created_at, 'UTC')->getTimestampMs();

        return $timestamp.$game->id.'_'.$game->uuid;
    }

    private function internalGameId(string $gameId): string
    {
        return preg_match('/^\d{14,}_([0-9a-f-]{36})$/D', $gameId, $matches) === 1
            ? $matches[1] : $gameId;
    }

    /** @return array<string, mixed> */
    public function gameEvents(Game $game, bool $over = false): array
    {
        if ($over && $game->status->isAbort()) {
            throw GatewayException::eventInvalid();
        }
        $events = [];
        foreach ($game->players as $player) {
            $events[] = [
                'eventType' => 'playerSeated',
                'seat' => $player->seat,
                'name' => $player->name,
                'stack' => $player->stack,
            ];
        }
        foreach ($game->events as $event) {
            $payload = $event->payload;
            switch ($event->type) {
                case GameEvent::FORCE_BET:
                    if (isset($payload['big_blind'])) {
                        foreach ($game->players as $player) {
                            if (($payload['ante'] ?? 0) > 0) {
                                $events[] = [
                                    'eventType' => 'blindPosted',
                                    'name' => $player->name,
                                    'blindType' => 'ANTE',
                                    'amount' => (int) $payload['ante'],
                                ];
                            }
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
                    }
                    foreach ($payload['extra_bets'] ?? [] as $bet) {
                        $events[] = [
                            'eventType' => 'blindPosted',
                            'name' => $bet['name'],
                            'blindType' => strtoupper($bet['type']),
                            'amount' => (int) $bet['amount'],
                        ];
                    }
                    break;
                case GameEvent::STAGE_START:
                    $stage = StageEnum::fromNameOrFail(strtoupper($event->payload['stage']));
                    $cards = $payload['cards'] ?? [];
                    // Business events hold the complete board; Proto expects cards dealt this street.
                    $cards = match ($stage) {
                        StageEnum::TURN => array_slice($cards, 3, 1),
                        StageEnum::RIVER => array_slice($cards, 4, 1),
                        default => $cards,
                    };
                    $events[] = [
                        'eventType' => 'stageStarted',
                        'stage' => strtolower($stage->name),
                        'cards' => implode(',', $cards),
                    ];
                    if ($stage->isPreflop()) {
                        $events[] = [
                            'eventType' => 'handDealt',
                            'name' => $game->hero()->name,
                            'cards' => implode(',', $game->hero()->cards ?? []),
                        ];
                    }
                    break;
                case GameEvent::PLAYER_ACTED:
                    $action = ActionEnum::fromNameOrFail(strtoupper(str_replace('-', '_', $payload['action'])));
                    $events[] = [
                        'eventType' => 'playerActed',
                        'name' => $payload['name'],
                        'action' => $action->wire(),
                        'amount' => $payload['amount'],
                    ];
                    break;
                case GameEvent::KNOWN_PLAY_CARDS:
                    $events[] = [
                        'eventType' => 'knownPlayerCards',
                        'name' => $payload['name'],
                        'cards' => implode(',', $payload['cards'] ?? []),
                    ];
                    break;
                case GameEvent::HAND_OVER:
                    if ($over) {
                        // Private known cards are not evidence that a player showed their hand.
                        foreach ($payload['shown'] ?? [] as $shown) {
                            $events[] = [
                                'eventType' => 'handShown',
                                'name' => $shown['name'],
                                'cards' => implode(',', $shown['cards']),
                            ];
                        }
                        // Each winner includes their actual main-pot and side-pot awards.
                        foreach ($payload['winners'] as $winner) {
                            $events[] = [
                                'eventType' => 'playerWon',
                                'name' => $winner['name'],
                                'amount' => $winner['amount'],
                            ];
                        }
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
                'gameId' => $this->externalGameId($game),
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
