<?php

declare(strict_types=1);

namespace App\Game;

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\NetworkEnum;
use App\Enum\SeatTypeEnum;
use App\Enum\StageEnum;
use App\Exception\AppException;
use App\Exception\AuthException;
use App\Exception\GatewayException;
use App\Game\Providers\ProviderInterface;
use App\Model\Game;
use App\Service\GameService;
use App\Service\UserTokenService;
use App\Vo\Game\PokerServerConnectionVo;
use App\Vo\Game\PokerServerMessageVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Throwable;

final class PokerServer implements OnCloseInterface, OnMessageInterface, OnOpenInterface
{
    /**
     * @var array<int, PokerServerConnectionVo>
     */
    private array $connections = [];

    public function __construct(
        private readonly Sender $sender,
        private readonly PokerManager $poker,
        private readonly UserTokenService $tokenService,
        private readonly GameService $gameService,
        private readonly ValidatorFactoryInterface $validatorFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param  mixed  $server */
    public function onOpen($server, $request): void
    {
        $token = $request->get['token'] ?? null;

        if (! $token) {
            $server->disconnect($request->fd);

            return;
        }

        $token = $this->tokenService->validateToken($token);
        if (empty($token->user)) {
            $server->disconnect($request->fd);

            return;
        }

        $this->connections[$request->fd] = new PokerServerConnectionVo($request->fd, $token->user, $token);
    }

    /** @param  mixed  $server */
    public function onMessage($server, $frame): void
    {
        $fd = $frame->fd;
        $id = null;
        try {
            $this->connection($fd);

            $message = json_decode($frame->data, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($message) || empty($message['type']) || empty($message['id'])) {
                throw GatewayException::eventInvalid();
            }

            if (empty($message['timestamp']) || $message['timestamp'] < microtime(true) * 1000 - 10000) {
                throw GatewayException::eventInvalid();
            }

            $id = $message['id'];
            if ($message['type'] === GameEvent::PING) {
                $this->handlePing($fd, $id);

                return;
            }

            $this->logger->info('Poker audit receive', ['fd' => $fd, 'message' => $message]);
            $this->handleGameEvents($fd, $id, $message);

        } catch (Throwable $error) {
            $this->replyError($fd, $error, $id);
        }

    }

    /** @param  mixed  $server */
    public function onClose($server, int $fd, int $reactorId): void
    {
        unset($this->connections[$fd]);
    }

    protected function handlePing(int $fd, string $id): void
    {
        $this->reply($fd, GameEvent::PONG, [], $id);
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws AuthException
     * @throws GatewayException
     */
    protected function handleGameEvents(int $fd, string $id, array $message): void
    {
        /** @var string $type */
        $type = $message['type'];
        $method = 'handle'.Str::studly($type);
        if (! method_exists($this, $method)) {
            throw GatewayException::eventInvalid();
        }
        $message = new PokerServerMessageVo(
            $fd,
            $this->connection($fd)->user,
            $this->connection($fd)->token,
            $id,
            $type,
            $message['payload'] ?? [],
            $message['timestamp'],
        );
        $game = $this->$method($message);

        if ($type === GameEvent::REQUEST_ACTION) {
            return;
        }

        $this->reply($fd, $type.'.ack', ['hand_uuid' => $game->uuid], $id);
    }

    public function handleHandStart(PokerServerMessageVo $message): Game
    {
        $payload = $this->validatorFactory->make($message->payload, $this->handStartRules())->validate();

        $game = $this->gameService->create(
            $message->user,
            $payload['room_number'],
            $payload['hand_number'],
            $payload['players'],
            $message->id,
            $payload,
            NetworkEnum::fromNameOrFail($payload['network']),
        );

        return $game;
    }

    public function handleForceBet(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            ...$this->forceBetRules(),
        ]);

        return $this->append($message);
    }

    public function handleHandCard(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            ...$this->handCardRules(),
        ]);
        $game = $this->append($message);
        $this->providerFor($game)->start($game);

        return $game;
    }

    /**
     * Accept the complete, authoritative history for one hand.  This is used
     * after a reporting interruption, when the client may not have received a
     * hand_start acknowledgement and consequently has no hand_uuid to send.
     */
    public function handleHandRefresh(PokerServerMessageVo $message): Game
    {
        $payload = $message->payload;
        $events = $payload['events'] ?? null;
        $startPayload = $payload['game'] ?? $payload;
        if (! is_array($events) || ! array_is_list($events) || ! is_array($startPayload)) {
            throw GatewayException::eventInvalid();
        }

        $startPayload = $this->validatorFactory->make($startPayload, $this->handStartRules())->validate();
        $events = $this->handRefreshEvents($events);
        $game = $this->gameService->upsertHandRefresh(
            $message->user,
            $startPayload['room_number'],
            $startPayload['hand_number'],
            $startPayload['players'],
            $message->id,
            $startPayload,
            $events,
            NetworkEnum::fromNameOrFail($startPayload['network']),
        );

        if ($game->status->isClosed()) {
            $this->providerFor($game)->over($game);
        }

        return $game;
    }

    public function handleStageStart(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            'stage' => ['required', 'string', 'in:'.$this->stageValues()],
            'cards' => ['present', 'array', 'list', 'max:5'],
            'cards.*' => ['required', 'string', 'max:3'],
        ]);
        $game = $this->append($message);
        $this->providerFor($game)->stage($game);

        return $game;
    }

    public function handlePlayerActed(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            'name' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'in:'.$this->actionValues()],
            'amount' => ['required', 'integer:strict', 'min:0', 'max:9007199254740991'],
        ]);
        $game = $this->append($message);
        $this->providerFor($game)->playerActed($game);

        return $game;
    }

    public function handleKnownPlayCards(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            'name' => ['required', 'string', 'max:64'],
            'cards' => ['required', 'array', 'list', 'size:2'],
            'cards.*' => ['required', 'string', 'max:3'],
        ]);
        $game = $this->append($message);
        $this->providerFor($game)->knownPlayerCards($game);

        return $game;
    }

    public function handleRequestAction(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, $this->handUuidRules());
        $game = $this->append($message);
        $this->providerFor($game)->requestAction($game,
            function (RequestActionResultVo $result) use ($message, $game) {
                if ($result->success) {
                    $this->reply($message->fd, GameEvent::REQUEST_ACTION.'.ack', [
                        'hand_uuid' => $game->uuid,
                        'action' => $result->action?->wire(),
                        'amount' => $result->amount,
                    ], $message->id);
                } else {
                    $this->reply($message->fd, 'error', [
                        'code' => $result->error_code ?? 'provider_unavailable',
                        'message' => $result->reason ?? '',
                    ], $message->id);
                }
            });

        return $game;
    }

    public function handleHandOver(PokerServerMessageVo $message): Game
    {
        $this->validatePayload($message->payload, [
            ...$this->handUuidRules(),
            ...$this->handOverRules(),
        ]);
        $game = $this->append($message);
        $connection = $this->connections[$message->fd] ?? null;
        $this->providerFor($game)->over($game, function (string $reason) use ($message, $game, $connection): void {
            // A reused fd must never deliver a previous connection's private game data.
            if ($connection === null || ($this->connections[$message->fd] ?? null) !== $connection) {
                return;
            }
            $this->reply($message->fd, 'hand_over.error', [
                'hand_uuid' => $game->uuid,
                'event_id' => $message->id,
                'code' => 'settlement_rejected',
                'message' => $reason,
            ]);
        });

        return $game;
    }

    /** @return array<string, list<string>> */
    private function handOverRules(): array
    {
        return [
            'winners' => ['required', 'array', 'list', 'min:1'],
            'winners.*' => ['required', 'array'],
            'winners.*.name' => ['required', 'string', 'max:64', 'distinct:strict'],
            'winners.*.amount' => ['required', 'integer:strict', 'min:0', 'max:9007199254740991'],
            'shown' => ['sometimes', 'array', 'list'],
            'shown.*' => ['required', 'array'],
            'shown.*.name' => ['required', 'string', 'max:64'],
            'shown.*.cards' => ['required', 'array', 'list', 'size:2'],
            'shown.*.cards.*' => ['required', 'string', 'max:3'],
        ];
    }

    private function connection(int $fd): PokerServerConnectionVo
    {
        // Hyperf invokes OnOpen through a deferred callback. A client can send its
        // first frame immediately after the upgrade, before that callback stores
        // the authenticated connection. Yield briefly so that a valid first frame
        // is not rejected merely because of scheduler ordering.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (isset($this->connections[$fd])) {
                return $this->connections[$fd];
            }
            Coroutine::sleep(0.001);
        }

        throw AuthException::authRequired();
    }

    /** @param  array<string, mixed>  $payload */
    private function reply(int $fd, string $type, array $payload = [], ?string $replyTo = null): void
    {
        $message = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'reply_to' => $replyTo,
            'payload' => $payload,
        ];
        $sent = $this->sender->push($fd, json_encode($message, JSON_THROW_ON_ERROR));
        if ($type !== GameEvent::PONG) {
            $this->logger->info('Poker audit send', ['fd' => $fd, 'sent' => $sent, 'message' => $message]);
        }
    }

    private function replyError(int $fd, Throwable $error, ?string $replyTo = null): void
    {
        if ($error instanceof ValidationException) {
            $errors = $error->errors();
            $message = reset($errors)[0] ?? $error->getMessage();
            $this->reply($fd, 'error', [
                'code' => 'event_invalid',
                'message' => $message,
                'details' => $errors,
            ], $replyTo);

            return;
        }

        $exception = $error instanceof AppException ? $error : GatewayException::eventInvalid();
        $this->reply($fd, 'error', [
            'code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
        ] + $exception->details(), $replyTo);
        if ($exception instanceof AuthException) {
            $this->sender->disconnect($fd);
        }
        if (! $error instanceof AppException) {
            $this->logger->warning('Poker WebSocket message rejected', ['exception' => $error::class]);
        }
    }

    private function append(PokerServerMessageVo $message): Game
    {
        return $this->gameService->append(
            $message->user,
            (string) $message->payload['hand_uuid'],
            $message->id,
            $message->type,
            $message->payload,
        );
    }

    private function providerFor(Game $game): ProviderInterface
    {
        return $this->poker->provider($game->provider);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<string>>  $rules
     */
    private function validatePayload(array $payload, array $rules): void
    {
        $this->validatorFactory->make($payload, $rules)->validate();
    }

    /** @return array<string, list<string>> */
    private function handUuidRules(): array
    {
        return ['hand_uuid' => ['required', 'uuid']];
    }

    /** @return array<string, list<string>> */
    private function handStartRules(): array
    {
        return [
            'room_number' => ['required', 'string', 'max:64'],
            'hand_number' => ['required', 'integer', 'min:1'],
            'network' => ['required', 'string', 'in:'.NetworkEnum::implode()],
            'players' => ['required', 'array', 'list', 'min:1'],
            'players.*' => ['required', 'array'],
            'players.*.seat' => ['required', 'integer', 'min:1', 'distinct'],
            'players.*.name' => ['required', 'string', 'max:64', 'distinct'],
            'players.*.hero' => ['required', 'boolean'],
            'players.*.stack' => ['required', 'integer:strict', 'min:0', 'max:9007199254740991'],
            'players.*.seat_type' => ['required', 'string', 'in:'.SeatTypeEnum::implode()],
        ];
    }

    /** @return array<string, list<string>> */
    private function forceBetRules(): array
    {
        return [
            'big_blind' => ['required_with:small_blind', 'required_without:extra_bets', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'small_blind' => [
                'required_with:big_blind', 'required_without:extra_bets', 'integer:strict', 'min:1', 'max:9007199254740991', 'lte:big_blind',
            ],
            'ante' => ['sometimes', 'integer:strict', 'min:0', 'max:9007199254740991'],
            'extra_bets' => ['sometimes', 'array', 'list', 'min:1', 'max:18'],
            'extra_bets.*' => ['required', 'array:name,type,amount'],
            'extra_bets.*.name' => ['required', 'string', 'max:64'],
            'extra_bets.*.type' => ['required', 'string', 'in:post,straddle'],
            'extra_bets.*.amount' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
        ];
    }

    /** @return array<string, list<string>> */
    private function handCardRules(): array
    {
        return [
            'cards' => ['required', 'array', 'list', 'size:2'],
            'cards.*' => ['required', 'string', 'max:3'],
        ];
    }

    /**
     * @param  list<mixed>  $events
     * @return list<array{type: string, payload: array<string, mixed>, id?: string}>
     */
    private function handRefreshEvents(array $events): array
    {
        $fullEvents = [];
        $overSeen = false;
        foreach ($events as $event) {
            if (! is_array($event) || ! is_string($event['type'] ?? null) || ! is_array($event['payload'] ?? null)) {
                throw GatewayException::eventInvalid();
            }
            if ($overSeen || $event['type'] === GameEvent::HAND_START) {
                throw GatewayException::eventInvalid();
            }
            if (isset($event['id']) && (! is_string($event['id']) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $event['id']))) {
                throw GatewayException::eventInvalid();
            }

            $rules = match ($event['type']) {
                GameEvent::FORCE_BET => $this->forceBetRules(),
                GameEvent::HAND_CARD => $this->handCardRules(),
                GameEvent::STAGE_START => [
                    'stage' => ['required', 'string', 'in:'.$this->stageValues()],
                    'cards' => ['present', 'array', 'list', 'max:5'],
                    'cards.*' => ['required', 'string', 'max:3'],
                ],
                GameEvent::PLAYER_ACTED => [
                    'name' => ['required', 'string', 'max:64'],
                    'action' => ['required', 'string', 'in:'.$this->actionValues()],
                    'amount' => ['required', 'integer:strict', 'min:0', 'max:9007199254740991'],
                ],
                GameEvent::KNOWN_PLAY_CARDS => [
                    'name' => ['required', 'string', 'max:64'],
                    'cards' => ['required', 'array', 'list', 'size:2'],
                    'cards.*' => ['required', 'string', 'max:3'],
                ],
                GameEvent::HAND_OVER => $this->handOverRules(),
                default => throw GatewayException::eventInvalid(),
            };
            $fullEvents[] = [
                'type' => $event['type'],
                'payload' => $this->validatorFactory->make($event['payload'], $rules)->validate(),
                ...(isset($event['id']) ? ['id' => $event['id']] : []),
            ];
            $overSeen = $event['type'] === GameEvent::HAND_OVER;
        }

        $initial = $fullEvents[0] ?? null;
        if ($initial === null || $initial['type'] !== GameEvent::FORCE_BET
            || ! isset($initial['payload']['small_blind'], $initial['payload']['big_blind'])) {
            throw GatewayException::eventInvalid();
        }
        $dealt = false;
        $preflop = true;
        foreach (array_slice($fullEvents, 1) as $event) {
            if ($event['type'] === GameEvent::FORCE_BET) {
                if (! $preflop || array_intersect(['small_blind', 'big_blind', 'ante'], array_keys($event['payload'])) !== []) {
                    throw GatewayException::eventInvalid();
                }
            } elseif ($event['type'] === GameEvent::HAND_CARD) {
                if ($dealt) {
                    throw GatewayException::eventInvalid();
                }
                $dealt = true;
            } elseif (! $dealt) {
                throw GatewayException::eventInvalid();
            }
            if ($event['type'] === GameEvent::STAGE_START && $event['payload']['stage'] !== 'preflop') {
                $preflop = false;
            }
        }
        if (! $dealt) {
            throw GatewayException::eventInvalid();
        }

        return $fullEvents;
    }

    private function actionValues(): string
    {
        return implode(',', array_map(static fn (ActionEnum $action): string => $action->wire(), ActionEnum::cases()));
    }

    private function stageValues(): string
    {
        return implode(',', array_map(static fn (StageEnum $stage): string => strtolower($stage->name), StageEnum::cases()));
    }
}
