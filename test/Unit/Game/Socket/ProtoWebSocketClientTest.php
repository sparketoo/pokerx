<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Socket;

use App\Exception\ProviderException;
use App\Game\Socket\ProtoWebSocketClient;
use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Tests\Fixtures\FakeProtoSocketRedis;
use Tests\Fixtures\FakeWebSocketTransport;
use Tests\TestCase;

final class ProtoWebSocketClientTest extends TestCase
{
    public function test_new_fd_reuses_session_id_from_previous_connection(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $first = new FakeWebSocketTransport;
            $first->receive('{"result":true,"sessionId":"session-1"}');
            $client = $this->client('42', $redis, static fn (): Client => $first);
            $client->connect();
            $client->close();

            $second = new FakeWebSocketTransport;
            $second->receive('{"result":true,"sessionId":"session-1"}');
            $replacement = $this->client('42', $redis, static fn (): Client => $second);
            $replacement->connect();

            self::assertSame('session-1', json_decode($second->sent[0]['data'], true)['sessionId']);
            self::assertSame('/?pid=pokerx-1-42', $second->path);
            $replacement->close();
        });
    }

    public function test_same_token_cannot_open_two_proto_sessions_at_once(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $first = new FakeWebSocketTransport;
            $first->receive('{"result":true,"sessionId":"session-1"}');
            $client = $this->client('42', $redis, static fn (): Client => $first);
            $client->connect();
            try {
                $duplicate = $this->client('42', $redis, static fn (): Client => new FakeWebSocketTransport);
                $duplicate->connect();
                self::fail('Duplicate token connection must be rejected');
            } catch (ProviderException $error) {
                self::assertSame(4000, $error->getCode());
            } finally {
                $client->close();
            }
        });
    }

    public function test_rejected_old_session_reauthenticates_without_it(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $initial = new FakeWebSocketTransport;
            $initial->receive('{"result":true,"sessionId":"old"}');
            $client = $this->client('42', $redis, static fn (): Client => $initial);
            $client->connect();
            $client->close();

            $rejected = new FakeWebSocketTransport;
            $rejected->receive('{"result":false,"info":"invalid sessionId"}');
            $fresh = new FakeWebSocketTransport;
            $fresh->receive('{"result":true,"sessionId":"new"}');
            $transports = [$rejected, $fresh];
            $replacement = $this->client('42', $redis, static function () use (&$transports): Client {
                return array_shift($transports) ?? throw new \RuntimeException('No transport available');
            });
            $replacement->connect();

            self::assertSame('old', json_decode($rejected->sent[0]['data'], true)['sessionId']);
            self::assertArrayNotHasKey('sessionId', json_decode($fresh->sent[0]['data'], true));
            $replacement->close();
        });
    }

    public function test_temporary_authentication_failure_keeps_session_for_later_reconnect(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $initial = new FakeWebSocketTransport;
            $initial->receive('{"result":true,"sessionId":"saved"}');
            $client = $this->client('42', $redis, static fn (): Client => $initial);
            $client->connect();
            $client->close();

            $limited = new FakeWebSocketTransport;
            $limited->receive('{"result":false,"info":"Connections limit reached"}');
            $attempt = $this->client('42', $redis, static fn (): Client => $limited);
            try {
                $attempt->connect();
                self::fail('Temporary authentication failure must be reported');
            } catch (ProviderException $error) {
                self::assertSame('Connections limit reached', $error->context()['remote_info']);
                $attempt->close();
            }

            $restored = new FakeWebSocketTransport;
            $restored->receive('{"result":true,"sessionId":"saved"}');
            $replacement = $this->client('42', $redis, static fn (): Client => $restored);
            $replacement->connect();

            self::assertSame('saved', json_decode($restored->sent[0]['data'], true)['sessionId']);
            $replacement->close();
        });
    }

    public function test_proto_error_for_game_fails_pending_request_without_waiting_for_timeout(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $socket = new FakeWebSocketTransport;
            $socket->receive('{"result":true,"sessionId":"session-1"}');
            $client = $this->client('42', $redis, static fn (): Client => $socket);
            $client->connect();
            $failure = null;
            Coroutine::create(static function () use ($client, &$failure): void {
                try {
                    $client->request('game-1', ['structType' => 'gameEvents'], ['structType' => 'getAnswer']);
                } catch (ProviderException $error) {
                    $failure = $error;
                }
            });
            Coroutine::sleep(0.02);
            $socket->receive('{"error":"Game not found","gameId":"game-1"}');
            Coroutine::sleep(0.02);

            self::assertSame(4001, $failure?->getCode());
            self::assertSame('Game not found', $failure->context()['remote_error']);
            $client->close();
        });
    }

    /** @param Closure(string, int, bool): Client $factory */
    private function client(string $clientId, FakeProtoSocketRedis $redis, Closure $factory): ProtoWebSocketClient
    {
        return new ProtoWebSocketClient(1, $clientId, [
            'url' => 'ws://proto.test/',
            'token' => 'proto-secret',
            'player_id' => 'pokerx',
        ], $redis, $factory);
    }
}
