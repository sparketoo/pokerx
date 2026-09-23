<?php

declare(strict_types=1);
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

// Deterministic Proto HTTP peer for the complete GameServer replay.
$port = (int) (getenv('FLOW_PROTO_PORT') ?: 18284);
$capture = getenv('FLOW_PROTO_CAPTURE') ?: sys_get_temp_dir().'/pokerx-proto-http-flow.jsonl';
$server = new Server('127.0.0.1', $port);
$server->set(['worker_num' => 1, 'log_level' => SWOOLE_LOG_WARNING]);
$requests = [];
$server->on('request', static function (Request $request, Response $response) use (
    $capture,
    &$requests,
): void {
    $payload = json_decode($request->rawContent() ?: '', true);
    $respond = static function (int $code, array $body) use ($response): void {
        $response->status($code);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($body, JSON_THROW_ON_ERROR));
    };
    if ($request->server['request_method'] !== 'POST'
        || $request->server['request_uri'] !== '/api/command' || ! is_array($payload)) {
        $respond(400, ['error' => 'invalid request']);

        return;
    }
    if (isset($payload['token'])) {
        file_put_contents($capture, json_encode(['kind' => 'auth', 'pid' => $request->get['pid'] ?? null,
            'player_id' => $request->header['x-player-id'] ?? null], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND | LOCK_EX);
        $respond(200, ['result' => true, 'sessionId' => 'local-http-flow']);

        return;
    }
    if (($request->header['x-session-id'] ?? null) !== 'local-http-flow') {
        $respond(400, ['error' => 'invalid sessionId']);

        return;
    }
    file_put_contents($capture, json_encode([
        'payload' => $payload,
        'pid' => $request->get['pid'] ?? null,
        'player_id' => $request->header['x-player-id'] ?? null,
        'session_id_valid' => true,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    $type = $payload['structType'] ?? null;
    if ($type === 'gameEvents' || $type === 'fullGameLog') {
        $respond(200, ['result' => true]);

        return;
    }
    if ($type === 'getAnswer') {
        $gameId = (string) ($payload['gameId'] ?? '');
        $requests[$gameId] = ($requests[$gameId] ?? 0) + 1;
        $first = $requests[$gameId] === 1;
        $respond(200, [
            'structType' => 'playerAction',
            'gameId' => $gameId,
            'action' => $first ? 'call' : 'check',
            'amount' => $first ? 50 : 0,
        ]);

        return;
    }
    $respond(400, ['error' => 'unsupported command']);
});
$server->start();
