<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Exception\AppException;
use App\Game\Providers\ProtoHttpProvider;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Di\Container;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Contract\Http\RawResponseInterface;
use Hyperf\Engine\Http\RawResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\TestCase;

use function App\Support\di;

final class ProtoHttpProviderTest extends TestCase
{
    public function test_authentication_action_and_full_game_log_use_one_session(): void
    {
        $replies = [
            ['result' => true, 'sessionId' => 'session-123'],
            ['result' => true],
            [
                'structType' => 'playerAction', 'gameId' => '12345678-90ab-4cde-8f01-23456789abcd',
                'action' => 'all-In', 'amount' => 100,
            ],
            ['result' => true],
        ];
        $client = new class($replies) implements ClientInterface
        {
            /** @var list<array{method: string, path: string, headers: array<string, list<string>>, contents: string}> */
            public array $requests = [];

            /** @var list<array<string, mixed>> */
            public array $settings = [];

            /** @param  list<array<string, mixed>>  $replies */
            public function __construct(private array $replies) {}

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                $this->settings[] = $settings;

                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $this->requests[] = compact('method', 'path', 'headers', 'contents');

                return new RawResponse(200, [], json_encode(array_shift($this->replies), JSON_THROW_ON_ERROR),
                    $version);
            }
        };
        $endpoints = [];
        $provider = new ProtoHttpProvider([
            'url' => 'https://proto.example',
            'player_id' => 'hero-1',
            'token' => 'test-token',
            'network' => 'WE',
        ], static function (string $host, int $port, bool $ssl) use ($client, &$endpoints): ClientInterface {
            $endpoints[] = compact('host', 'port', 'ssl');

            return $client;
        }, new FakeProtoHttpRedis);
        $game = $this->game();
        $result = null;
        $provider->requestAction($game, static function (RequestActionResultVo $answer) use (&$result): void {
            $result = $answer;
        });

        self::assertInstanceOf(RequestActionResultVo::class, $result);
        self::assertTrue($result->success);
        self::assertSame(ActionEnum::ALL_IN, $result->action);
        self::assertSame(100, $result->amount);

        $game->status = GameStatusEnum::OVER;
        $over = new GameEventVo($game, GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero-1', 'amount' => 200]],
        ], 1, null);
        $game->events->push($over);
        $provider->over($over);

        self::assertCount(4, $client->requests);
        foreach ($client->requests as $index => $request) {
            self::assertSame('POST', $request['method']);
            self::assertSame('/api/command?pid=hero-1', $request['path']);
            self::assertSame(['hero-1'], $request['headers']['X-Player-Id']);
            self::assertSame(['application/json'], $request['headers']['Content-Type']);
            self::assertSame($index === 0 ? null : ['session-123'], $request['headers']['X-Session-Id'] ?? null);
            self::assertSame(['host' => 'proto.example', 'port' => 443, 'ssl' => true], $endpoints[$index] ?? null);
            self::assertTrue($client->settings[$index]['ssl_verify_peer'] ?? false);
            self::assertSame(10.0, $client->settings[$index]['timeout']);
            self::assertSame(5.0, $client->settings[$index]['connect_timeout']);
        }
        self::assertSame('test-token', $this->body($client->requests[0])['token']);
        self::assertSame('gameEvents', $this->body($client->requests[1])['structType']);
        self::assertSame('getAnswer', $this->body($client->requests[2])['structType']);
        self::assertSame('fullGameLog', $this->body($client->requests[3])['structType']);
        self::assertSame('gameOver',
            $this->body($client->requests[3])['events'][count($this->body($client->requests[3])['events']) - 1]['eventType']);
    }

    public function test_http_error_is_returned_through_callback(): void
    {
        $client = new class implements ClientInterface
        {
            /** @var list<array{method: string, path: string, headers: array<string, list<string>>, contents: string}> */
            public array $requests = [];

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $this->requests[] = compact('method', 'path', 'headers', 'contents');
                $body = json_decode($contents, true);

                return ($body['token'] ?? null) === 'test-token'
                    ? new RawResponse(200, [], '{"result":true,"sessionId":"session-123"}', $version)
                    : new RawResponse(400, [], '{"error":"invalid sessionId"}', $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example/api/command', 'token' => 'test-token'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            new FakeProtoHttpRedis,
        );
        $result = null;
        $provider->requestAction($this->game(), static function (RequestActionResultVo $answer) use (&$result): void {
            $result = $answer;
        });

        self::assertInstanceOf(RequestActionResultVo::class, $result);
        self::assertFalse($result->success);
        self::assertInstanceOf(AppException::class, $result->exception);
        self::assertSame(4001, $result->exception->getCode());
        self::assertCount(4, $client->requests);
    }

    public function test_502_or_invalid_json_retries_only_the_failed_command_once(): void
    {
        foreach ([
            [502, 'Bad Gateway', 'gameEvents'], [200, '{invalid', 'getAnswer'], [200, 'null', 'gameEvents'],
        ] as [$status, $body, $failedCommand]) {
            $client = new class($status, $body, $failedCommand) implements ClientInterface
            {
                /** @var list<array{body: array<string, mixed>, headers: array<string, list<string>>}> */
                public array $requests = [];

                private bool $failed = false;

                public function __construct(
                    private readonly int $status,
                    private readonly string $body,
                    private readonly string $failedCommand,
                ) {}

                /** @param  array<string, mixed>  $settings */
                public function set(array $settings): bool
                {
                    return true;
                }

                /** @param  array<string, list<string>>  $headers */
                public function request(
                    string $method = 'GET',
                    string $path = '/',
                    array $headers = [],
                    string $contents = '',
                    string $version = '1.1'
                ): RawResponseInterface {
                    $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    $this->requests[] = ['body' => $payload, 'headers' => $headers];
                    if (isset($payload['token'])) {
                        return new RawResponse(200, [], '{"result":true,"sessionId":"session-1"}', $version);
                    }
                    if ($payload['structType'] === $this->failedCommand && ! $this->failed) {
                        $this->failed = true;

                        return new RawResponse($this->status, [], $this->body, $version);
                    }
                    if ($payload['structType'] === 'gameEvents') {
                        return new RawResponse(200, [], '{"result":true}', $version);
                    }

                    return new RawResponse(200, [], json_encode([
                        'structType' => 'playerAction', 'gameId' => $payload['gameId'], 'action' => 'fold',
                    ], JSON_THROW_ON_ERROR), $version);
                }
            };
            $provider = new ProtoHttpProvider(
                ['url' => 'https://proto.example', 'token' => 'test-token'],
                static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
                new FakeProtoHttpRedis,
            );

            $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
                self::assertTrue($result->success);
            });

            self::assertCount(4, $client->requests);
            $failedIndex = $failedCommand === 'gameEvents' ? 1 : 2;
            self::assertSame($client->requests[$failedIndex], $client->requests[$failedIndex + 1]);
            self::assertSame('gameEvents', $client->requests[1]['body']['structType']);
            self::assertSame('getAnswer', $client->requests[3]['body']['structType']);
        }
    }

    public function test_retryable_response_is_not_sent_more_than_twice(): void
    {
        foreach ([[502, 'Bad Gateway'], [200, '{invalid']] as [$status, $body]) {
            $client = new class($status, $body) implements ClientInterface
            {
                /** @var list<array<string, mixed>> */
                public array $requests = [];

                public function __construct(private readonly int $status, private readonly string $body) {}

                /** @param  array<string, mixed>  $settings */
                public function set(array $settings): bool
                {
                    return true;
                }

                /** @param  array<string, list<string>>  $headers */
                public function request(
                    string $method = 'GET',
                    string $path = '/',
                    array $headers = [],
                    string $contents = '',
                    string $version = '1.1'
                ): RawResponseInterface {
                    $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    $this->requests[] = $payload;

                    return isset($payload['token'])
                        ? new RawResponse(200, [], '{"result":true,"sessionId":"session-1"}', $version)
                        : new RawResponse($this->status, [], $this->body, $version);
                }
            };
            $provider = new ProtoHttpProvider(
                ['url' => 'https://proto.example', 'token' => 'test-token'],
                static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
                new FakeProtoHttpRedis,
            );

            $result = null;
            $provider->requestAction($this->game(),
                static function (RequestActionResultVo $answer) use (&$result): void {
                    $result = $answer;
                });

            self::assertInstanceOf(RequestActionResultVo::class, $result);
            self::assertFalse($result->success);
            self::assertCount(3, $client->requests);
            self::assertSame($client->requests[1], $client->requests[2]);
        }
    }

    public function test_diagnostic_logs_link_retries_and_session_recovery_without_exposing_credentials(): void
    {
        $container = di(Container::class);
        $originalLogger = $container->get(LoggerInterface::class);
        $handler = new TestHandler;
        $logger = new Logger('proto-test');
        $logger->pushHandler($handler);
        $container->set(LoggerInterface::class, $logger);

        try {
            $client = new class implements ClientInterface
            {
                public int $authentications = 0;

                public int $answers = 0;

                /** @param  array<string, mixed>  $settings */
                public function set(array $settings): bool
                {
                    return true;
                }

                /** @param  array<string, list<string>>  $headers */
                public function request(
                    string $method = 'GET',
                    string $path = '/',
                    array $headers = [],
                    string $contents = '',
                    string $version = '1.1'
                ): RawResponseInterface {
                    $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($payload['token'])) {
                        $this->authentications++;

                        return new RawResponse(200, [], json_encode([
                            'result' => true, 'sessionId' => 'session-'.$this->authentications,
                        ], JSON_THROW_ON_ERROR), $version);
                    }
                    if ($payload['structType'] === 'gameEvents') {
                        return new RawResponse(200, [], '{"result":true}', $version);
                    }
                    $this->answers++;

                    return match ($this->answers) {
                        1 => new RawResponse(502, [], 'Bad Gateway secret-token session-1', $version),
                        2 => new RawResponse(400, [], '{"error":"invalid sessionId","info":"secret-token session-1"}',
                            $version),
                        default => new RawResponse(200, [], '{"result":false,"info":"No token data"}', $version),
                    };
                }
            };
            $provider = new ProtoHttpProvider(
                ['url' => 'https://proto.example', 'token' => 'secret-token'],
                static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
                new FakeProtoHttpRedis,
            );

            $result = null;
            $provider->requestAction($this->game(),
                static function (RequestActionResultVo $answer) use (&$result): void {
                    $result = $answer;
                });

            self::assertInstanceOf(RequestActionResultVo::class, $result);
            self::assertFalse($result->success);
            self::assertSame(2, $client->authentications);
            self::assertSame(3, $client->answers);

            $records = $handler->getRecords();
            $requests = array_values(array_filter($records,
                static fn ($record): bool => $record->message === 'Proto HTTP request'));
            $responses = array_values(array_filter($records,
                static fn ($record): bool => $record->message === 'Proto HTTP response'));
            $retries = array_values(array_filter($records,
                static fn ($record): bool => $record->message === 'Proto HTTP retry'));
            $sessions = array_values(array_filter($records,
                static fn ($record): bool => $record->message === 'Proto HTTP session invalidated'));
            $failures = array_values(array_filter($records,
                static fn ($record): bool => $record->message === 'Proto HTTP action failed'));

            self::assertCount(7, $requests);
            self::assertCount(7, $responses);
            self::assertCount(1, $retries);
            self::assertCount(1, $sessions);
            self::assertCount(1, $failures);
            $operationId = $requests[0]->context['operation_id'];
            self::assertNotEmpty($operationId);
            foreach ([$requests, $responses, $retries, $sessions, $failures] as $group) {
                foreach ($group as $record) {
                    self::assertSame($operationId, $record->context['operation_id']);
                }
            }
            self::assertSame(502, $responses[2]->context['http_status']);
            self::assertSame('invalid', $responses[2]->context['json_type']);
            self::assertSame('http_502', $retries[0]->context['reason']);
            self::assertSame(1, $requests[2]->context['attempt']);
            self::assertSame(2, $requests[3]->context['attempt']);
            self::assertSame($requests[2]->context['request_hash'], $requests[3]->context['request_hash']);
            self::assertTrue($requests[2]->context['session_id_present']);
            self::assertSame('object', $responses[6]->context['json_type']);
            self::assertSame('No token data', $responses[6]->context['remote_info']);

            $logged = json_encode(array_map(static fn ($record): array => [
                $record->message, $record->context,
            ], $records), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret-token', $logged);
            self::assertStringNotContainsString('session-1', $logged);
            self::assertStringNotContainsString('session-2', $logged);
            self::assertStringNotContainsString('Bad Gateway', $logged);
        } finally {
            $container->set(LoggerInterface::class, $originalLogger);
        }
    }

    public function test_session_is_shared_across_provider_instances_and_renewed_for_280_seconds(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            /** @var list<array{body: array<string, mixed>, headers: array<string, list<string>>}> */
            public array $requests = [];

            public int $authentications = 0;

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $this->requests[] = ['body' => $body, 'headers' => $headers];
                if (isset($body['token'])) {
                    $this->authentications++;
                    $response = ['result' => true, 'sessionId' => 'session-'.$this->authentications];
                } elseif ($body['structType'] === 'getAnswer') {
                    $response = ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold'];
                } else {
                    $response = ['result' => true];
                }

                return new RawResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR), $version);
            }
        };
        $options = ['url' => 'https://proto.example', 'token' => 'test-token', 'player_id' => 'fixed-pid'];
        $factory = static fn (string $host, int $port, bool $ssl): ClientInterface => $client;
        $first = new ProtoHttpProvider($options, $factory, $redis);
        $second = new ProtoHttpProvider($options, $factory, $redis);

        $first->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });
        self::assertSame(1, $client->authentications);
        $key = 'proto-http:session:user:1';
        self::assertSame('session-1', $redis->values[$key]);
        self::assertSame(280, $redis->ttl($key));

        $redis->advance(200);
        $second->requestAction($this->game('second-game'), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });
        self::assertSame(1, $client->authentications);
        self::assertSame(280, $redis->ttl($key));
        self::assertSame(['session-1'], $client->requests[4]['headers']['X-Session-Id']);
        self::assertSame(['fixed-pid'], $client->requests[4]['headers']['X-Player-Id']);

        $redis->advance(281);
        $first->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });
        self::assertSame(2, $client->authentications);
    }

    public function test_failed_request_does_not_renew_cached_session(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $authentications = 0;

            public bool $failNextGameEvents = false;

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['token'])) {
                    $this->authentications++;

                    return new RawResponse(200, [], json_encode([
                        'result' => true, 'sessionId' => 'session-'.$this->authentications,
                    ], JSON_THROW_ON_ERROR), $version);
                }
                if (($body['structType'] ?? null) === 'gameEvents' && $this->failNextGameEvents) {
                    $this->failNextGameEvents = false;

                    return new RawResponse(500, [], '{"error":"backend unavailable"}', $version);
                }

                return new RawResponse(200, [], json_encode(
                    ($body['structType'] ?? null) === 'getAnswer'
                        ? ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold']
                        : ['result' => true],
                    JSON_THROW_ON_ERROR,
                ), $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );
        $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        $redis->advance(200);
        $client->failNextGameEvents = true;
        $provider->requestAction($this->game('failed-game'), static function (RequestActionResultVo $result): void {
            self::assertFalse($result->success);
        });
        $key = 'proto-http:session:user:1';
        self::assertSame(80, $redis->ttl($key));

        $redis->advance(81);
        $provider->requestAction($this->game('next-game'), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });
        self::assertSame(2, $client->authentications);
    }

    public function test_cache_renewal_failure_does_not_discard_a_successful_action(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $requests = 0;

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $this->requests++;
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                $response = isset($body['token'])
                    ? ['result' => true, 'sessionId' => 'session-1']
                    : (($body['structType'] ?? null) === 'getAnswer'
                        ? ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold']
                        : ['result' => true]);

                return new RawResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR), $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );
        $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        $redis->advance(200);
        $redis->failNextExpire = true;
        $provider->requestAction($this->game('second-game'), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        self::assertSame(80, $redis->ttl('proto-http:session:user:1'));
        self::assertSame(5, $client->requests);
    }

    public function test_invalid_remote_session_is_reauthenticated_and_retried_once(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $authentications = 0;

            public int $requests = 0;

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $this->requests++;
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['token'])) {
                    $this->authentications++;
                    $response = ['result' => true, 'sessionId' => 'session-'.$this->authentications];
                } elseif (($headers['X-Session-Id'][0] ?? null) === 'session-1') {
                    return new RawResponse(400, [], '{"error":"invalid sessionId"}', $version);
                } elseif ($body['structType'] === 'getAnswer') {
                    $response = ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold'];
                } else {
                    $response = ['result' => true];
                }

                return new RawResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR), $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token', 'player_id' => 'fixed-pid'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );
        $firstResult = null;
        $provider->requestAction($this->game(),
            static function (RequestActionResultVo $result) use (&$firstResult): void {
                $firstResult = $result;
            });
        self::assertInstanceOf(RequestActionResultVo::class, $firstResult);
        self::assertTrue($firstResult->success);
        self::assertSame(2, $client->authentications);
        self::assertSame(5, $client->requests);
        self::assertSame(['session-2'], array_values($redis->values));
    }

    public function test_invalid_session_does_not_delete_a_newer_cached_session(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class($redis) implements ClientInterface
        {
            public int $authentications = 0;

            public function __construct(private readonly FakeProtoHttpRedis $redis) {}

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['token'])) {
                    $this->authentications++;

                    return new RawResponse(200, [], '{"result":true,"sessionId":"session-1"}', $version);
                }
                if (($headers['X-Session-Id'][0] ?? null) === 'session-1') {
                    $this->redis->setex('proto-http:session:user:1', 280, 'session-2');

                    return new RawResponse(400, [], '{"error":"invalid sessionId"}', $version);
                }

                return new RawResponse(200, [], json_encode(
                    ($body['structType'] ?? null) === 'getAnswer'
                        ? ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold']
                        : ['result' => true],
                    JSON_THROW_ON_ERROR,
                ), $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );

        $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        self::assertSame(1, $client->authentications);
        self::assertSame('session-2', $redis->values['proto-http:session:user:1']);
    }

    public function test_different_users_have_separate_sessions(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $authentications = 0;

            /** @param  array<string, mixed>  $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param  array<string, list<string>>  $headers */
            public function request(
                string $method = 'GET',
                string $path = '/',
                array $headers = [],
                string $contents = '',
                string $version = '1.1'
            ): RawResponseInterface {
                $body = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
                if (isset($body['token'])) {
                    $this->authentications++;
                    $response = ['result' => true, 'sessionId' => 'session-'.$this->authentications];
                } elseif ($body['structType'] === 'getAnswer') {
                    $response = ['structType' => 'playerAction', 'gameId' => $body['gameId'], 'action' => 'fold'];
                } else {
                    $response = ['result' => true];
                }

                return new RawResponse(200, [], json_encode($response, JSON_THROW_ON_ERROR), $version);
            }
        };
        $provider = new ProtoHttpProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );
        $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });
        $otherGame = $this->game('another-game', 2);
        $provider->requestAction($otherGame, static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        self::assertSame(2, $client->authentications);
    }

    public function test_post_blind_is_sent_as_blind_posted_for_its_player_before_preflop(): void
    {
        $game = new GameVo(1, '12345678-90ab-4cde-8f01-23456789abce', NetworkEnum::OK, 'room#129', 2, 1, 2, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 190, 'hero' => true],
            ['uid' => 'small', 'seat' => 2, 'stack' => 190, 'hero' => false],
            ['uid' => 'big', 'seat' => 3, 'stack' => 190, 'hero' => false],
            ['uid' => 'other', 'seat' => 4, 'stack' => 190, 'hero' => false],
        ], 1, 1);
        $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'other', 'amount' => 2], 1);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], 2);
        $provider = new ProtoHttpProvider(['url' => 'https://proto.example']);

        $events = $provider->gameEvents($game)['events'];
        self::assertSame([
            'eventType' => 'blindPosted', 'name' => 'other', 'blindType' => 'POST', 'amount' => 2,
        ], $events[10]);
        self::assertSame('stageStarted', $events[11]['eventType']);
    }

    public function test_straddle_keeps_broadcast_order_and_returns_are_included_in_full_game_log_wins(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], 1);
        $game->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'hero-1', 'amount' => 200], 2);
        $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero-1', 'amount' => 300]],
            'returns' => [['uid' => 'hero-1', 'amount' => 25], ['uid' => 'villain-1', 'amount' => 7]],
        ], 3);
        $provider = new ProtoHttpProvider(['url' => 'https://proto.example']);
        $events = $provider->gameEvents($game, true)['events'];
        $types = array_column($events, 'eventType');
        $stage = array_search('stageStarted', $types, true);
        self::assertSame('blindPosted', $events[$stage + 1]['eventType']);
        self::assertSame('STRADDLE', $events[$stage + 1]['blindType']);
        self::assertSame(200, $events[$stage + 1]['amount']);
        $wins = array_values(array_filter($events,
            static fn (array $event): bool => $event['eventType'] === 'playerWon'));
        self::assertSame([
            ['eventType' => 'playerWon', 'name' => 'hero-1', 'amount' => 325],
            ['eventType' => 'playerWon', 'name' => 'villain-1', 'amount' => 7],
        ], $wins);
    }

    private function game(string $uuid = '12345678-90ab-4cde-8f01-23456789abcd', int $userId = 1): GameVo
    {
        return new GameVo(
            $userId,
            $uuid,
            NetworkEnum::WE,
            'table-1#1',
            100,
            50,
            0,
            [
                ['uid' => 'hero-1', 'seat' => 1, 'stack' => 1000, 'hero' => true],
                ['uid' => 'villain-1', 'seat' => 2, 'stack' => 1000, 'hero' => false],
            ],
            1,
            1,
        );
    }

    /**
     * @param  array{contents: string}  $request
     * @return array<string, mixed>
     */
    private function body(array $request): array
    {
        return json_decode($request['contents'], true, 512, JSON_THROW_ON_ERROR);
    }
}
