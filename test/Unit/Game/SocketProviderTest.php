<?php

declare(strict_types=1);

use App\Enum\ActionEnum;
use App\Game\Providers\SocketProvider;
use App\Model\Game;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Coroutine\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

use function Tests\run;

final class CallbackProvider extends SocketProvider
{
    public function requestAction(Game $game, Closure $callback): void
    {
        $this->setRequestActionCallback($game->uuid, $callback);
    }

    public function resolve(string $gameId, RequestActionResultVo $result): void
    {
        $this->callRequestActionCallback($gameId, $result);
    }

    /** @var list<array{string, int}> */
    public array $dataFrames = [];

    protected function onData(string $data, int $opcode): void
    {
        $this->dataFrames[] = [$data, $opcode];
    }
}

it('settles provider callbacks once and allows another request for the same hand', function (): void {
    run(function (): void {
        $provider = new CallbackProvider(['request_timeout' => 0.02]);
        $game = new Game(['uuid' => 'callback-hand']);
        $results = [];
        $callback = function (RequestActionResultVo $result) use (&$results): void {
            $results[] = $result;
        };
        $provider->requestAction($game, $callback);
        $provider->resolve($game->uuid, RequestActionResultVo::success(ActionEnum::CHECK, 0));
        $provider->resolve($game->uuid, RequestActionResultVo::success(ActionEnum::CHECK, 0));
        Coroutine::sleep(0.04);
        expect($results)->toHaveCount(1);
        $provider->requestAction($game, $callback);
        Coroutine::sleep(0.04);
        expect($results)->toHaveCount(2)->and($results[1]->error_code)->toBe('solve_timeout');
        $provider->resolve($game->uuid, RequestActionResultVo::success(ActionEnum::CHECK, 0));
        expect($results)->toHaveCount(2);
        $provider->close();
    });
});

it('settles outstanding requests when explicitly closed without a connected socket', function (): void {
    run(function (): void {
        $provider = new CallbackProvider(['request_timeout' => 0.02]);
        $results = [];
        $game = new Game(['uuid' => 'closed-hand']);
        $provider->requestAction($game, function (RequestActionResultVo $result) use (&$results): void {
            $results[] = $result;
        });
        $provider->close();
        Coroutine::sleep(0.04);
        expect($results)->toHaveCount(1)->and($results[0]->error_code)->toBe('provider_unavailable');
    });
});

it('settles in-flight requests when the upstream connection closes', function (): void {
    run(function (): void {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        $listener->bind('127.0.0.1', 0);
        $listener->listen();
        $address = $listener->getsockname();
        if ($address === false) {
            throw new RuntimeException('Fixture bind failed');
        }
        $connected = new Channel(1);
        $release = new Channel(1);
        Coroutine::create(function () use ($listener, $connected, $release): void {
            $client = $listener->accept(1);
            if ($client === false) {
                throw new RuntimeException('Fixture connection missing');
            }
            $request = $client->recv(4096, 1);
            if (preg_match('/Sec-WebSocket-Key:\s*([^\r\n]+)/i', $request, $match) !== 1) {
                throw new RuntimeException('Fixture handshake missing key');
            }
            $accept = base64_encode(sha1(trim($match[1]).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $client->send("HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
            $client->send("\x8a\x00\x89\x00\x81\x02{}");
            $connected->push(true);
            $release->pop(1);
            $client->close();
            $listener->close();
        });
        $provider = new CallbackProvider(['url' => 'ws://127.0.0.1:'.$address['port'], 'request_timeout' => 1, 'reconnect_interval' => 0.1]);
        $provider->connect();
        $connected->pop(1);
        Coroutine::sleep(0.01);
        expect($provider->dataFrames)->toBe([['{}', 1]]);
        $result = new Channel(1);
        $provider->requestAction(new Game(['uuid' => 'disconnect-hand']), function (RequestActionResultVo $value) use ($result): void {
            $result->push($value);
        });
        $release->push(true);
        $failure = $result->pop(1);
        $provider->close();
        expect($failure)->toBeInstanceOf(RequestActionResultVo::class);
        if ($failure instanceof RequestActionResultVo) {
            expect($failure->error_code)->toBe('provider_unavailable');
        }
    });
});
