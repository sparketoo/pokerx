<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Socket;

use App\Game\Socket\WebSocketClient;
use Closure;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Tests\Fixtures\FakeWebSocketTransport;
use Tests\TestCase;

final class WebSocketClientTest extends TestCase
{
    public function test_closing_during_authentication_never_keeps_the_socket_alive(): void
    {
        Coroutine\run(function (): void {
            $socket = new FakeWebSocketTransport;
            $client = new class(static fn (): Client => $socket) extends WebSocketClient
            {
                public function __construct(Closure $factory)
                {
                    parent::__construct('ws://proto.test/', 1.0, 1.0, 3.0, $factory);
                }

                protected function authenticate(Client $socket): void
                {
                    $this->close();
                }

                protected function handleText(string $text): void {}
            };

            try {
                $client->connect();
                self::fail('A closed client must not finish connecting');
            } catch (\RuntimeException) {
                self::assertTrue($socket->closed);
            }
        });
    }

    public function test_it_dispatches_messages_and_reconnects_after_transport_closes(): void
    {
        Coroutine\run(function (): void {
            $first = new FakeWebSocketTransport;
            $second = new FakeWebSocketTransport;
            $transports = [$first, $second];
            $client = new class(static function () use (&$transports): Client {
                return array_shift($transports) ?? throw new \RuntimeException('No transport available');
            }) extends WebSocketClient
            {

                /** @var list<string> */
                public array $messages = [];

                public function __construct(Closure $factory)
                {
                    parent::__construct('ws://proto.test/', 1.0, 0.05, 0.2, $factory);
                }

                protected function authenticate(Client $socket): void {}

                protected function handleText(string $text): void
                {
                    $this->messages[] = $text;
                }
            };

            $client->connect();
            $first->receive('first');
            Coroutine::sleep(0.02);
            $first->close();
            Coroutine::sleep(1.1);
            $second->receive('second');
            Coroutine::sleep(0.02);

            self::assertSame(['first', 'second'], $client->messages);
            self::assertContains(SWOOLE_WEBSOCKET_OPCODE_PING, array_column($second->sent, 'opcode'));
            $client->close();
        });
    }

    public function test_heartbeat_continues_while_upstream_is_reconnecting(): void
    {
        Coroutine\run(function (): void {
            $socket = new FakeWebSocketTransport;
            $client = new class(static function () use ($socket): Client {
                static $attempt = 0;

                return ++$attempt === 1 ? $socket : throw new \RuntimeException('Upstream unavailable');
            }) extends WebSocketClient
            {

                public int $heartbeats = 0;

                public function __construct(Closure $factory)
                {
                    parent::__construct('ws://proto.test/', 0.1, 0.02, 0.1, $factory);
                }

                protected function authenticate(Client $socket): void {}

                protected function handleText(string $text): void {}

                protected function onHeartbeat(): void
                {
                    $this->heartbeats++;
                }
            };

            $client->connect();
            $socket->close();
            Coroutine::sleep(0.1);

            self::assertGreaterThanOrEqual(2, $client->heartbeats);
            $client->close();
        });
    }
}
