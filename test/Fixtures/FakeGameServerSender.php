<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\WebSocketServer\Sender;

final class FakeGameServerSender extends Sender
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /** @var list<int> */
    public array $disconnected = [];

    public function __construct() {}

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'push') {
            $this->messages[] = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);

            return true;
        }
        if ($name === 'disconnect') {
            $this->disconnected[] = $arguments[0];

            return true;
        }

        return false;
    }
}
