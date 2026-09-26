<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;

final class FakeWebSocketTransport extends Client
{
    /** @var list<array{data: mixed, opcode: int}> */
    public array $sent = [];

    /** @var array<string, mixed> */
    public array $settings = [];

    public bool $closed = false;

    public string $path = '';

    /** @var null|Closure(mixed, int): void */
    public ?Closure $onPush = null;

    private Channel $incoming;

    public function __construct()
    {
        $this->incoming = new Channel(16);
    }

    /** @param array<string, mixed> $settings */
    public function set(array $settings): bool
    {
        $this->settings = $settings;

        return true;
    }

    public function upgrade(mixed $path): bool
    {
        if (! is_string($path)) {
            return false;
        }
        $this->path = $path;

        return true;
    }

    public function recv(mixed $timeout = 0): Frame|string|bool
    {
        return $this->incoming->pop((float) $timeout);
    }

    public function push(mixed $data, mixed $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT, mixed $flags = SWOOLE_WEBSOCKET_FLAG_FIN): bool
    {
        if (! is_int($opcode)) {
            return false;
        }
        $this->sent[] = ['data' => $data, 'opcode' => $opcode];

        if (! $this->closed && $this->onPush !== null) {
            ($this->onPush)($data, $opcode);
        }

        return ! $this->closed;
    }

    public function close(): bool
    {
        $this->closed = true;
        $this->incoming->push(false);

        return true;
    }

    public function receive(string $text, int $opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT): void
    {
        $frame = new Frame;
        $frame->data = $text;
        $frame->opcode = $opcode;
        $this->incoming->push($frame);
    }
}
