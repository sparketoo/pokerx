<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Exception\AppException;
use App\Game\Providers\ProtoProvider;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Contract\Http\RawResponseInterface;
use Hyperf\Engine\Http\RawResponse;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\TestCase;

final class ProtoProviderTest extends TestCase
{
    public function test_authentication_action_and_full_game_log_use_one_session(): void
    {
        $replies = [
            ['result' => true, 'sessionId' => 'session-123'],
            ['result' => true],
            ['structType' => 'playerAction', 'gameId' => '12345678-90ab-4cde-8f01-23456789abcd', 'action' => 'all-In', 'amount' => 100],
            ['result' => true],
        ];
        $client = new class($replies) implements ClientInterface
        {
            /** @var list<array{method: string, path: string, headers: array<string, list<string>>, contents: string}> */
            public array $requests = [];

            /** @var list<array<string, mixed>> */
            public array $settings = [];

            /** @param list<array<string, mixed>> $replies */
            public function __construct(private array $replies) {}

            /** @param array<string, mixed> $settings */
            public function set(array $settings): bool
            {
                $this->settings[] = $settings;

                return true;
            }

            /** @param array<string, list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
                $this->requests[] = compact('method', 'path', 'headers', 'contents');

                return new RawResponse(200, [], json_encode(array_shift($this->replies), JSON_THROW_ON_ERROR), $version);
            }
        };
        $endpoints = [];
        $provider = new ProtoProvider([
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
        self::assertSame('gameOver', $this->body($client->requests[3])['events'][count($this->body($client->requests[3])['events']) - 1]['eventType']);
    }

    public function test_http_error_is_returned_through_callback(): void
    {
        $client = new class implements ClientInterface
        {
            /** @var list<array{method: string, path: string, headers: array<string, list<string>>, contents: string}> */
            public array $requests = [];

            /** @param array<string, mixed> $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param array<string, list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
                $this->requests[] = compact('method', 'path', 'headers', 'contents');
                $body = json_decode($contents, true);

                return ($body['token'] ?? null) === 'test-token'
                    ? new RawResponse(200, [], '{"result":true,"sessionId":"session-123"}', $version)
                    : new RawResponse(400, [], '{"error":"invalid sessionId"}', $version);
            }
        };
        $provider = new ProtoProvider(
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
        self::assertSame('provider_failed', $result->exception->getErrorCode());
        self::assertCount(2, $client->requests);
    }

    public function test_session_is_shared_across_provider_instances_and_renewed_for_280_seconds(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            /** @var list<array{body: array<string, mixed>, headers: array<string, list<string>>}> */
            public array $requests = [];

            public int $authentications = 0;

            /** @param array<string, mixed> $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param array<string, list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
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
        $first = new ProtoProvider($options, $factory, $redis);
        $second = new ProtoProvider($options, $factory, $redis);

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

    public function test_invalid_remote_session_is_recreated_on_next_use(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $authentications = 0;

            public int $requests = 0;

            /** @param array<string, mixed> $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param array<string, list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
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
        $provider = new ProtoProvider(
            ['url' => 'https://proto.example', 'token' => 'test-token', 'player_id' => 'fixed-pid'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client,
            $redis,
        );
        $firstResult = null;
        $provider->requestAction($this->game(), static function (RequestActionResultVo $result) use (&$firstResult): void {
            $firstResult = $result;
        });
        self::assertInstanceOf(RequestActionResultVo::class, $firstResult);
        self::assertFalse($firstResult->success);
        self::assertSame(1, $client->authentications);
        self::assertCount(0, $redis->values);

        $provider->requestAction($this->game(), static function (RequestActionResultVo $result): void {
            self::assertTrue($result->success);
        });

        self::assertSame(2, $client->authentications);
        self::assertSame(5, $client->requests);
        self::assertSame(['session-2'], array_values($redis->values));
    }

    public function test_different_users_have_separate_sessions(): void
    {
        $redis = new FakeProtoHttpRedis;
        $client = new class implements ClientInterface
        {
            public int $authentications = 0;

            /** @param array<string, mixed> $settings */
            public function set(array $settings): bool
            {
                return true;
            }

            /** @param array<string, list<string>> $headers */
            public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface
            {
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
        $provider = new ProtoProvider(
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
