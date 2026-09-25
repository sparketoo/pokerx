<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Exception\AppException;
use App\Exception\ProviderException;
use App\Game\Socket\ProtoWebSocketClient;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Hyperf\Redis\Redis;
use Throwable;

use function Hyperf\Translation\__;

final class ProtoProvider extends BaseProvider
{
    /** @var array<string, array{fd: int, client: ProtoWebSocketClient}> */
    private array $clients = [];

    private readonly ProtoHttpProvider $messages;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly array $options,
        private readonly Redis $redis,
    ) {
        $this->messages = new ProtoHttpProvider($options);
    }

    public function connect(int $fd, int $userId, string $clientId, ?string $locale = null): void
    {
        $key = $this->key($userId, $clientId);
        if (isset($this->clients[$key])) {
            throw new ProviderException(__('messages.provider.unavailable', [], $locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'client_already_connected']);
        }
        $client = new ProtoWebSocketClient($userId, $clientId, $this->options, $this->redis, locale: $locale);
        $this->clients[$key] = ['fd' => $fd, 'client' => $client];
        try {
            $client->connect();
        } catch (Throwable $error) {
            if ($this->clientForKey($key) === $client) {
                unset($this->clients[$key]);
            }
            $client->close();
            throw $error;
        }
    }

    public function disconnect(int $fd, int $userId, string $clientId): void
    {
        $key = $this->key($userId, $clientId);
        $entry = $this->clients[$key] ?? null;
        if ($entry === null || $entry['fd'] !== $fd) {
            return;
        }
        unset($this->clients[$key]);
        $entry['client']->close();
    }

    public function requestAction(GameVo $game, Closure $callback): void
    {
        try {
            $response = $this->client($game)->request($game->uuid, $this->messages->gameEvents($game), [
                'structType' => 'getAnswer',
                'gameId' => $game->uuid,
                'potForAlpha' => $game->pot(),
                'delay' => (int) ($this->options['delay'] ?? 9000),
            ]);
            $result = $this->actionResult($response, $game->uuid);
        } catch (Throwable $error) {
            $result = RequestActionResultVo::failure($error instanceof AppException
                ? $error : new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, previous: $error, context: ['reason' => 'request_exception']));
        }

        $callback($result);
    }

    public function over(GameEventVo $event): void
    {
        try {
            $this->client($event->game)->sendMessage($this->messages->gameEvents($event->game, true));
        } catch (Throwable $error) {
            $this->logger()->warning('Proto WebSocket fullGameLog failed', [
                'game_id' => $event->game->uuid,
                'exception' => $error,
            ]);
        }
    }

    private function client(GameVo $game): ProtoWebSocketClient
    {
        if ($game->clientId === null) {
            throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'game_client_missing']);
        }

        return $this->clients[$this->key($game->userId, $game->clientId)]['client']
            ?? throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'game_client_missing']);
    }

    private function key(int $userId, string $clientId): string
    {
        return $userId.':'.$clientId;
    }

    private function clientForKey(string $key): ?ProtoWebSocketClient
    {
        return $this->clients[$key]['client'] ?? null;
    }

    /** @param array<string, mixed> $response */
    private function actionResult(array $response, string $gameId): RequestActionResultVo
    {
        if (($response['structType'] ?? null) !== 'playerAction' || ($response['gameId'] ?? null) !== $gameId
            || ! is_string($response['action'] ?? null)) {
            throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'unexpected_action']);
        }
        $action = match (strtolower($response['action'])) {
            'fold' => ActionEnum::FOLD,
            'check' => ActionEnum::CHECK,
            'call' => ActionEnum::CALL,
            'bet' => ActionEnum::BET,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            default => throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'unsupported_action', 'action' => $response['action']]),
        };

        return RequestActionResultVo::success($action, $response['amount'] ?? 0);
    }
}
