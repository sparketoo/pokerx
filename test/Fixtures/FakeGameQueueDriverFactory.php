<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use InvalidArgumentException;

final class FakeGameQueueDriverFactory extends DriverFactory
{
    public function __construct(private readonly FakeGameQueueDriver $driver) {}

    public function get(string $name): DriverInterface
    {
        if ($name !== 'default') {
            throw new InvalidArgumentException('Unexpected queue: '.$name);
        }

        return $this->driver;
    }
}
