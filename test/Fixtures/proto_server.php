<?php

use Swoole\Timer;
use Swoole\WebSocket\Server;

// Independent real WebSocket fixture, deliberately using the third-party wire format.
$server = new Server('127.0.0.1', (int) (getenv('FIXTURE_PORT') ?: 18082));
$server->set(['worker_num' => 1, 'enable_coroutine' => false, 'hook_flags' => 0, 'log_file' => sys_get_temp_dir().'/pokerx-proto-fixture.log']);
$games = [];
$authenticated = [];
$server->on('message', function ($server, $frame) use (&$games, &$authenticated) {
    $m = json_decode($frame->data, true);
    $fd = $frame->fd;
    if (! isset($authenticated[$fd])) {
        $ok = ($m['token'] ?? null) === 'mock-token';
        if ($ok) {
            $authenticated[$fd] = true;
        }$server->push($fd, json_encode(['result' => $ok, 'sessionId' => 'fixture-'.$fd]));

        return;
    }
    if (($m['structType'] ?? null) === 'gameEvents') {
        $previous = $games[$fd]['events'] ?? null;
        $events = $m['events'] ?? null;
        if (! is_array($events) || ($previous !== null && (count($events) !== count($previous) + 1 ||
            array_slice($events, 0, -1) !== $previous))) {
            $server->push($fd, json_encode(['error' => 'events must advance by one ordered event', 'gameId' => $m['game']['gameId'] ?? null]));

            return;
        }
        $games[$fd] = $m;

        return;
    }
    if (($m['structType'] ?? null) === 'getAnswer') {
        $mode = getenv('FIXTURE_MODE') ?: 'success';
        $game = $games[$fd] ?? null;
        if ($mode === 'timeout') {
            return;
        }
        if ($mode === 'disconnect') {
            $server->disconnect($fd);

            return;
        }
        if ($mode === 'malformed') {
            $server->push($fd, 'not json');

            return;
        }
        if ($mode === 'reject' || ! $game) {
            $server->push($fd, json_encode(['error' => 'fixture rejection', 'gameId' => $m['gameId']]));

            return;
        }
        $hero = null;
        foreach ($game['events'] as $e) {
            if ($e['eventType'] === 'handDealt') {
                $hero = $e['name'];
            }
        }
        $response = ['structType' => 'playerAction', 'gameId' => $m['gameId'], 'name' => $hero, 'action' => 'check', 'amount' => 0];
        if ($mode === 'wrong_game') {
            $response['gameId'] = 'another-game';
        }
        $server->push($fd, json_encode($response));
        // Duplicate and late reply must never complete the next request.
        if ($mode === 'duplicate') {
            Timer::after(100, function () use ($server, $fd, $response) {
                if ($server->isEstablished($fd)) {
                    $server->push($fd, json_encode($response));
                }
            });
        }
    }
});
$server->on('close', function ($s, $fd) use (&$games, &$authenticated) {
    unset($games[$fd],$authenticated[$fd]);
});
$server->start();
