<?php

declare(strict_types=1);

namespace App\Game;

use App\Constants\GameEvent;
use App\Enum\SeatTypeEnum;
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
        if ($type !== GameEvent::GAME_START && empty($message['payload']['game_uuid'])) {
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

        $this->reply($fd, $type.'.ack', ['game_uuid' => $game->uuid], $id);
    }

    public function handleGameStart(PokerServerMessageVo $message): Game
    {
        $payload = $this->validatorFactory->make($message->payload, [
            'room_number' => ['required', 'string', 'max:64'],
            'hand_number' => ['required', 'integer', 'min:1'],
            'provider' => ['required', 'string', 'max:32'],
            'game_type' => ['sometimes', 'string', 'max:32'],
            'big_blind' => ['required', 'numeric', 'decimal:0,4', 'min:0.0001', 'max:9999999999.9999'],
            'small_blind' => [
                'required', 'numeric', 'decimal:0,4', 'min:0.0001', 'max:9999999999.9999', 'lte:big_blind',
            ],
            'ante' => ['sometimes', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'players' => ['required', 'array', 'list', 'min:1'],
            'players.*' => ['required', 'array'],
            'players.*.seat' => ['required', 'integer', 'min:1', 'distinct'],
            'players.*.name' => ['required', 'string', 'max:64', 'distinct'],
            'players.*.hero' => ['required', 'boolean'],
            'players.*.stack' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'players.*.seat_type' => ['required', 'string', 'in:'.SeatTypeEnum::implode()],
            'players.*.amount' => ['present', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
        ])->validate();

        $game = $this->gameService->create(
            $message->user,
            $payload['room_number'],
            $payload['hand_number'],
            $payload['provider'],
            $payload['big_blind'],
            $payload['small_blind'],
            $payload['ante'] ?? 0,
            $payload['players'],
            $message->id,
            $payload,
            $payload['game_type'] ?? 'NL',
        );
        $game->loadMissing(['players', 'events']);

        $this->providerFor($game)->start($game);

        return $game;
    }

    public function handleGameStage(PokerServerMessageVo $message): Game
    {
        $game = $this->append($message);
        $this->providerFor($game)->stage($game);

        return $game;
    }

    public function handleGamePlayActed(PokerServerMessageVo $message): Game
    {
        $game = $this->append($message);
        $this->providerFor($game)->playerActed($game);

        return $game;
    }

    public function handleGameKnownPlayCards(PokerServerMessageVo $message): Game
    {
        $game = $this->append($message);
        $this->providerFor($game)->knownPlayerCards($game);

        return $game;
    }

    public function handleGameRequestAction(PokerServerMessageVo $message): Game
    {
        $game = $this->append($message);
        $this->providerFor($game)->requestAction($game,
            function (RequestActionResultVo $result) use ($message, $game) {
                if ($result->success) {
                    $this->reply($message->fd, 'game_play_action', [
                        'game_uuid' => $game->uuid,
                        'action' => $result->action?->wire(),
                        'amount' => $result->amount,
                    ], $message->id);
                } else {
                    $this->replyError($message->fd, new \RuntimeException($result->reason ?? 'Unknown error'));
                }
            });

        return $game;
    }

    public function handleGameOver(PokerServerMessageVo $message): Game
    {
        $game = $this->append($message);
        $this->providerFor($game)->over($game);

        return $game;
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
        $this->sender->push($fd, json_encode($message, JSON_THROW_ON_ERROR));
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
            (string) $message->payload['game_uuid'],
            $message->id,
            $message->type,
            $message->payload,
        );
    }

    private function providerFor(Game $game): ProviderInterface
    {
        return $this->poker->provider($game->provider);
    }
}
