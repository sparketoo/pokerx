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
use Throwable;

use function Hyperf\Translation\__;

final class ProtoProvider extends BaseProvider
{
    /** @var array<string, ProtoWebSocketClient> */
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

    public function disconnect(GameServerConnectionVo $connection): void
    {
        $key = $this->key($connection->user->id, $connection->token->id);
        if (! empty($this->clients[$key])) {
            $this->clients[$key]->close();
            unset($this->clients[$key]);
        }
    }

    public function requestAction(GameVo $game, Closure $callback): void
    {
        $response = $this->client($game)->request($game->uuid, $this->messages->gameEvents($game), [
            'structType' => 'getAnswer',
            'gameId' => $game->uuid,
            'potForAlpha' => $game->pot(),
            'delay' => (int) ($this->options['delay'] ?? 9000),
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
        $key = $this->key($game->userId, $game->tokenId);
        if (empty($this->clients[$key])) {
            $client = new ProtoWebSocketClient($key, $this->options, $this->redis);

            try {
                $client->connect();
            } catch (Throwable $error) {
                $client->close();
                throw $error;
            }

            $this->clients[$key] = $client;
        }

        return $this->clients[$key];
    }

    private function key(int $userId, int $tokenId): string
    {
        return $userId.':'.$tokenId;
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
