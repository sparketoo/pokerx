<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Exception\ProviderException;
use App\Game\Socket\ProtoWebSocketClient;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Hyperf\Redis\Redis;
use Swoole\Coroutine\Http\Client;
use Throwable;

use function Hyperf\Translation\__;

final class ProtoProvider extends BaseProvider
{
    /** @var array<string, array{connection: GameServerConnectionVo, client: ProtoWebSocketClient}> */
    private array $clients = [];

    private readonly ProtoHttpProvider $messages;

    /**
     * @param  array<string, mixed>  $options
     * @param  null|Closure(string, int, bool): Client  $socketFactory
     */
    public function __construct(
        private readonly array $options,
        private readonly Redis $redis,
        private readonly ?Closure $socketFactory = null,
    ) {
        $this->messages = new ProtoHttpProvider($options);
    }

    public function disconnect(GameServerConnectionVo $connection): void
    {
        $entry = $this->clients[$connection->clientId] ?? null;
        if ($entry !== null && $entry['connection'] === $connection) {
            unset($this->clients[$connection->clientId]);
            $entry['client']->close();
        }
    }

    public function connect(GameServerConnectionVo $connection): void
    {
        if (isset($this->clients[$connection->clientId])) {
            throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE,
                context: ['reason' => 'client_already_connected']);
        }
        $clientKey = hash('sha256', $connection->clientId);
        $client = new ProtoWebSocketClient($clientKey, $this->options, $this->redis, $this->socketFactory);

        try {
            $client->connect();
        } catch (Throwable $error) {
            $client->close();
            throw $error;
        }

        $this->clients[$connection->clientId] = [
            'connection' => $connection,
            'client' => $client,
        ];
    }

    public function requestAction(GameVo $game, Closure $callback): void
    {
        $response = $this->client($game)->request($game->uuid, $this->messages->gameEvents($game), [
            'structType' => 'getAnswer',
            'gameId' => $game->uuid,
            'potForAlpha' => $game->pot(),
            'delay' => (int) ($this->options['delay'] ?? 15000),
        ]);
        $result = $this->actionResult($response, $game->uuid);

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
        $entry = $this->clients[$game->clientId] ?? null;
        if ($entry === null) {
            throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE,
                context: ['reason' => 'client_not_connected']);
        }

        return $entry['client'];
    }

    /** @param  array<string, mixed>  $response */
    private function actionResult(array $response, string $gameId): RequestActionResultVo
    {
        if (($response['structType'] ?? null) !== 'playerAction' || ($response['gameId'] ?? null) !== $gameId
            || ! is_string($response['action'] ?? null)) {
            throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED,
                context: ['reason' => 'unexpected_action']);
        }
        $action = match (strtolower($response['action'])) {
            'fold' => ActionEnum::FOLD,
            'check' => ActionEnum::CHECK,
            'call' => ActionEnum::CALL,
            'bet' => ActionEnum::BET,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            default => throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED,
                context: ['reason' => 'unsupported_action', 'action' => $response['action']]),
        };

        return RequestActionResultVo::success($action, $response['amount'] ?? 0);
    }
}
