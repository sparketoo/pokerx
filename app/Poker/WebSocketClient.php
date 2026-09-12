<?php

declare(strict_types=1);

namespace App\Poker;

use Closure;
use Hyperf\Coroutine\Coroutine;
use Hyperf\WebSocketClient\Client;
use Hyperf\WebSocketClient\ClientFactory;
use Hyperf\WebSocketClient\CloseFrame;
use Hyperf\WebSocketClient\Frame;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Throwable;

use function App\Support\di;
use function Hyperf\Config\config;

/** Owns a receive coroutine and a bounded, single-writer channel per connection. */
final class WebSocketClient
{
    private ?Client $client = null;

    private ?Channel $outbox = null;

    public private(set) bool $closed = false;

    private bool $closing = false;

    public function __construct(
        private readonly string $url,
        private readonly Closure $onOpen,
        private readonly Closure $onMessage,
        private readonly Closure $onPong,
        private readonly Closure $onClose
    ) {}

    public function connect(): void
    {
        $this->outbox = new Channel(64);
        Coroutine::create(function (): void {
            try {
                $client = di(ClientFactory::class)->create($this->url, false);
                if ($this->closed) {
                    $client->close();

                    return;
                }
                $this->client = $client;
                Coroutine::create(function () use ($client): void {
                    try {
                        while (! $this->closed && $this->outbox !== null) {
                            $item = $this->outbox->pop();
                            if (! is_array($item)) {
                                break;
                            }
                            if (! $client->push($item[0], $item[1])) {
                                break;
                            }
                            if ($item[1] === 8) {
                                break;
                            }
                        }
                    } catch (Throwable) {
                        // A writer failure closes this provider connection only.
                    } finally {
                        $this->abort();
                    }
                });
                ($this->onOpen)();
                while (! $this->closed) {
                    $frame = $client->recv((float) config('poker.proto.heartbeat',
                        20) + (float) config('poker.proto.pong_timeout', 10));
                    if ($frame === false) {
                        throw new RuntimeException('Provider receive failed');
                    }
                    if (! $frame instanceof Frame || $frame instanceof CloseFrame || $frame->opcode === 8) {
                        break;
                    }
                    if ($frame->opcode === 10) {
                        ($this->onPong)();
                    } elseif ($frame->opcode === 9) {
                        $this->send($frame->data, 10);
                    } elseif ($frame->opcode === 1 && strlen($frame->data) <= 1048576) {
                        ($this->onMessage)($frame->data);
                    } else {
                        throw new RuntimeException('Invalid provider frame');
                    }
                }
            } catch (Throwable) {
                // Provider maps connection errors to its scoped exception and resolves at most once.
            } finally {
                $this->abort();
            }
        });
    }

    public function send(string $data, int $opcode = 1): bool
    {
        return ! $this->closed && ! $this->closing && strlen($data) <= 1048576 && $this->outbox !== null && $this->outbox->push([
            $data, $opcode,
        ], 0.001);
    }

    public function close(): void
    {
        if ($this->closed || $this->closing) {
            return;
        }
        $sent = $this->send('', 8);
        $this->closing = true;
        if (! $sent) {
            $this->abort();
        }
    }

    public function abort(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->outbox?->close();
        $this->client?->close();
        $this->client = null;
        ($this->onClose)();
    }
}
