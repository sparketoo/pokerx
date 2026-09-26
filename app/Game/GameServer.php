<?php

declare(strict_types=1);

namespace App\Game;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\AppException;
use App\Exception\AuthException;
use App\Exception\GameException;
use App\Game\Providers\ProviderInterface;
use App\Model\User;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameServerMessageVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\ValidationException;
use Hyperf\WebSocketServer\Sender;
use Illuminate\Encryption\Encrypter;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine;
use Throwable;

use function Hyperf\Translation\__;

final class GameServer implements OnCloseInterface, OnMessageInterface, OnOpenInterface
{
    public const string TYPE_PING = 'PING';

    public const string TYPE_ACCEPT = 'ACCEPT';

    // 请求下注决策
    public const string TYPE_REQUEST_ACTION = 'REQUEST_ACTION';

    // 请求保险决策
    public const string TYPE_REQUEST_INSURANCE = 'REQUEST_INSURANCE';

    /**
     * @var array<int, GameServerConnectionVo>
     */
    private array $connections = [];

    public function __construct(
        private readonly Sender $sender,
        private readonly GameProviderManager $poker,
        private readonly UserTokenService $tokenService,
        private readonly GameService $gameService,
        private readonly InsuranceService $insuranceService,
        private readonly ValidatorFactoryInterface $validatorFactory,
        private readonly LoggerInterface $logger,
        private readonly Encrypter $encrypter,
    ) {}

    /** @param  mixed  $server */
    public function onOpen($server, $request): void
    {
        $disconnect = true;
        $connection = null;
        try {
            $token = $request->get['token'] ?? null;
            $locale = $request->get['locale'] ?? null;
            if (! is_string($token) || $token === '') {
                return;
            }

            $token = $this->tokenService->validateToken($token);
            if (empty($token->user)) {
                return;
            }

            $clientId = $this->clientId($token->user, $request->get['client_id'] ?? null);
            $connection = new GameServerConnectionVo(
                $request->fd,
                $token->user,
                $clientId,
            );
            $this->connections[$request->fd] = $connection;
            $this->provider()->connect($connection);
            if (! $this->isCurrentConnection($connection)) {
                $disconnect = false;
                $this->provider()->disconnect($connection);

                return;
            }
            $connection->ready = true;
            if (! $this->reply($request->fd, self::TYPE_ACCEPT, ['client_id' => $clientId])) {
                throw new RuntimeException('Failed to send WebSocket ACCEPT');
            }
            $this->logger->debug('Poker Server Connected', [
                'fd' => $request->fd,
                'user_id' => $token->user->id,
                'locale' => $locale,
            ]);
            $disconnect = false;
        } catch (Throwable $error) {
            if ($connection !== null && $this->isCurrentConnection($connection)) {
                unset($this->connections[$request->fd]);
            }
            if ($connection !== null) {
                try {
                    $this->provider()->disconnect($connection);
                } catch (Throwable $cleanupError) {
                    $this->logger->warning('Poker Server provider disconnect failed', [
                        'fd' => $request->fd,
                        'exception' => $cleanupError,
                    ]);
                }
            }
            $this->logger->error('Poker Server connection setup failed', [
                ...($error instanceof AppException ? $error->context() : []),
                'fd' => $request->fd,
                'exception_class' => $error::class,
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'exception' => $error,
            ]);
        } finally {
            if ($disconnect) {
                $server->disconnect($request->fd);
            }
        }
    }

    /** @param  mixed  $server */
    public function onMessage($server, $frame): void
    {
        $fd = $frame->fd;
        $id = null;
        $connection = null;
        $this->logger->debug('Poker Server Message', ['fd' => $fd, 'data' => $frame->data]);
        try {
            try {
                $message = json_decode($frame->data, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            if (! is_array($message)
                || ! is_string($message['id'] ?? null) || $message['id'] === ''
                || ! is_string($message['type'] ?? null) || $message['type'] === '') {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            $id = $message['id'];
            $connection = $this->connection($fd);
            if (array_key_exists('payload', $message) && ! is_array($message['payload'])) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            if ($message['type'] !== self::TYPE_PING && ! isset($message['payload'])) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }
            if (! is_int($message['timestamp'] ?? null)
                || $message['timestamp'] <= 0
                || $message['timestamp'] < microtime(true) * 1000 - 10000
                || ($connection->lastMessageTimestamp !== null && $message['timestamp'] < $connection->lastMessageTimestamp)) {
                throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
            }

            $connection->lastMessageTimestamp = $message['timestamp'];
            if ($message['type'] === self::TYPE_PING) {
                $this->ack($connection, self::TYPE_PING, $id);

                return;
            }

            $message = new GameServerMessageVo(
                $connection,
                $id,
                $message['type'],
                $message['payload'],
                $message['timestamp'],
            );

            $this->handleEvent($message);

        } catch (Throwable $error) {
            if ($connection !== null && ! $this->isCurrentConnection($connection)) {
                return;
            }
            $this->replyError($fd, $error, $id);
        }
    }

    /** @param  mixed  $server */
    public function onClose($server, int $fd, int $reactorId): void
    {
        $connection = $this->connections[$fd] ?? null;

        if ($connection === null) {
            return;
        }
        unset($this->connections[$fd]);

        try {
            $this->provider()->disconnect($connection);
        } catch (Throwable $error) {
            $this->logger->warning('Poker Server provider disconnect failed', [
                ...($error instanceof AppException ? $error->context() : []),
                'fd' => $fd,
                'exception' => $error,
            ]);
        }

        $this->logger->debug('Poker Server Closed', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'user_id' => $connection->user->id,
        ]);
    }

    /**
     * @throws AuthException
     * @throws GameException
     */
    protected function handleEvent(GameServerMessageVo $message): void
    {
        if (! $this->isCurrentConnection($message->connection)) {
            return;
        }
        if ($message->type === self::TYPE_REQUEST_ACTION) {
            $this->handleRequestAction($message);

            return;
        }
        if ($message->type === self::TYPE_REQUEST_INSURANCE) {
            $this->handInsurance($message);

            return;
        }

        if (! GameEventTypeEnum::has($message->type)) {
            throw new GameException(__('messages.game.event_type_invalid'), ErrorCode::EVENT_INVALID);
        }
        $method = 'handle'.Str::studly($message->type);

        $this->$method($message);
    }

    public function handleStart(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_key' => ['required', 'string', 'max:32', 'regex:/\A\S(?:.*\S)?\z/us'],
            'ante' => ['required', 'integer', 'min:0'],
            'big_blind' => ['required', 'integer', 'min:1'],
            'small_blind' => ['required', 'integer', 'min:0'],
            'network' => ['required', 'string', 'in:'.NetworkEnum::implode()],
            'button_seat_number' => ['required', 'integer', 'min:1', 'max:10'],
            'players' => ['required', 'array', 'list', 'min:2'],
            'players.*' => ['required', 'array'],
            'players.*.seat' => ['required', 'integer', 'min:1', 'max:10', 'distinct'],
            'players.*.uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/', 'distinct:ignore_case'],
            'players.*.name' => ['string', 'max:16'],
            'players.*.hero' => ['required', 'boolean'],
            'players.*.stack' => ['required', 'integer:strict', 'min:0', 'max:100000000'],
        ])->validate();

        foreach ($payload['players'] as &$player) {
            $player['uid'] = strtolower($player['uid']);
        }
        unset($player);

        if (! in_array(true, array_column($payload['players'], 'hero'), true)) {
            throw new GameException(__('messages.game.hero_not_found'), ErrorCode::BUSINESS_ERROR);
        }

        $game = $this->gameService->create(
            $message->connection->user->getKey(),
            $payload['game_key'],
            NetworkEnum::fromNameOrFail($payload['network']),
            $payload['ante'],
            $payload['big_blind'],
            $payload['small_blind'],
            $payload['players'],
            $payload['button_seat_number'],
            $message->connection->clientId,
        );

        try {
            if (! $this->isCurrentConnection($message->connection)) {
                throw new RuntimeException('WebSocket connection closed during game creation');
            }
            $this->provider()->start($game);
        } catch (Throwable $error) {
            $this->gameService->delete($game->uuid);

            throw $error;
        }
        $this->ack($message->connection, $message->type, $message->id, [
            'game_uuid' => $game->uuid,
        ]);
    }

    /**
     * 上报任意参局玩家在开局时补交的活盲。
     */
    public function handlePostBlind(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
            'amount' => ['required', 'integer:strict', 'min:1', 'max:100000000'],
        ])->validate();

        $payload['uid'] = strtolower($payload['uid']);
        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::POST_BLIND,
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->postBlind($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    /** 上报翻牌前玩家自愿下的活盲。 */
    public function handleStraddleBlind(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'integer:strict', 'min:1', 'max:100000000'],
            'uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
        ])->validate();

        $payload['uid'] = strtolower($payload['uid']);
        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::STRADDLE_BLIND,
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->straddleBlind($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    public function handleDealt(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'cards' => ['required', 'array', 'list', 'size:2'],
            'cards.*' => ['required', 'string', 'max:3'],
        ])->validate();

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->dealt($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    public function handleStage(GameServerMessageVo $message): void
    {
        $cardsSize = match ($message->payload['stage'] ?? null) {
            StageEnum::PREFLOP->name => 0,
            StageEnum::FLOP->name => 3,
            StageEnum::TURN->name, StageEnum::RIVER->name => 1,
            default => null,
        };
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'stage' => ['required', 'string', 'in:'.StageEnum::implode()],
            'cards' => ['present', 'array', 'list', ...($cardsSize === null ? [] : ['size:'.$cardsSize])],
            'cards.*' => ['required', 'string', 'max:3'],
        ])->validate();

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->stage($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    public function handleAction(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
            'action' => ['required', 'string', 'in:'.ActionEnum::implode()],
            'amount' => ['required', 'integer:strict', 'min:0', 'max:100000000'],
        ])->validate();

        $payload['uid'] = strtolower($payload['uid']);

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->action($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    public function handInsurance(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'stage' => ['required', 'in:'.StageEnum::FLOP->name.','.StageEnum::TURN->name],
            'outs' => ['required', 'integer', 'min:1', 'max:52'],
            'pot' => ['required', 'integer', 'min:1', 'max:100000000'],
            'odds' => ['required', 'decimal:0,2', 'gt:0'],
            'min' => ['required', 'integer', 'min:0', 'max:100000000'],
            'max' => ['required', 'integer', 'min:0', 'max:100000000'],
            'breakeven' => ['required', 'integer', 'min:0', 'max:100000000'],
        ])->validate();

        $game = $this->gameService->findForClient($payload['game_uuid'], $message->connection->user->id, $message->connection->clientId);
        $result = $this->insuranceService->suggest($game,
            $payload['outs'],
            $payload['pot'],
            (float) $payload['odds'],
            $payload['min'],
            $payload['max'],
            $payload['breakeven'],
        );
        $this->ack($message->connection, $message->type, $message->id, [
            'amount' => $result,
        ]);
    }

    public function handleShow(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
            'cards' => ['required', 'array', 'list', 'size:2'],
            'cards.*' => ['required', 'string', 'max:3'],
        ])->validate();

        $payload['uid'] = strtolower($payload['uid']);

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );

        $this->provider()->show($event);
        $this->ack($message->connection, $message->type, $message->id);
    }

    public function handleRequestAction(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
        ])->validate();

        $game = $this->gameService->findForClient($payload['game_uuid'], $message->connection->user->id, $message->connection->clientId);
        if (! $this->isCurrentConnection($message->connection)) {
            return;
        }
        $this->provider()->requestAction($game,
            function (RequestActionResultVo $result) use ($game, $message) {
                if (! $this->isCurrentConnection($message->connection)) {
                    return;
                }
                if ($result->success) {
                    $this->ack($message->connection, self::TYPE_REQUEST_ACTION, $message->id, [
                        'game_uuid' => $game->uuid,
                        'action' => $result->action?->name,
                        'amount' => $result->amount,
                    ]);
                    $this->logger->info('Poker action answered', [
                        'message_id' => $message->id,
                        'game_id' => $game->uuid,
                        'user_id' => $game->userId,
                        'fd' => $message->connection->fd,
                        'action' => $result->action?->name,
                        'amount' => $result->amount,
                    ]);
                } else {
                    /** @var AppException $exception */
                    $exception = $result->exception;
                    $this->replyError($message->connection->fd, $exception, $message->id);
                }
            });
    }

    /**
     * 游戏结束
     */
    public function handleOver(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
            'winners' => ['required', 'array', 'list', 'min:1'],
            'winners.*' => ['required', 'array'],
            'winners.*.uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/', 'distinct:ignore_case'],
            'winners.*.amount' => ['required', 'integer:strict', 'min:0', 'max:100000000'],
            'returns' => ['sometimes', 'array', 'list'],
            'returns.*' => ['required', 'array'],
            'returns.*.uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/', 'distinct:ignore_case'],
            'returns.*.amount' => ['required', 'integer:strict', 'min:1', 'max:100000000'],
            'shown' => ['sometimes', 'array', 'list'],
            'shown.*' => ['required', 'array'],
            'shown.*.uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
            'shown.*.cards' => ['required', 'array', 'list', 'size:2'],
            'shown.*.cards.*' => ['required', 'string', 'max:3'],
        ])->validate();

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );
        if (! $this->isCurrentConnection($message->connection)) {
            return;
        }
        $this->provider()->over($event);
        $this->gameService->queueToStore($event->game);
        $this->ack($message->connection, $message->type, $message->id);
    }

    /**
     * 游戏终止
     */
    public function handleAbort(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
        ])->validate();

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
            $message->connection->user->id,
            $message->connection->clientId,
        );
        $this->provider()->abort($event);
        $this->gameService->queueToStore($event->game);
        $this->ack($message->connection, $message->type, $message->id);

    }

    private function clientId(User $user, mixed $candidate): string
    {
        if ($candidate === null) {
            return $this->encrypter->encryptString($user->id.':'.bin2hex(random_bytes(16)));
        }
        if (! is_string($candidate) || $candidate === '') {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED);
        }
        try {
            $identity = $this->encrypter->decryptString($candidate);
        } catch (Throwable $error) {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED, previous: $error);
        }
        if (! preg_match('/^([1-9][0-9]*):[a-f0-9]{32}$/D', $identity, $matches)
            || (int) $matches[1] !== $user->id) {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED);
        }

        return $candidate;
    }

    private function isCurrentConnection(GameServerConnectionVo $connection): bool
    {
        return ($this->connections[$connection->fd] ?? null) === $connection;
    }

    private function connection(int $fd): GameServerConnectionVo
    {
        // Hyperf invokes OnOpen through a deferred callback. A client can send its
        // first frame immediately after the upgrade, before that callback stores
        // the authenticated connection. Yield briefly so that a valid first frame
        // is not rejected merely because of scheduler ordering.
        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (isset($this->connections[$fd]) && $this->connections[$fd]->ready) {
                return $this->connections[$fd];
            }
            Coroutine::sleep(0.001);
        }

        throw new AuthException(__('messages.auth.required'), ErrorCode::AUTH_REQUIRED);
    }

    /** @param  array<string, mixed>  $payload */
    private function reply(int $fd, string $type, array $payload = [], ?string $replyTo = null): bool
    {
        $message = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'reply_to' => $replyTo,
            'payload' => $payload,
        ];
        $sent = $this->sender->push($fd, json_encode($message, JSON_THROW_ON_ERROR));
        if (isset($message['payload']['client_id'])) {
            $message['payload']['client_id'] = '[redacted]';
        }
        $this->logger->debug('Poker Server Send', ['fd' => $fd, 'sent' => $sent, 'message' => $message]);

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function ack(GameServerConnectionVo $connection, string $type, string $replyTo, array $payload = []): void
    {
        if (! $this->isCurrentConnection($connection)) {
            return;
        }
        $this->reply(
            $connection->fd,
            $type.'.ACK',
            $payload,
            $replyTo,
        );
    }

    private function replyError(int $fd, Throwable $error, ?string $replyTo = null): void
    {
        if ($error instanceof ValidationException) {
            $errors = $error->errors();
            $message = reset($errors)[0] ?? $error->getMessage();
            $this->reply($fd, 'error', [
                'code' => ErrorCode::EVENT_INVALID,
                'message' => $message,
                'details' => $errors,
            ], $replyTo);

            return;
        }

        $locale = $this->connections[$fd]->locale ?? null;
        $code = ErrorCode::SERVER_ERROR;
        $details = [];
        if ($error instanceof AppException) {
            $code = $error->getCode();
            $message = $error->getMessage();
            $details = $error->details();
        } else {
            $message = __('messages.common.server_error', [], $locale);
        }

        $this->logger->log($error instanceof AppException ? 'warning' : 'error', 'Poker Server Error', [
            ...($error instanceof AppException ? $error->context() : []),
            'fd' => $fd,
            'code' => $code,
            'exception' => $error,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'reply_to' => $replyTo,
        ]);
        $this->reply($fd, 'error', [
            'code' => $code,
            'message' => $message,
            ...($details !== [] ? ['details' => $details] : []),
        ], $replyTo);
        if ($error instanceof AuthException) {
            $this->sender->disconnect($fd);
        }
    }

    protected function provider(): ProviderInterface
    {
        return $this->poker->provider();
    }
}
