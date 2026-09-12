<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Vo\Game\RequestActionResultVo;
use Hyperf\Coroutine\Coroutine;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\WebSocketClient\Client;
use Hyperf\WebSocketClient\Frame;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Throwable;

abstract class SocketProvider extends BaseProvider
{
    private ?Client $socket = null;

    /** Identifies one connect/close lifecycle, including attempts still in the handshake. */
    private ?object $session = null;

    private ?Channel $writer = null;

    private ?int $pingTimer = null;

    /**
     * @var array<string, \Closure(RequestActionResultVo): void>
     */
    protected array $requestActionCallbacks = [];

    /** @param  array<string, mixed>  $options */
    public function __construct(protected readonly array $options = []) {}

    protected function getUrl(): string
    {
        return $this->options['url'] ?? '';
    }

    /** @return array<string, string> */
    protected function getHeaders(): array
    {
        return $this->options['headers'] ?? [];
    }

    protected function onOpen(): void
    {
        //
    }

    abstract protected function onData(string $data, int $opcode): void;

    protected function onClose(): void
    {
        //
    }

    protected function onPing(): void {}

    protected function onError(Throwable $error): void {}

    final public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    final public function connect(): void
    {
        if ($this->session !== null) {
            return;
        }
        $session = $this->session = new \stdClass;
        Coroutine::create(function () use ($session): void {
            while ($this->session === $session) {
                $socket = null;
                try {
                    $socket = new Client(new Uri($this->getUrl()), $this->getHeaders());
                    if ($this->session !== $session) {
                        $socket->close();

                        return;
                    }
                    $this->socket = $socket;
                    $this->writer = new Channel(1);
                    $this->writer->push(true);
                    $this->onOpen();
                    $interval = (float) ($this->options['ping_interval'] ?? 20);
                    if ($this->socket === $socket && $interval > 0) {
                        $this->pingTimer = Timer::tick(max(1, (int) ($interval * 1000)),
                            function () use ($socket): void {
                                if ($this->socket === $socket) {
                                    $this->ping();
                                }
                            });
                    }
                    while ($this->socket === $socket) {
                        $frame = $socket->recv();
                        if ($this->socket !== $socket || ! $frame instanceof Frame || $frame->opcode === 8) {
                            break;
                        }
                        $this->onData($frame->data, $frame->opcode);
                    }
                } catch (Throwable $error) {
                    if ($this->session === $session) {
                        $this->report($error);
                    }
                } finally {
                    if ($socket !== null) {
                        $this->disconnect($socket);
                    }
                }
                if ($this->session === $session) {
                    Coroutine::sleep(max(0.1, (float) ($this->options['reconnect_interval'] ?? 1)));
                }
            }
        });
    }

    /** Disconnected messages are never queued or replayed. */
    final public function write(string $data, int $opcode = 1): bool
    {
        $socket = $this->socket;
        $writer = $this->writer;
        if ($socket === null || $writer === null || ! $writer->pop(max(0.001,
            (float) ($this->options['send_timeout'] ?? 10)))) {
            return false;
        }
        try {
            if ($this->socket !== $socket) {
                return false;
            }
            if (! $socket->push($data, $opcode)) {
                throw new RuntimeException('WebSocket send failed');
            }

            return true;
        } catch (Throwable $error) {
            $this->disconnect($socket);
            $this->report($error);

            return false;
        } finally {
            if ($this->writer === $writer) {
                $writer->push(true);
            }
        }
    }

    /** The subclass decides whether and how to send an application heartbeat. */
    final public function ping(): void
    {
        $socket = $this->socket;
        if (! $this->isConnected() || $socket === null) {
            return;
        }
        try {
            $this->onPing();
        } catch (Throwable $error) {
            $this->disconnect($socket);
            $this->report($error);
        }
    }

    final public function close(): void
    {
        $this->session = null;
        if ($this->socket !== null) {
            $this->disconnect($this->socket);
        }
    }

    final public function reconnect(): void
    {
        if ($this->session === null) {
            $this->connect();
        } elseif ($this->socket !== null) {
            $this->disconnect($this->socket);
        }
    }

    private function disconnect(Client $socket): void
    {
        if ($this->socket !== $socket) {
            return;
        }
        $this->socket = null;
        if ($this->pingTimer !== null) {
            Timer::clear($this->pingTimer);
            $this->pingTimer = null;
        }
        $writer = $this->writer;
        $this->writer = null;
        $writer?->close();
        $socket->close();
        try {
            $this->onClose();
        } catch (Throwable $error) {
            $this->report($error);
        }
    }

    private function report(Throwable $error): void
    {
        try {
            $this->onError($error);
        } catch (Throwable) {
            // An error observer must not terminate the reconnect loop.
        }
    }

    protected function hasRequestActionCallback(string $gameId): bool
    {
        return isset($this->requestActionCallbacks[$gameId]);
    }

    protected function setRequestActionCallback(string $gameId, \Closure $callback): void
    {
        $this->requestActionCallbacks[$gameId] = $callback;
    }

    protected function callRequestActionCallback(string $gameId, RequestActionResultVo $result): void
    {
        $callback = $this->requestActionCallbacks[$gameId] ?? null;
        if ($callback) {
            $callback($result);
        }

    }
}
