<?php

require __DIR__.'/bootstrap.php';

use App\Game\Providers\ProtoProvider;
use App\Game\Reducer;
use Hyperf\Stringable\Str;
use Swoole\Coroutine;
use Swoole\Timer;

use function Hyperf\Config\config;

$report = null;
\Hyperf\Coroutine\run(function () use (&$report) {
    $options = getopt('', ['fixture', 'expect::']);
    $config = config('poker.proto');
    if (isset($options['fixture'])) {
        $config = array_replace($config, ['url' => 'ws://127.0.0.1:'.(getenv('FIXTURE_PORT') ?: 18082), 'token' => 'mock-token', 'heartbeat' => .3, 'pong_timeout' => 1, 'timeout_margin' => -4.5]);
    }
    $packets = json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/GATEWAY-V1-EXAMPLES.json'), true)['packets'];
    $events = [];
    foreach ($packets as $key => $v) {
        if (str_starts_with($key, 'client_') && str_starts_with($v['type'] ?? '', 'game_')) {
            $events[] = $v;
        }
    }
    usort($events, fn ($a, $b) => $a['seq'] <=> $b['seq']);
    $uuid = (string) Str::uuid();
    $reducer = new Reducer;
    $c = $reducer->start(1, $uuid, $events[0]);
    $contexts = [];
    foreach (array_slice($events, 0, 8) as $e) {
        if ($e['seq'] > 1) {
            $e['payload']['hand_id'] = $uuid;
        }if ($e['type'] === 'game_request_action') {
            $e['payload']['delay'] = 5000;
        }$c = $reducer->apply($c, $e);
        $contexts[] = $c;
    }
    $report = null;

    $frames = [];
    $provider = new ProtoProvider($config, function ($direction, $type, $payload) use (&$frames, $config) {
        $frames[] = ['direction' => $direction, 'type' => $type] + (isset($payload['error']) ? ['reason' => str_replace($config['token'] ?? '', '[redacted]', (string) $payload['error'])] : []);
    });
    $finish = function ($result) use (&$report, &$frames, $options, $provider) {
        $expected = $options['expect'] ?? 'success';
        $ok = $expected === 'success' ? $result->success : (! $result->success && $result->error_code === $expected);
        if (isset($options['fixture']) && ! in_array('pong', array_column($frames, 'type'), true)) {
            $ok = false;
        }
        $report = ['result' => $ok ? 'passed' : 'failed', 'provider' => isset($options['fixture']) ? 'local_proto_fixture' : 'live_proto', 'solve' => $result->jsonSerialize(), 'frames' => $frames];
        $provider->close();
    };
    try {
        $provider->start($contexts[0]);
        $provider->stageStarted($contexts[1]);
        $provider->playerActed($contexts[2]);
        $provider->playerActed($contexts[3]);
        $provider->playerActed($contexts[4]);
        $provider->stageStarted($contexts[5]);
        $provider->playerActed($contexts[6]);
        Timer::after(isset($options['fixture']) ? 1200 : 1, fn () => $provider->requestAction($contexts[7], $finish));
        while ($report === null) {
            Coroutine::sleep(0.01);
        }
    } catch (Throwable $error) {
        $report = ['result' => 'failed', 'error' => get_class($error), 'reason' => $error->getMessage()];
    } finally {
        $provider->close();
    }
});
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit(($report['result'] ?? null) === 'passed' ? 0 : 1);
