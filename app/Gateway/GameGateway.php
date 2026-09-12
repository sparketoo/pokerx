<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Exception\AppException;
use App\Exception\AuthException;
use App\Exception\GatewayException;
use App\Model\Log;
use App\Poker\PokerManager;
use App\Service\GameService;
use App\Vo\Game\GetSolveVo;
use Closure;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use LogicException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Swoole\WebSocket\Server;
use Throwable;

use function App\Support\di;
use function Hyperf\Config\config;

final class GameGateway implements OnCloseInterface, OnMessageInterface, OnOpenInterface
{
    private GatewayState $state;

    private ?int $maintenance = null;

    private ?Closure $messageHandler = null;

    /** @var array<int, Channel> */
    private array $locks = [];

    public function __construct(private readonly GameRuntime $runtime)
    {
        $this->state = new GatewayState;
    }

    /**
     * @param  mixed  $server
     * @param  mixed  $request
     */
    public function onOpen($server, $request): void
    {
        if (! $server instanceof Server) {
            throw new LogicException('Swoole required');
        }
        if ($this->messageHandler === null) {
            $this->boot($server);
        }
        $this->locks[$request->fd] = new Channel(1);
        $this->state->clients[$request->fd] = [
            'id' => (string) Str::uuid(), 'token' => null, 'user' => null, 'opened' => time(),
        ];
    }

    public function onMessage($server, $frame): void
    {
        if (! $server instanceof Server) {
            throw new LogicException('Swoole required');
        }
        $lock = $this->locks[$frame->fd] ?? null;
        if ($lock === null || ! $lock->push(true, 10)) {
            $server->disconnect($frame->fd);

            return;
        }
        try {
            ($this->messageHandler ?? throw new LogicException('Gateway not initialized'))($server, $frame);
        } finally {
            $lock->pop(0.001);
        }
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $client = $this->state->clients[$fd] ?? null;
        unset($this->state->clients[$fd]);
        if (isset($this->locks[$fd])) {
            $this->locks[$fd]->close();
        }
        unset($this->locks[$fd]);
        if ($client) {
            foreach ($this->state->providerOwners as $key => $owner) {
                if ($owner === $client['id']) {
                    $this->state->providers[$key]->close();
                    unset($this->state->providers[$key], $this->state->providerOwners[$key]);
                }
            }
            di(Redis::class)->del('connection:'.$client['id']);
        }
    }

    public function shutdown(): void
    {
        if ($this->maintenance !== null) {
            Timer::clear($this->maintenance);
            $this->maintenance = null;
        }
        $this->state->clients = [];
        foreach ($this->state->providers as $provider) {
            $provider->close();
        }
        $this->state->providers = [];
        $this->state->providerOwners = [];
        foreach ($this->locks as $lock) {
            $lock->close();
        }
        $this->locks = [];
    }

    private function boot(Server $server): void
    {
        $state = $this->state;
        $runtime = $this->runtime;
        $this->maintenance = Timer::tick(1000, function () use ($server, $state): void {
            foreach ($state->clients as $fd => $client) {
                try {
                    if ($client['token'] === null) {
                        if (time() - $client['opened'] > 10) {
                            $server->disconnect($fd);
                        }

                        continue;
                    }
                    $this->authorize($client['token']);
                    $active = json_decode(di(Redis::class)->get('active_token:'.hash('sha256',
                        $client['token'])) ?: 'null', true);
                    if (($active['connection_id'] ?? null) !== $client['id']) {
                        $server->disconnect($fd);
                    }
                } catch (Throwable) {
                    $server->disconnect($fd);
                }
            }
        });
        $send = function (int $fd, string $generation, string $type, array $payload, ?string $reply = null) use (
            $server,
            $state,
            $runtime
        ) {
            if (! $state->isCurrent($fd, $generation) || ! $server->isEstablished($fd)) {
                return;
            }
            $message = ['id' => (string) Str::uuid(), 'type' => $type, 'reply_to' => $reply, 'payload' => $payload];
            $server->push($fd, json_encode($message, JSON_THROW_ON_ERROR));
            if ($state->clients[$fd]['user']) {
                try {
                    $runtime->log($state->clients[$fd]['user'], 'CLIENT_OUT', $type, $message,
                        $payload['hand_id'] ?? null);
                } catch (Throwable) {
                }
            }
        };
        $this->messageHandler = function ($server, $frame) use ($state, $runtime, $send) {
            $fd = $frame->fd;
            if (! isset($state->clients[$fd])) {
                return;
            }
            $c = $state->clients[$fd];
            $id = null;
            try {
                $message = json_decode($frame->data, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($message)) {
                    throw GatewayException::eventInvalid();
                }
                if ($c['token'] === null) {
                    if (! is_string($message['token'] ?? null) || ! is_string($message['client_version'] ?? null) || ! is_string($message['client_platform'] ?? null) || array_diff(array_keys($message),
                        ['token', 'client_version', 'client_platform'])) {
                        throw AuthException::authRequired();
                    }
                    $user = $this->authorize($message['token']);
                    $state->clients[$fd]['token'] = $message['token'];
                    $state->clients[$fd]['user'] = $user;
                    di(Redis::class)->setex('active_token:'.hash('sha256', $message['token']),
                        config('poker.token_days') * 86400,
                        json_encode(['connection_id' => $c['id']], JSON_THROW_ON_ERROR));
                    di(Redis::class)->setex('connection:'.$c['id'], 60,
                        json_encode(['user_id' => $user], JSON_THROW_ON_ERROR));
                    $send($fd, $c['id'], 'auth_ok', ['connection_id' => $c['id'], 'heartbeat_interval_ms' => 20000]);

                    return;
                }
                $user = $this->authorize($c['token']);
                if ((json_decode(di(Redis::class)->get('active_token:'.hash('sha256', $c['token'])) ?: 'null', true,
                    512, JSON_THROW_ON_ERROR)['connection_id'] ?? null) !== $c['id']) {
                    throw AuthException::authExpired();
                }
                di(Redis::class)->setex('connection:'.$c['id'], 60,
                    json_encode(['user_id' => $user], JSON_THROW_ON_ERROR));
                $id = is_string($message['id'] ?? null) ? $message['id'] : null;
                if (($message['type'] ?? null) === 'heatbeat_ping') {
                    if (! $id || ! Str::isUuid($id) || ! is_int($message['payload']['timestamp'] ?? null) || isset($message['seq'])) {
                        throw GatewayException::eventInvalid();
                    }
                    $runtime->log($user, 'CLIENT_IN', 'heatbeat_ping', $message);
                    $send($fd, $c['id'], 'heatbeat_pong', ['timestamp' => $message['payload']['timestamp']], $id);

                    return;
                }
                $accepted = $runtime->accept($user, $c['id'], $message);
                $context = $accepted['context'];
                if (! is_string($id)) {
                    throw GatewayException::eventInvalid();
                }
                if ($message['type'] === 'game_over') {
                    $this->persist($user);
                }
                $send($fd, $c['id'], 'event_ack', [
                    'hand_id' => $context->game->id, 'accepted_seq' => $message['seq'],
                    'status' => $accepted['duplicate'] ? 'duplicate' : 'accepted',
                ], $id);
                if ($accepted['duplicate']) {
                    return;
                }
                if ($accepted['solve']) {
                    $result = $accepted['solve'];
                    $send($fd, $c['id'], 'error', [
                        'code' => $result->error_code, 'message' => $result->reason, 'hand_id' => $context->game->id,
                        'retryable' => false,
                    ], $id);

                    return;
                }
                $key = $user.':'.$context->game->id;
                if (! isset($state->providers[$key])) {
                    $state->providers[$key] = di(PokerManager::class)->forGame($context->game->provider,
                        fn ($direction, $type, $payload, $game) => $runtime->log($user, $direction, $type, $payload,
                            $game));
                }
                $state->providerOwners[$key] = $c['id'];
                $provider = $state->providers[$key];
                $method = match ($message['type']) {
                    'game_start' => 'start',
                    'game_stage_started' => 'stageStarted',
                    'game_player_acted' => 'playerActed',
                    'game_player_cards' => 'KnownPlayerCards',
                    'game_get_solve' => 'getSolve',
                    'game_over' => 'over'
                };
                if ($method === 'getSolve') {
                    $provider->getSolve($context,
                        function (GetSolveVo $result) use ($runtime, $c, $user, $context, $id, $send, $fd, $state) {
                            try {
                                try {
                                    $this->authorize($c['token']);
                                    $active = json_decode(di(Redis::class)->get('active_token:'.hash('sha256',
                                        $c['token'])) ?: 'null', true);
                                    $authorized = $state->isCurrent($fd,
                                        $c['id']) && ($active['connection_id'] ?? null) === $c['id'];
                                } catch (Throwable) {
                                    $authorized = false;
                                }
                                $result = $runtime->complete($user, $context->game->id, $id, $result, $authorized);
                                $this->persist($user);
                                if ($result->success) {
                                    $send($fd, $c['id'], 'game_play_action', [
                                        'hand_id' => $context->game->id,
                                        'action' => ($result->action ?? throw new LogicException('Successful solve has no action'))->wire(),
                                        'amount' => $result->amount,
                                    ], $id);
                                } else {
                                    $send($fd, $c['id'], 'error', [
                                        'code' => $result->error_code, 'message' => $result->reason,
                                        'hand_id' => $context->game->id, 'retryable' => false,
                                    ], $id);
                                }
                            } catch (Throwable) {
                                $send($fd, $c['id'], 'error', [
                                    'code' => 'storage_unavailable', 'message' => '结果正在恢复',
                                    'hand_id' => $context->game->id, 'retryable' => true,
                                ], $id);
                            }
                        });
                } else {
                    $provider->$method($context);
                    if ($method === 'over') {
                        unset($state->providers[$key], $state->providerOwners[$key]);
                    }
                }
            } catch (Throwable $e) {
                $error = $e instanceof AppException ? $e : GatewayException::eventInvalid();
                $details = $e instanceof AppException ? $e->details() : [];
                $send($fd, $c['id'], 'error', [
                    'code' => $error->getErrorCode(), 'message' => $error->getMessage(),
                    'retryable' => in_array($error->getErrorCode(), ['storage_unavailable', 'state_recovering'],
                        true),
                ] + $details, $id);
                if ($c['user']) {
                    try {
                        $runtime->log($c['user'], 'CLIENT_IN', 'error',
                            ['code' => $error->getErrorCode(), 'event' => Log::redact($message ?? [])]);
                    } catch (Throwable) {
                    }
                }
                if ($c['token'] === null || in_array($error->getErrorCode(), ['auth_required', 'auth_expired'], true)) {
                    $server->disconnect($fd);
                }
            }
        };
    }

    private function authorize(string $token): int
    {
        $hash = hash('sha256', $token);
        $entry = json_decode(di(Redis::class)->get('token:'.$hash) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
        if (! $entry || $entry['expires_at'] <= time() || di(Redis::class)->exists('denied:'.$entry['user_id']) || di(Redis::class)->exists('revoked:'.$hash)) {
            throw AuthException::authExpired();
        }

        return $entry['user_id'];
    }

    private function persist(int $user): void
    {
        Coroutine::create(static function () use ($user): void {
            try {
                di(GameService::class)->flush($user);
            } catch (Throwable $error) {
                di(LoggerInterface::class)->warning('Persistence pending retry',
                    ['user_id' => $user, 'exception' => $error::class]);
            }
        });
    }
}
