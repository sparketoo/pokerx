<?php

declare(strict_types=1);

namespace App\Game\Socket;

use Closure;
use Hyperf\Coroutine\Coroutine;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Throwable;

abstract class WebSocketClient
{
    private ?Client $socket = null;

    private bool $closed = false;

    private bool $connected = false;

    private float $lastReceivedAt = 0;

    private Channel $writeLock;

    private Channel $stopSignal;

    /** @param null|Closure(string, int, bool): Client $socketFactory */
    public function __construct(
        private readonly string $url,
        private readonly float $connectTimeout = 5.0,
        private readonly float $pingInterval = 20.0,
        private readonly float $idleTimeout = 55.0,
        private readonly ?Closure $socketFactory = null,
    ) {
        $this->writeLock = new Channel(1);
        $this->writeLock->push(true);
        $this->stopSignal = new Channel(1);
    }

    public function connect(): void
    {
        if ($this->closed) {
            throw new RuntimeException('WebSocket client is closed');
        }
        if ($this->connected) {
            return;
        }

        $this->openSocket();
        Coroutine::create(fn () => $this->receiveLoop());
        Coroutine::create(fn () => $this->heartbeatLoop());
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stopSignal->close();
        $this->connected = false;
        $socket = $this->socket;
        $this->socket = null;
        $socket?->close();
        $this->onDisconnected();
    }

    protected function sendText(string $text): void
    {
        $this->sendFrame($text, SWOOLE_WEBSOCKET_OPCODE_TEXT);
    }

    abstract protected function authenticate(Client $socket): void;

    abstract protected function handleText(string $text): void;

    protected function onDisconnected(): void {}

    protected function onHeartbeat(): void {}

    protected function onConnectionFailure(Throwable $error): void {}

    protected function retryAuthentication(Throwable $error): bool
    {
        return false;
    }

    private function openSocket(): void
    {
        $parts = parse_url($this->url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['ws', 'wss'], true)
            || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('Invalid WebSocket URL');
        }

        $ssl = $parts['scheme'] === 'wss';
        $host = $parts['host'];
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '') ?: '/';
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        foreach ([true, false] as $canRetry) {
            $factory = $this->socketFactory ?? static fn (string $host, int $port, bool $ssl): Client => new Client($host, $port, $ssl);
            $socket = $factory($host, $port, $ssl);
            try {
                if (! $socket->set([
                    'timeout' => $this->connectTimeout,
                    'connect_timeout' => $this->connectTimeout,
                    'open_websocket_pong_frame' => true,
                ]) || ! $socket->upgrade($path)) {
                    throw new RuntimeException(sprintf(
                        'WebSocket connection failed (status=%d, error=%d: %s)',
                        $socket->statusCode,
                        $socket->errCode,
                        $socket->errMsg,
                    ));
                }
                $this->authenticate($socket);
                if ($this->isClosed()) {
                    throw new RuntimeException('WebSocket client was closed during authentication');
                }
                $this->socket = $socket;
                $this->lastReceivedAt = microtime(true);
                $this->connected = true;

                return;
            } catch (Throwable $error) {
                $socket->close();
                if (! $canRetry || ! $this->retryAuthentication($error)) {
                    throw $error;
                }
            }
        }
    }

    private function receiveLoop(): void
    {
        $delay = 1.0;
        while (! $this->closed) {
            $socket = $this->socket;
            if ($socket === null) {
                $this->stopSignal->pop($delay);
                if ($this->isClosed()) {
                    return;
                }
                try {
                    $this->openSocket();
                    $delay = 1.0;
                } catch (Throwable $error) {
                    $this->onConnectionFailure($error);
                    $delay = min($delay * 2, 5.0);
                }

                continue;
            }

            try {
                $frame = $socket->recv($this->idleTimeout);
                if (! $frame instanceof Frame || $frame->opcode === SWOOLE_WEBSOCKET_OPCODE_CLOSE) {
                    throw new RuntimeException('WebSocket connection closed');
                }
                $this->lastReceivedAt = microtime(true);
                if ($frame->opcode === SWOOLE_WEBSOCKET_OPCODE_TEXT) {
                    $this->handleText((string) $frame->data);
                }
            } catch (Throwable $error) {
                $this->onConnectionFailure($error);
                if ($this->socket !== $socket) {
                    continue;
                }
                $this->socket = null;
                $this->connected = false;
                $socket->close();
                $this->onDisconnected();
            }
        }
    }

    private function heartbeatLoop(): void
    {
        while (! $this->closed) {
            $this->stopSignal->pop($this->pingInterval);
            if ($this->isClosed()) {
                continue;
            }
            try {
                $this->onHeartbeat();
                if (! $this->connected) {
                    continue;
                }
                if (microtime(true) - $this->lastReceivedAt >= $this->idleTimeout) {
                    $this->socket?->close();

                    continue;
                }
                $this->sendFrame('', SWOOLE_WEBSOCKET_OPCODE_PING);
            } catch (Throwable $error) {
                $this->onConnectionFailure($error);
                $this->socket?->close();
            }
        }
    }

    private function sendFrame(string $text, int $opcode): void
    {
        if ($this->closed || ! $this->connected || $this->socket === null) {
            throw new RuntimeException('WebSocket connection is unavailable');
        }
        if ($this->writeLock->pop($this->connectTimeout) !== true) {
            throw new RuntimeException('WebSocket write timed out');
        }
        try {
            $socket = $this->currentSocket();
            if ($socket === null || ! $socket->push($text, $opcode)) {
                $socket?->close();
                throw new RuntimeException('WebSocket write failed');
            }
        } finally {
            $this->writeLock->push(true);
        }
    }

    private function isClosed(): bool
    {
        return $this->closed;
    }

    private function currentSocket(): ?Client
    {
        return $this->socket;
    }
}
