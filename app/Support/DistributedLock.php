<?php

declare(strict_types=1);

namespace App\Support;

use Hyperf\Redis\Redis;
use RuntimeException;
use Swoole\Coroutine;

/** Cross-worker lock: random ownership and compare-delete prevent releasing another owner's lock. */
final readonly class DistributedLock
{
    public function __construct(private string $key, private int $seconds = 30) {}

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function block(float $wait, callable $callback): mixed
    {
        $redis = di(Redis::class);
        $owner = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $wait;
        do {
            if ($redis->set($this->key, $owner, ['nx', 'ex' => $this->seconds])) {
                try {
                    return $callback();
                } finally {
                    $redis->eval('if redis.call("GET",KEYS[1]) == ARGV[1] then return redis.call("DEL",KEYS[1]) end return 0',
                        [$this->key, $owner], 1);
                }
            }
            Coroutine::sleep(0.01);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Lock wait timeout');
    }
}
