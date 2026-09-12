<?php

use function Hyperf\Support\env;

return [
    'default' => env('POKER_PROVIDER', 'proto'),
    'proto' => [
        'url' => env('PROTO_URL'),
        'token' => env('PROTO_TOKEN'),
        'network' => env('PROTO_NETWORK', 'WE'),
        'connect_timeout' => 10,
        'heartbeat' => 20,
        'pong_timeout' => 10,
        'reconnect_max' => 30,
        'timeout_margin' => 5,
    ],
    'mock' => [
        'delay_ms' => (int) env('MOCK_DELAY_MS', 50),
        'failure' => (bool) env('MOCK_FAILURE', false),
    ],
    'ws_host' => env('POKER_WS_HOST', '127.0.0.1'),
    'ws_port' => (int) env('POKER_WS_PORT', 18081),
    'cost' => 100,
    'token_days' => 30,
    'incomplete_after' => (int) env('POKER_INCOMPLETE_AFTER', 1800),
];
