<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Exception\AppException;
use App\Exception\GameException;
use App\Exception\ProviderException;
use App\Vo\Game\CardVo;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Http\Client;
use Hyperf\Redis\Redis;
use JsonException;
use Throwable;

use function App\Support\di;
use function Hyperf\Translation\__;

final class ProtoHttpProvider extends BaseProvider
{
    private const int SESSION_TTL = 280;

    /** @var array<string, true> */
    private array $pendingActions = [];

    /**
     * @param  array<string, mixed>  $options
     * @param  null|Closure(string, int, bool): ClientInterface  $clientFactory
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?Closure $clientFactory = null,
        private readonly ?Redis $redis = null,
    ) {}

    public function requestAction(GameVo $game, Closure $callback): void
    {
        if (isset($this->pendingActions[$game->uuid])) {
            throw new GameException(__('messages.game.action_in_progress'), ErrorCode::REQUEST_ACTION_IN_PROGRESS);
        }
        $operationId = bin2hex(random_bytes(8));
        $startedAt = hrtime(true);
        $this->logger()->info('Proto HTTP action started', [
            'operation_id' => $operationId,
            'game_id' => $game->uuid,
            'user_id' => $game->userId,
        ]);
        $this->pendingActions[$game->uuid] = true;
        try {
            $response = $this->withSession($game, function (string $sessionId) use ($game, $operationId): array {
                $this->expectAcknowledgement($this->command($this->gameEvents($game), $operationId, $sessionId), 'gameEvents');

                return $this->command([
                    'structType' => 'getAnswer',
                    'gameId' => $game->uuid,
                    'potForAlpha' => $game->pot(),
                    'delay' => (int) ($this->options['delay'] ?? 9000),
                ], $operationId, $sessionId);
            }, $operationId);
            $result = $this->actionResult($response, $game->uuid);
            $this->logger()->info('Proto HTTP action completed', [
                'operation_id' => $operationId,
                'game_id' => $game->uuid,
                'user_id' => $game->userId,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'action' => $result->action?->name,
            ]);
        } catch (Throwable $error) {
            $this->logger()->warning('Proto HTTP action failed', [
                'operation_id' => $operationId,
                'game_id' => $game->uuid,
                'user_id' => $game->userId,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'exception' => $error,
                'error_code' => $error instanceof AppException ? $error->getCode() : null,
                ...($error instanceof AppException ? $error->context() : []),
            ]);
            $result = RequestActionResultVo::failure($error instanceof AppException
                ? $error : new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, previous: $error, context: ['reason' => 'request_exception']));
        } finally {
            unset($this->pendingActions[$game->uuid]);
        }

        $callback($result);
    }

    public function over(GameEventVo $event): void
    {
        $operationId = bin2hex(random_bytes(8));
        try {
            $this->withSession($event->game, function (string $sessionId) use ($event, $operationId): void {
                $this->expectAcknowledgement($this->command($this->gameEvents($event->game, true), $operationId, $sessionId),
                    'fullGameLog');
            }, $operationId);
            $this->logger()->info('Proto HTTP fullGameLog acknowledged', [
                'operation_id' => $operationId,
                'game_id' => $event->game->uuid,
            ]);
        } catch (Throwable $error) {
            $this->logger()->warning('Proto HTTP fullGameLog failed', [
                'operation_id' => $operationId,
                'game_id' => $event->game->uuid,
                'exception' => $error,
                'error_code' => $error instanceof AppException ? $error->getCode() : null,
                ...($error instanceof AppException ? $error->context() : []),
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $operation
     * @return T
     */
    private function withSession(GameVo $game, Closure $operation, string $operationId): mixed
    {
        $key = 'proto-http:session:user:'.$game->userId;
        $redis = $this->redis ?? di(Redis::class);
        $sessionId = $this->sessionId($redis, $key, $game, $operationId);

        try {
            $result = $operation($sessionId);
        } catch (ProviderException $error) {
            if (($error->context()['proto_http_session_invalid'] ?? false) !== true) {
                throw $error;
            }
            $this->logger()->warning('Proto HTTP session invalidated', [
                'operation_id' => $operationId,
                'game_id' => $game->uuid,
                'user_id' => $game->userId,
                'session_fingerprint' => $this->sessionFingerprint($sessionId),
            ]);
            $this->forgetSession($redis, $key, $sessionId);
            $sessionId = $this->sessionId($redis, $key, $game, $operationId);
            try {
                $result = $operation($sessionId);
            } catch (ProviderException $retryError) {
                if (($retryError->context()['proto_http_session_invalid'] ?? false) === true) {
                    $this->forgetSession($redis, $key, $sessionId);
                }

                throw $retryError;
            }
        }

        try {
            $renewed = $redis->eval(
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('expire', KEYS[1], ARGV[2]) end return 0",
                [$key, $sessionId, self::SESSION_TTL],
                1,
            );
            $this->logger()->info('Proto HTTP session renewal', [
                'operation_id' => $operationId,
                'game_id' => $game->uuid,
                'user_id' => $game->userId,
                'session_fingerprint' => $this->sessionFingerprint($sessionId),
                'renewed' => (int) $renewed === 1,
                'ttl_seconds' => self::SESSION_TTL,
            ]);
        } catch (Throwable $error) {
            try {
                $this->logger()->warning('Proto HTTP session cache renewal failed', [
                    'operation_id' => $operationId,
                    'game_id' => $game->uuid,
                    'exception' => $error::class,
                    'message' => $this->diagnosticText($error->getMessage(), $sessionId),
                ]);
            } catch (Throwable) {
                // A cache or logging failure must not discard an acknowledged action.
            }
        }

        return $result;
    }

    private function sessionId(Redis $redis, string $key, GameVo $game, string $operationId): string
    {
        $sessionId = $redis->get($key);
        if (is_string($sessionId) && $sessionId !== '') {
            $this->logger()->info('Proto HTTP session cache hit', [
                'operation_id' => $operationId,
                'game_id' => $game->uuid,
                'user_id' => $game->userId,
                'session_fingerprint' => $this->sessionFingerprint($sessionId),
            ]);

            return $sessionId;
        }

        $this->logger()->info('Proto HTTP session cache miss', [
            'operation_id' => $operationId,
            'game_id' => $game->uuid,
            'user_id' => $game->userId,
        ]);
        $token = (string) ($this->options['token'] ?? '');
        $response = $this->command(['token' => $token, 'descr' => 'PokerX'], $operationId, null, $game->uuid);
        if (($response['result'] ?? null) !== true || ! is_string($response['sessionId'] ?? null)
            || $response['sessionId'] === '') {
            throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: [
                'reason' => 'authentication_failed',
                'remote_info' => $this->diagnosticText($response['info'] ?? null),
            ]);
        }

        $sessionId = $response['sessionId'];
        $redis->setex($key, self::SESSION_TTL, $sessionId);
        $this->logger()->info('Proto HTTP session authenticated', [
            'operation_id' => $operationId,
            'game_id' => $game->uuid,
            'user_id' => $game->userId,
            'session_fingerprint' => $this->sessionFingerprint($sessionId),
            'ttl_seconds' => self::SESSION_TTL,
        ]);

        return $sessionId;
    }

    private function forgetSession(Redis $redis, string $key, string $sessionId): void
    {
        $redis->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0",
            [$key, $sessionId],
            1,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function command(array $payload, string $operationId, ?string $sessionId = null, ?string $gameId = null): array
    {
        $parts = parse_url((string) ($this->options['url'] ?? '')) ?: [];
        $ssl = ($parts['scheme'] ?? null) === 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = rtrim($parts['path'] ?? '', '/');
        if (! str_ends_with($path, '/api/command')) {
            $path .= '/api/command';
        }
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $headers = ['Content-Type' => ['application/json']];
        $pid = (string) ($this->options['player_id'] ?? '');
        if ($pid !== '') {
            $headers['X-Player-Id'] = [$pid];
            $query['pid'] = $pid;
        }
        if ($sessionId !== null) {
            $headers['X-Session-Id'] = [$sessionId];
        }

        if ($query !== []) {
            $path .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $factory = $this->clientFactory ?? static fn (
            string $host,
            int $port,
            bool $ssl
        ): ClientInterface => new Client($host, $port, $ssl);
        $settings = [
            'timeout' => (float) ($this->options['request_timeout'] ?? 10),
            'connect_timeout' => (float) ($this->options['connect_timeout'] ?? 5),
        ];
        if ($ssl) {
            $settings['ssl_verify_peer'] = true;
            $settings['ssl_host_name'] = $host;
        }
        $contents = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $command = isset($payload['token']) ? 'authenticate' : ($payload['structType'] ?? 'unknown');
        $payloadGameId = $payload['gameId'] ?? ($payload['game']['gameId'] ?? null);
        $gameId ??= is_string($payloadGameId) ? $payloadGameId : null;
        $context = [
            'operation_id' => $operationId,
            'command_id' => bin2hex(random_bytes(8)),
            'game_id' => $gameId,
            'command' => $command,
            'host' => $host,
            'port' => $port,
            'path' => strtok($path, '?'),
            'session_id_present' => $sessionId !== null,
            'session_fingerprint' => $sessionId !== null ? $this->sessionFingerprint($sessionId) : null,
            'request_bytes' => strlen($contents),
            'request_hash' => $command === 'authenticate' ? null : substr(hash('sha256', $contents), 0, 16),
            'event_count' => in_array($command, ['gameEvents', 'fullGameLog'], true) ? count($payload['events'] ?? []) : null,
            'pot_for_alpha' => $command === 'getAnswer' ? ($payload['potForAlpha'] ?? null) : null,
            'timeout_seconds' => $settings['timeout'],
            'connect_timeout_seconds' => $settings['connect_timeout'],
        ];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $requestContext = [...$context, 'attempt' => $attempt + 1];
            $this->logger()->info('Proto HTTP request', $requestContext);
            $startedAt = hrtime(true);
            $client = null;
            $stage = 'client_creation';
            try {
                $client = $factory($host, $port, $ssl);
                $stage = 'client_configuration';
                if (! $client->set($settings)) {
                    throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'client_configuration_failed']);
                }
                $stage = 'http_request';
                $response = $client->request('POST', $path, $headers, $contents);
            } catch (Throwable $error) {
                $this->logger()->warning('Proto HTTP transport failed', [
                    ...$requestContext,
                    'stage' => $stage,
                    'duration_ms' => $this->elapsedMilliseconds($startedAt),
                    'exception_class' => $error::class,
                    'exception_code' => $error->getCode(),
                    'exception_message' => $this->diagnosticText($error->getMessage(), $sessionId),
                ]);

                throw $error;
            } finally {
                if ($client instanceof Client) {
                    $client->close();
                }
            }
            $raw = $response->getBody();
            $body = null;
            $jsonError = null;
            try {
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                $jsonError = $error;
            }
            $responseContext = [
                ...$requestContext,
                'http_status' => $response->getStatusCode(),
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'response_bytes' => strlen($raw),
                'response_hash' => substr(hash('sha256', $raw), 0, 16),
                'content_type' => $this->responseHeader($response->getHeaders(), 'content-type', $sessionId),
                'upstream_request_id' => $this->responseHeader($response->getHeaders(), 'x-request-id', $sessionId),
                'json_type' => $jsonError !== null ? 'invalid'
                    : (is_array($body) ? (str_starts_with(ltrim($raw), '[') ? 'array' : 'object') : get_debug_type($body)),
                'json_error' => $jsonError?->getMessage(),
                'remote_result' => is_bool($body['result'] ?? null) ? $body['result'] : null,
                'remote_struct_type' => $this->diagnosticText($body['structType'] ?? null, $sessionId),
                'remote_game_id' => $this->diagnosticText($body['gameId'] ?? null, $sessionId),
                'remote_error' => $this->diagnosticText($body['error'] ?? null, $sessionId),
                'remote_info' => $this->diagnosticText($body['info'] ?? null, $sessionId),
            ];
            $this->logger()->info('Proto HTTP response', $responseContext);
            if ($response->getStatusCode() === 502) {
                if ($attempt === 0) {
                    $this->logger()->warning('Proto HTTP retry', [...$requestContext, 'reason' => 'http_502']);

                    continue;
                }

                throw new ProviderException(__('messages.provider.unavailable'), ErrorCode::PROVIDER_UNAVAILABLE, context: ['reason' => 'http_502']);
            }

            if ($jsonError !== null) {
                if ($attempt === 0) {
                    $this->logger()->warning('Proto HTTP retry', [...$requestContext, 'reason' => 'invalid_json']);

                    continue;
                }

                throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, previous: $jsonError, context: ['reason' => 'invalid_json']);
            }
            if (! is_array($body)) {
                if ($attempt === 0) {
                    $this->logger()->warning('Proto HTTP retry', [...$requestContext, 'reason' => 'invalid_response']);

                    continue;
                }

                throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'invalid_response']);
            }
            if (($body['error'] ?? null) === 'invalid sessionId') {
                throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['proto_http_session_invalid' => true, 'reason' => 'invalid_session']);
            }
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || isset($body['error'])) {
                $reason = $body['error'] ?? 'HTTP '.$response->getStatusCode();
                throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: [
                    'reason' => 'request_rejected',
                    'http_status' => $response->getStatusCode(),
                    'remote_error' => $this->diagnosticText($reason, $sessionId),
                ]);
            }

            return $body;
        }

        throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'request_failed']);
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    private function sessionFingerprint(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 16);
    }

    private function diagnosticText(mixed $value, ?string $sessionId = null): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $secrets = array_values(array_filter([
            $this->options['token'] ?? null,
            $sessionId,
        ], static fn (mixed $secret): bool => is_string($secret) && $secret !== ''));
        $message = str_replace($secrets, '[redacted]', $value);
        $message = preg_replace('/[\x00-\x1F\x7F]/', ' ', $message) ?? '[invalid text]';

        return substr($message, 0, 200);
    }

    /** @param array<array<string>> $headers */
    private function responseHeader(array $headers, string $name, ?string $sessionId): ?string
    {
        foreach ($headers as $header => $values) {
            if (is_string($header) && strcasecmp($header, $name) === 0) {
                foreach ($values as $value) {
                    return $this->diagnosticText($value, $sessionId);
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $response */
    private function expectAcknowledgement(array $response, string $command): void
    {
        if (($response['result'] ?? null) !== true) {
            throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'not_acknowledged', 'command' => $command]);
        }
    }

    /** @param  array<string, mixed>  $response */
    private function actionResult(array $response, string $gameId): RequestActionResultVo
    {
        if (($response['structType'] ?? null) !== 'playerAction' || ($response['gameId'] ?? null) !== $gameId) {
            throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'unexpected_action']);
        }
        $action = self::actionToEnum($response['action'] ?? '');

        return RequestActionResultVo::success($action, $response['amount'] ?? 0);
    }

    /** @return array<string, mixed> */
    public function gameEvents(GameVo $game, bool $over = false): array
    {
        if ($over && $game->status->isAbort()) {
            throw new GameException(__('messages.game.event_invalid'), ErrorCode::EVENT_INVALID);
        }
        $events = [];
        $hero = $game->hero();
        foreach ($game->players as $player) {
            $events[] = [
                'eventType' => 'playerSeated',
                'seat' => $player->seatNumber,
                'name' => $player->uid,
                'stack' => $player->stack,
            ];
        }
        foreach ($game->players as $player) {
            if ($player->ante > 0) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $player->uid,
                    'blindType' => 'ANTE',
                    'amount' => $player->ante,
                ];
            }
        }
        foreach ($game->players as $player) {
            if ($player->blind > 0) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $player->uid,
                    'blindType' => $player->isSb() ? 'SB' : 'BB',
                    'amount' => $player->blind,
                ];
            }
        }
        foreach ($game->events->sortBy('timestamp') as $event) {
            if ($event->type->isPostBlind()) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $event->payload['uid'],
                    'blindType' => 'POST',
                    'amount' => $event->payload['amount'],
                ];

                continue;
            }
            if ($event->type->isStraddleBlind()) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $event->payload['uid'],
                    'blindType' => 'STRADDLE',
                    'amount' => $event->payload['amount'],
                ];

                continue;
            }
            if ($event->type->isStage()) {
                $stage = StageEnum::fromNameOrFail(strtoupper($event->payload['stage']));
                $cards = $event->payload['cards'] ?? [];
                $events[] = [
                    'eventType' => 'stageStarted',
                    'stage' => strtolower($stage->name),
                    'cards' => CardVo::cardsToShort($cards),
                ];

                continue;
            }
            if ($event->type->isDealt()) {
                $events[] = [
                    'eventType' => 'handDealt',
                    'name' => $hero->uid,
                    'cards' => CardVo::cardsToShort($event->payload['cards']),
                ];

                continue;
            }
            if ($event->type->isAction()) {
                $events[] = [
                    'eventType' => 'playerActed',
                    'name' => $event->payload['uid'],
                    'action' => self::enumToAction(ActionEnum::fromNameOrFail($event->payload['action'])),
                    'amount' => $event->payload['amount'] ?? 0,
                ];

                continue;
            }
            if ($event->type->isShow()) {
                $events[] = [
                    'eventType' => 'knownPlayerCards',
                    'name' => $event->payload['uid'],
                    'cards' => CardVo::cardsToShort($event->payload['cards']),
                ];

                continue;
            }
        }

        if ($over) {
            foreach ($game->players as $player) {
                if ($player->winnings + $player->returned > 0) {
                    $events[] = [
                        'eventType' => 'playerWon',
                        'name' => $player->uid,
                        'amount' => $player->winnings + $player->returned,
                    ];
                }
            }
            foreach ($game->players as $player) {
                if ($player->cards) {
                    $events[] = [
                        'eventType' => 'handShown',
                        'name' => $player->uid,
                        'cards' => CardVo::cardsToShort($player->cards),
                    ];
                } else {
                    $events[] = [
                        'eventType' => 'noHandShown',
                        'name' => $player->uid,
                    ];
                }
            }
            $events[] = [
                'eventType' => 'gameOver',
            ];
        }

        return [
            'structType' => $over ? 'fullGameLog' : 'gameEvents',
            'game' => [
                'gameId' => $game->uuid,
                'pokerNetwork' => $this->options['network'] ?? 'WE',
                'gameType' => 'NL',
                'bigBlind' => $game->bigBlind,
                'ante' => $game->ante,
                'currency' => $this->options['currency'] ?? 'USDT',
                'gameDate' => (string) $game->createdAtMs,
                'numPlayers' => count($game->players),
                'buttonSetToSeat' => $game->buttonSeatNumber,
            ],
            'events' => $events,
        ];
    }

    private static function enumToAction(ActionEnum $action): string
    {
        return match ($action) {
            ActionEnum::ALL_IN => 'all-in',
            default => strtolower($action->name),
        };
    }

    private static function actionToEnum(string $action): ActionEnum
    {
        return match (strtolower($action)) {
            'fold' => ActionEnum::FOLD,
            'check' => ActionEnum::CHECK,
            'call' => ActionEnum::CALL,
            'bet' => ActionEnum::BET,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            default => throw new ProviderException(__('messages.provider.failed'), ErrorCode::PROVIDER_FAILED, context: ['reason' => 'unsupported_action', 'action' => $action]),
        };
    }
}
