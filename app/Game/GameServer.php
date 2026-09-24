<?php

declare(strict_types=1);

namespace App\Game;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\AppException;
use App\Exception\AuthException;
use App\Exception\FoundationException;
use App\Exception\GameException;
use App\Game\Providers\ProviderInterface;
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
use JsonException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Throwable;

final class GameServer implements OnCloseInterface, OnMessageInterface, OnOpenInterface
{
    public const string TYPE_PING = 'PING';

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
    ) {}

    /** @param  mixed  $server */
    public function onOpen($server, $request): void
    {
        $token = $request->get['token'] ?? null;
        $locale = $request->get['locale'] ?? null;

        if (! $token) {
            $server->disconnect($request->fd);

            return;
        }

        $token = $this->tokenService->validateToken($token);
        if (empty($token->user)) {
            $server->disconnect($request->fd);

            return;
        }

        $this->connections[$request->fd] = new GameServerConnectionVo($request->fd, $token->user, $token, $locale);
        $this->reply($request->fd, 'ACCEPT');
        $this->logger->debug('Poker Server Connected', [
            'fd' => $request->fd,
            'user_id' => $token->user->id,
            'locale' => $locale,
            'token_id' => $token->id,
        ]);
    }

    /** @param  mixed  $server */
    public function onMessage($server, $frame): void
    {
        $fd = $frame->fd;
        $id = null;
        $this->logger->debug('Poker Server Message', ['fd' => $fd, 'data' => $frame->data]);
        try {
            try {
                $message = json_decode($frame->data, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw GameException::eventInvalid();
            }
            if (! is_array($message)
                || ! is_string($message['id'] ?? null) || $message['id'] === ''
                || ! is_string($message['type'] ?? null) || $message['type'] === '') {
                throw GameException::eventInvalid();
            }
            $id = $message['id'];
            $connection = $this->connection($fd);
            if (array_key_exists('payload', $message) && ! is_array($message['payload'])) {
                throw GameException::eventInvalid();
            }
            if ($message['type'] !== self::TYPE_PING && ! isset($message['payload'])) {
                throw GameException::eventInvalid();
            }
            if (! is_int($message['timestamp'] ?? null)
                || $message['timestamp'] <= 0
                || $message['timestamp'] < microtime(true) * 1000 - 10000
                || ($connection->lastMessageTimestamp !== null && $message['timestamp'] < $connection->lastMessageTimestamp)) {
                throw GameException::eventInvalid();
            }

            $connection->lastMessageTimestamp = $message['timestamp'];
            if ($message['type'] === self::TYPE_PING) {
                $this->ack($fd, self::TYPE_PING, $id);

                return;
            }

            $message = new GameServerMessageVo(
                $fd,
                $connection->user,
                $connection->token,
                $id,
                $message['type'],
                $message['payload'],
                $message['timestamp'],
            );

            $this->handleEvent($message);

        } catch (Throwable $error) {
            $this->replyError($fd, $error, $id);
        }

    }

    /** @param  mixed  $server */
    public function onClose($server, int $fd, int $reactorId): void
    {
        $connection = $this->connections[$fd] ?? null;
        unset($this->connections[$fd]);
        if ($connection === null) {
            return;
        }
        $this->logger->debug('Poker Server Closed', [
            'fd' => $fd,
            'reactor_id' => $reactorId,
            'user_id' => $connection->user->id,
            'token_id' => $connection->token->id,
        ]);
    }

    /**
     * @throws AuthException
     * @throws GameException
     */
    protected function handleEvent(GameServerMessageVo $message): void
    {
        if ($message->type === self::TYPE_REQUEST_ACTION) {
            $this->handleRequestAction($message);

            return;
        }
        if ($message->type === self::TYPE_REQUEST_INSURANCE) {
            $this->handInsurance($message);

            return;
        }

        if (! GameEventTypeEnum::has($message->type)) {
            throw GameException::eventTypeInvalid($message->type);
        }
        $method = 'handle'.Str::studly($message->type);
        if (! method_exists($this, $method)) {
            throw GameException::eventTypeInvalid($message->type);
        }

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
            throw GameException::heroNotFound();
        }

        $game = $this->gameService->create(
            $message->user->getKey(),
            $payload['game_key'],
            NetworkEnum::fromNameOrFail($payload['network']),
            $payload['ante'],
            $payload['big_blind'],
            $payload['small_blind'],
            $payload['players'],
            $payload['button_seat_number'],
        );

        try {
            $this->provider()->start($game);
        } catch (Throwable $error) {
            $this->gameService->delete($game->uuid);

            throw $error;
        }
        $this->ack($message->fd, $message->type, $message->id, [
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
        );

        $this->provider()->postBlind($event);
        $this->ack($message->fd, $message->type, $message->id);
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
        );

        $this->provider()->dealt($event);
        $this->ack($message->fd, $message->type, $message->id);
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
        );

        $this->provider()->stage($event);
        $this->ack($message->fd, $message->type, $message->id);
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
        );

        $this->provider()->action($event);
        $this->ack($message->fd, $message->type, $message->id);
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

        $game = $this->gameService->find($payload['game_uuid']);
        $result = $this->insuranceService->suggest($game,
            $payload['outs'],
            $payload['pot'],
            (float) $payload['odds'],
            $payload['min'],
            $payload['max'],
            $payload['breakeven'],
        );
        $this->ack($message->fd, $message->type, $message->id, [
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
        );

        $this->provider()->show($event);
        $this->ack($message->fd, $message->type, $message->id);
    }

    public function handleRequestAction(GameServerMessageVo $message): void
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'game_uuid' => ['required', 'uuid'],
        ])->validate();

        $game = $this->gameService->find($payload['game_uuid']);
        $this->provider()->requestAction($game,
            function (RequestActionResultVo $result) use ($game, $message) {
                if ($result->success) {
                    $this->ack($message->fd, self::TYPE_REQUEST_ACTION, $message->id, [
                        'game_uuid' => $game->uuid,
                        'action' => $result->action?->name,
                        'amount' => $result->amount,
                    ]);
                } else {
                    /** @var AppException $exception */
                    $exception = $result->exception;
                    $this->replyError($message->fd, $exception, $message->id);
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
            'shown' => ['sometimes', 'array', 'list'],
            'shown.*' => ['required', 'array'],
            'shown.*.uid' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{1,16}\z/'],
            'shown.*.cards' => ['required', 'array', 'list', 'size:2'],
            'shown.*.cards.*' => ['required', 'string', 'max:3'],
        ])->validate();

        foreach ($payload['winners'] as &$winner) {
            $winner['uid'] = strtolower($winner['uid']);
        }
        unset($winner);
        if (isset($payload['shown'])) {
            foreach ($payload['shown'] as &$shown) {
                $shown['uid'] = strtolower($shown['uid']);
            }
            unset($shown);
        }

        $event = $this->gameService->event(
            $payload['game_uuid'],
            GameEventTypeEnum::fromNameOrFail($message->type),
            $payload,
            $message->timestamp,
        );
        $this->provider()->over($event);
        $this->gameService->store($event->game);
        $this->ack($message->fd, $message->type, $message->id);
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
        );
        $this->provider()->abort($event);
        $this->gameService->store($event->game);
        $this->ack($message->fd, $message->type, $message->id);

    }

    private function connection(int $fd): GameServerConnectionVo
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
        $this->logger->debug('Poker Server Send', ['fd' => $fd, 'sent' => $sent, 'message' => $message]);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function ack(int $fd, string $type, string $replyTo, array $payload = []): void
    {
        $this->reply(
            $fd,
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
                'code' => 'event_invalid',
                'message' => $message,
                'details' => $errors,
            ], $replyTo);

            return;
        }

        $locale = $this->connections[$fd]->locale ?? null;
        $code = 'server_error';
        $details = [];
        if ($error instanceof AppException) {
            $code = $error->getErrorCode();
            $message = $error->getLocaleMessage($locale);
            $details = $error->details();
        } else {
            $message = FoundationException::serverError()->getLocaleMessage($locale);
        }

        $this->logger->log($error instanceof AppException ? 'warning' : 'error', 'Poker Server Error', [
            'fd' => $fd,
            'code' => $code,
            'exception' => $error,
            'reply_to' => $replyTo,
        ]);
        $this->reply($fd, 'error', [
            'code' => $code,
            'message' => $message,
            ...$details,
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
