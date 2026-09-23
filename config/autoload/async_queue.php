<?php

declare(strict_types=1);

use Hyperf\AsyncQueue\Driver\RedisDriver;

return [
    'default' => [
        'enable' => true,
        'driver' => RedisDriver::class,
        'redis' => ['pool' => 'default'],
        'channel' => '{queue}',
        'timeout' => 2,
        'retry_seconds' => 5,
        'handle_timeout' => 120,
        'processes' => 1,
        'concurrent' => ['limit' => 1],
        'max_messages' => 0,
    ],
];
