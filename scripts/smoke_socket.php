<?php

require __DIR__.'/bootstrap.php';

use App\Poker\WebSocketClient;
use Hyperf\Contract\ConfigInterface;
use Swoole\Coroutine;

\Hyperf\Coroutine\run(function () {
    \App\Support\di(ConfigInterface::class)->set('poker.proto.connect_timeout', 2);
    \App\Support\di(ConfigInterface::class)->set('poker.proto.heartbeat', 1);
    \App\Support\di(ConfigInterface::class)->set('poker.proto.pong_timeout', 1);
    $received = [];
    $opened = false;
    $closed = 0;
    $client = null;
    $client = new WebSocketClient(getenv('SOCKET_TEST_URL'), function () use (&$opened, &$client) {
        $opened = true;
        $client->send('hello');
    }, function (string $message) use (&$received, &$client) {
        $received[] = $message;
        $client->send(getenv('SOCKET_LARGE_WRITE') ? str_repeat('x', 1048576) : 'final');
        $client->close();
    }, function () {}, function () use (&$closed) {
        $closed++;
    });
    $client->connect();
    while ($closed === 0) {
        Coroutine::sleep(.01);
    }
    echo json_encode(['opened' => $opened, 'messages' => $received, 'closed' => $closed], JSON_THROW_ON_ERROR).PHP_EOL;

});
