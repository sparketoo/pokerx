<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Redis\Redis;
use LogicException;

final class FakeProtoHttpRedis extends Redis
{
    /** @var array<string, string> */
    public array $values = [];

    public float $gameWriteDelay = 0;

    public bool $failNextExpire = false;

    /** @var array<string, float> */
    private array $expires = [];

    private float $now = 0;

    public function __construct() {}

    public function advance(float $seconds): void
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
            if (str_starts_with($key, 'game:')) {
                $this->now += $this->gameWriteDelay;
            }
            $this->values[$key] = $value;
            $this->expires[$key] = $this->now + $ttl;

            return true;
        }
        if ($name === 'set') {
            [$key, $value, $options] = $arguments;
            if (in_array('NX', $options, true) && $this->value($key) !== null) {
                return false;
            }
            $this->values[$key] = $value;
            $this->expires[$key] = $this->now + $options['EX'];

            return true;
        }
        if ($name === 'eval') {
            [$script, $args, $keyCount] = $arguments;
            [$key, $owner] = $keyCount === 2
                ? [$args[0], $args[2]]
                : [$args[0], $args[1]];
            $current = $this->value($key);
            if (str_contains($script, "redis.call('set'")) {
                if ($current !== null && $current !== $owner) {
                    return 0;
                }
                $this->values[$key] = $owner;
                $this->expires[$key] = $this->now + $args[3] + 1;
                $this->values[$args[1]] = $args[4];
                $this->expires[$args[1]] = $this->now + $args[3];

                return 1;
            }
            if (str_contains($script, "redis.call('expire'")) {
                if ($this->failNextExpire) {
                    $this->failNextExpire = false;

                    throw new LogicException('Redis expire failed');
                }
                if ($current !== $owner) {
                    return 0;
                }
                $this->expires[$key] = $this->now + (int) $args[2];

                return 1;
            }
            if ($current === $owner) {
                $this->remove($key);

                return 1;
            }

            return 0;
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
