<?php

declare(strict_types=1);
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

// A deterministic, local Proto peer for the GameServer end-to-end replay.
$port = (int) (getenv('FLOW_PROTO_PORT') ?: 18084);
$capture = getenv('FLOW_PROTO_CAPTURE') ?: sys_get_temp_dir().'/pokerx-proto-flow.jsonl';
$server = new Server('127.0.0.1', $port);
$server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING]);
$authenticated = [];
$requests = [];
$heroName = getenv('FLOW_HERO_NAME') ?: 'Arven';
$firstAction = getenv('FLOW_FIRST_ACTION') ?: 'call';
$firstAmount = (int) (getenv('FLOW_FIRST_AMOUNT') ?: 2);
$secondAction = getenv('FLOW_SECOND_ACTION') ?: 'fold';

$server->on('message', function (Server $server, Frame $frame) use (
    $capture,
    &$authenticated,
    &$requests,
    $heroName,
    $firstAction,
    $firstAmount,
    $secondAction,
): void {
    $message = json_decode($frame->data, true);
    if (! is_array($message)) {
        return;
    }
    if (! isset($authenticated[$frame->fd])) {
        $authenticated[$frame->fd] = true;
        $server->push($frame->fd, json_encode(['result' => true, 'sessionId' => 'local-flow'], JSON_THROW_ON_ERROR));

        return;
    }

    file_put_contents($capture, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    if (($message['structType'] ?? null) === 'getAnswer') {
        $gameId = (string) ($message['gameId'] ?? '');
        $requests[$gameId] = ($requests[$gameId] ?? 0) + 1;
        $first = $requests[$gameId] === 1;
        $server->push($frame->fd, json_encode([
            'structType' => 'playerAction',
            'gameId' => $gameId,
            'name' => $heroName,
            'action' => $first ? $firstAction : $secondAction,
            'amount' => $first ? $firstAmount : 0,
        ], JSON_THROW_ON_ERROR));
    }
});
$server->on('close', static function (Server $server, int $fd) use (&$authenticated): void {
    unset($authenticated[$fd]);
});
$server->start();
