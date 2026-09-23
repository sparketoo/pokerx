<?php

use function Hyperf\Support\env;

return [
    'default' => env('POKER_PROVIDER', 'proto'),
    'proto' => [
        'url' => env('PROTO_URL'),
        'player_id' => env('PROTO_PLAYER_ID', 'pokerx'),
        'token' => env('PROTO_TOKEN'),
        'network' => env('PROTO_NETWORK', 'WE'),
        'currency' => env('PROTO_CURRENCY', 'USDT'),
        'connect_timeout' => 5,
        'request_timeout' => (float) env('PROTO_HTTP_REQUEST_TIMEOUT', 10),
    ],
    'mock' => [
        'delay_ms' => (int) env('MOCK_DELAY_MS', 50),
        'failure' => (bool) env('MOCK_FAILURE', false),
    ],
    'ws_host' => env('POKER_WS_HOST', '127.0.0.1'),
    'ws_port' => (int) env('POKER_WS_PORT', 18081),
    'token_days' => 30,
    'incomplete_after' => (int) env('POKER_INCOMPLETE_AFTER', 1800),
];
