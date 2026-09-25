<?php

declare(strict_types=1);

namespace App\Game\Socket;

use App\Constants\ErrorCode;
use App\Exception\GameException;
use App\Exception\ProviderException;
use Closure;
use Hyperf\Redis\Redis;
use JsonException;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

use function App\Support\di;
use function Hyperf\Translation\__;

final class ProtoWebSocketClient extends WebSocketClient
{
    private const int LEASE_TTL = 40;

    private const int SESSION_TTL = 120;

    /** @var array<string, Channel> */
    private array $pendingAnswers = [];

    private ?string $leaseOwner = null;

    private ?string $sessionId = null;

    private bool $retryFreshSession = false;

    private readonly string $leaseKey;

    private readonly string $sessionKey;

    private readonly string $token;

    /**
     * @param  array<string, mixed>  $options
     * @param  null|Closure(string, int, bool): Client  $socketFactory
     */
    public function __construct(
        int $userId,
        string $clientId,
        private readonly array $options,
        private readonly Redis $redis,
        ?Closure $socketFactory = null,
        private readonly ?string $locale = null,
    ) {
        $url = (string) ($options['url'] ?? '');
        $this->token = (string) ($options['token'] ?? '');
        if ($url === '' || $this->token === '' || $clientId === '') {
            throw new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'configuration_missing']);
        }
        $pid = (string) ($options['player_id'] ?? 'pokerx').'-'.$userId.'-'.$clientId;
        $url .= (str_contains($url, '?') ? '&' : '?').'pid='.rawurlencode($pid);
        $prefix = 'proto-ws:'.substr(hash('sha256', $url.'|'.$this->token), 0, 20).':'.$userId.':'.$clientId;
        $this->leaseKey = $prefix.':lease';
        $this->sessionKey = $prefix.':session';

        parent::__construct(
            $url,
            (float) ($options['connect_timeout'] ?? 5.0),
            (float) ($options['ping_interval'] ?? 20.0),
            (float) ($options['idle_timeout'] ?? 55.0),
            $socketFactory,
        );
    }

    public function connect(): void
    {
        if ($this->leaseOwner !== null) {
            parent::connect();

            return;
        }
        $this->leaseOwner = bin2hex(random_bytes(16));
        if ($this->redis->set($this->leaseKey, $this->leaseOwner, ['NX', 'EX' => self::LEASE_TTL]) !== true) {
            $this->leaseOwner = null;
            throw new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'lease_unavailable']);
        }
        try {
            parent::connect();
        } catch (Throwable $error) {
            $this->releaseLease();
            throw $error;
        }
    }

    public function close(): void
    {
        parent::close();
        $this->releaseLease();
    }

    /**
     * @param  array<string, mixed>  $events
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public function request(string $gameId, array $events, array $question): array
    {
        if (isset($this->pendingAnswers[$gameId])) {
            throw new GameException(__('messages.game.action_in_progress', [], $this->locale), ErrorCode::REQUEST_ACTION_IN_PROGRESS);
        }
        $answer = new Channel(1);
        $this->pendingAnswers[$gameId] = $answer;
        try {
            $this->sendMessage($events);
            $this->sendMessage($question);
            $response = $answer->pop((float) ($this->options['request_timeout'] ?? 10.0));
            if ($response instanceof Throwable) {
                throw $response;
            }
            if (! is_array($response)) {
                throw new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'action_timeout', 'game_id' => $gameId]);
            }

            return $response;
        } finally {
            unset($this->pendingAnswers[$gameId]);
            $answer->close();
        }
    }

    /** @param array<string, mixed> $message */
    public function sendMessage(array $message): void
    {
        $this->sendText(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    protected function authenticate(Client $socket): void
    {
        $previous = $this->sessionId ?? $this->redis->get($this->sessionKey);
        $previous = is_string($previous) && $previous !== '' ? $previous : null;
        $message = ['token' => $this->token, 'descr' => 'PokerX'];
        if ($previous !== null) {
            $message['sessionId'] = $previous;
        }
        if (! $socket->push(json_encode($message, JSON_THROW_ON_ERROR))) {
            throw new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'authentication_write_failed']);
        }
        $frame = $socket->recv((float) ($this->options['connect_timeout'] ?? 5.0));
        try {
            $response = $frame instanceof Frame
                ? json_decode((string) $frame->data, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $response = null;
        }
        if (! is_array($response) || ($response['result'] ?? null) !== true
            || ! is_string($response['sessionId'] ?? null) || $response['sessionId'] === '') {
            $remoteInfo = is_array($response) && is_string($response['info'] ?? null) ? $response['info'] : '';
            $info = strtolower($remoteInfo);
            if ($previous !== null && str_contains($info, 'session')
                && (str_contains($info, 'invalid') || str_contains($info, 'expired') || str_contains($info, 'not found'))) {
                $this->redis->eval(
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
                    [$this->sessionKey, $previous],
                    1,
                );
                $this->sessionId = null;
                $this->retryFreshSession = true;
            }
            throw new ProviderException(__('messages.provider.failed', [], $this->locale), ErrorCode::PROVIDER_FAILED, context: [
                'reason' => 'authentication_failed',
                'remote_info' => $this->safeDiagnostic($remoteInfo, $previous),
                'session_id_present' => $previous !== null,
            ]);
        }

        $this->sessionId = $response['sessionId'];
        $saved = $this->redis->eval(
            "if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end redis.call('setex', KEYS[2], ARGV[2], ARGV[3]) return 1",
            [$this->leaseKey, $this->sessionKey, $this->leaseOwner, self::SESSION_TTL, $this->sessionId],
            2,
        );
        if ((int) $saved !== 1) {
            throw new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'lease_lost']);
        }
    }

    protected function retryAuthentication(Throwable $error): bool
    {
        if (! $this->retryFreshSession) {
            return false;
        }
        $this->retryFreshSession = false;

        return true;
    }

    protected function handleText(string $text): void
    {
        try {
            $response = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ProviderException(__('messages.provider.failed', [], $this->locale), ErrorCode::PROVIDER_FAILED, previous: $error, context: ['reason' => 'invalid_json']);
        }
        if (! is_array($response)) {
            throw new ProviderException(__('messages.provider.failed', [], $this->locale), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'invalid_message']);
        }
        if (is_string($response['error'] ?? null)) {
            $error = new ProviderException(__('messages.provider.failed', [], $this->locale), ErrorCode::PROVIDER_FAILED, context: [
                'reason' => 'request_rejected',
                'remote_error' => $this->safeDiagnostic($response['error']),
                'game_id' => is_string($response['gameId'] ?? null) ? $response['gameId'] : null,
            ]);
            $gameId = $response['gameId'] ?? null;
            if (is_string($gameId)) {
                ($this->pendingAnswers[$gameId] ?? null)?->push($error, 0.001);
            } else {
                foreach ($this->pendingAnswers as $channel) {
                    $channel->push($error, 0.001);
                }
            }

            return;
        }
        if (($response['structType'] ?? null) === 'playerAction' && is_string($response['gameId'] ?? null)) {
            ($this->pendingAnswers[$response['gameId']] ?? null)?->push($response, 0.001);
        }
    }

    protected function onDisconnected(): void
    {
        foreach ($this->pendingAnswers as $channel) {
            $channel->push(new ProviderException(__('messages.provider.unavailable', [], $this->locale), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'connection_closed']), 0.001);
        }
    }

    protected function onConnectionFailure(Throwable $error): void
    {
        di(LoggerInterface::class)->warning('Proto WebSocket connection failed', [
            ...($error instanceof ProviderException ? $error->context() : []),
            'code' => $error instanceof ProviderException ? $error->getCode() : ErrorCode::PROVIDER_UNAVAILABLE,
            'exception' => $error,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ]);
    }

    protected function onHeartbeat(): void
    {
        if ($this->leaseOwner === null) {
            $this->close();

            return;
        }
        $renewed = $this->redis->eval(
            "if redis.call('get', KEYS[1]) ~= ARGV[1] then return 0 end redis.call('expire', KEYS[1], ARGV[2]) redis.call('expire', KEYS[2], ARGV[3]) return 1",
            [$this->leaseKey, $this->sessionKey, $this->leaseOwner, self::LEASE_TTL, self::SESSION_TTL],
            2,
        );
        if ((int) $renewed !== 1) {
            $this->close();
        }
    }

    private function releaseLease(): void
    {
        if ($this->leaseOwner === null) {
            return;
        }
        $this->redis->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
            [$this->leaseKey, $this->leaseOwner],
            1,
        );
        $this->leaseOwner = null;
    }

    private function safeDiagnostic(string $value, ?string $sessionId = null): string
    {
        $value = str_replace($this->token, '[redacted]', $value);
        if ($this->sessionId !== null) {
            $value = str_replace($this->sessionId, '[redacted]', $value);
        }

        if ($sessionId !== null) {
            $value = str_replace($sessionId, '[redacted]', $value);
        }

        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';

        return substr($value, 0, 500);
    }
}
