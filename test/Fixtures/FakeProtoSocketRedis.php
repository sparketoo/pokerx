<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Redis\Redis;
use LogicException;

final class FakeProtoSocketRedis extends Redis
{
    /** @var array<string, string> */
    public array $values = [];

    public function __construct() {}

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'get') {
            return $this->values[$arguments[0]] ?? false;
        }
        if ($name === 'set') {
            [$key, $value, $options] = $arguments;
            if (in_array('NX', $options, true) && isset($this->values[$key])) {
                return false;
            }
            $this->values[$key] = $value;

            return true;
        }
        if ($name === 'eval') {
            [$script, $args] = $arguments;
            if (($this->values[$args[0]] ?? null) !== $args[1]) {
                return 0;
            }
            if (str_contains($script, "redis.call('del'")) {
                unset($this->values[$args[0]]);
            }
            if (str_contains($script, "redis.call('setex'")) {
                $this->values[$args[2]] = $args[4];
            }

            return 1;
        }
        if ($name === 'del') {
            unset($this->values[$arguments[0]]);

            return 1;
        }

        throw new LogicException('Unexpected Redis command: '.$name);
    }
}
