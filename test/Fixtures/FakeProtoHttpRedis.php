<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Redis\Redis;
use LogicException;

final class FakeProtoHttpRedis extends Redis
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, float> */
    private array $expires = [];

    private float $now = 0;

    public function __construct() {}

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function ttl(string $key): int
    {
        return (int) (($this->expires[$key] ?? 0) - $this->now);
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'get') {
            return $this->value($arguments[0]) ?? false;
        }
        if ($name === 'setex') {
            [$key, $ttl, $value] = $arguments;
            $this->values[$key] = $value;
            $this->expires[$key] = $this->now + $ttl;

            return true;
        }
        if ($name === 'del') {
            $this->remove($arguments[0]);

            return 1;
        }

        throw new LogicException('Unexpected Redis command: '.$name);
    }

    private function value(string $key): ?string
    {
        if (($this->expires[$key] ?? 0) <= $this->now) {
            $this->remove($key);
        }

        return $this->values[$key] ?? null;
    }

    private function remove(string $key): void
    {
        unset($this->values[$key], $this->expires[$key]);
    }
}
