<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Redis\Redis;
use LogicException;

final class FakeProtoSocketRedis extends Redis
{
    private const string PREFIX = 'pokerx:';

    /** @var array<string, string> */
    public array $values = [];

    public function __construct() {}

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'get') {
            return $this->values[self::PREFIX.$arguments[0]] ?? false;
        }
        if ($name === 'set') {
            [$key, $value, $options] = $arguments;
            $key = self::PREFIX.$key;
            if (in_array('NX', $options, true) && isset($this->values[$key])) {
                return false;
            }
            $this->values[$key] = $value;

            return true;
        }
        if ($name === 'eval') {
            [$script, $args, $numKeys] = $arguments;
            for ($index = 0; $index < $numKeys; $index++) {
                $args[$index] = self::PREFIX.$args[$index];
            }
            if (($this->values[$args[0]] ?? null) !== $args[$numKeys]) {
                return 0;
            }
            if (str_contains($script, "redis.call('del'")) {
                unset($this->values[$args[0]]);
            }
            if (str_contains($script, "redis.call('setex'")) {
                $sessionKey = str_contains($script, 'KEYS[2]') ? $args[1] : $args[2];
                $this->values[$sessionKey] = $args[array_key_last($args)];
            }

            return 1;
        }
        if ($name === 'del') {
            unset($this->values[self::PREFIX.$arguments[0]]);

            return 1;
        }

        throw new LogicException('Unexpected Redis command: '.$name);
    }
}
