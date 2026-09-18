<?php

declare(strict_types=1);

use App\Enum\ActionEnum;
use App\Enum\NetworkEnum;
use App\Enum\SeatTypeEnum;
use App\Game\Providers\ProtoProvider;
use App\Game\Providers\SocketProvider;
use App\Model\Event;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Database\Model\Collection;
use Hyperf\WebSocketClient\Client;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Channel;

use function Tests\run;

it('resolves every documented advice action without waiting for a timeout', function (string $action, ActionEnum $expected, int $amount): void {
    run(function () use ($action, $expected, $amount): void {
        $provider = new ProtoProvider;
        $results = [];
        (new ReflectionMethod($provider, 'setRequestActionCallback'))->invoke($provider, 'test-hand', function (RequestActionResultVo $value) use (&$results): void {
            $results[] = $value;
        });
        try {
            $provider->handlePlayerAction(['gameId' => 'test-hand', 'action' => $action, 'amount' => $amount]);
            expect($results)->toHaveCount(1)
                ->and($results[0]->success)->toBeTrue()
                ->and($results[0]->action)->toBe($expected)
                ->and($results[0]->amount)->toBe($amount);
        } finally {
            $provider->close();
        }
    });
})->with([
    ['fold', ActionEnum::FOLD, 0],
    ['check', ActionEnum::CHECK, 0],
    ['call', ActionEnum::CALL, 2],
    ['bet', ActionEnum::BET, 125],
    ['raise', ActionEnum::RAISE, 450],
    ['all-in', ActionEnum::ALL_IN, 30],
    ['all-In', ActionEnum::ALL_IN, 363],
]);

it('rejects invalid advice immediately instead of leaving the request pending', function (array $advice): void {
    run(function () use ($advice): void {
        $provider = new ProtoProvider;
        $results = [];
        (new ReflectionMethod($provider, 'setRequestActionCallback'))->invoke($provider, 'test-hand', function (RequestActionResultVo $value) use (&$results): void {
            $results[] = $value;
        });
        try {
            $provider->handlePlayerAction(['gameId' => 'test-hand', ...$advice]);
            expect($results)->toHaveCount(1)
                ->and($results[0]->error_code)->toBe('provider_rejected');
        } finally {
            $provider->close();
        }
    });
})->with([
    [['action' => 'unknown', 'amount' => 0]],
    [['action' => 'bet', 'amount' => -1]],
    [['action' => 'bet', 'amount' => 1.25]],
    [['action' => 'call', 'amount' => 'not-a-number']],
]);

/** @param array<mixed> $shown */
function settlementGame(array $shown): Game
{
    $game = new Game(['id' => 12345, 'uuid' => '832d5fc3-98bc-4e02-b8f2-d2bff2c51ea4', 'network' => NetworkEnum::OK, 'big_blind' => 2, 'ante' => 0, 'created_at' => '2026-09-17 15:13:08']);
    $game->setRelation('players', new Collection([
        new GamePlayer(['seat' => 1, 'seat_type' => SeatTypeEnum::SB, 'name' => 'Arven', 'stack' => 24, 'is_hero' => true, 'cards' => ['4c', '2s']]),
        new GamePlayer(['seat' => 2, 'seat_type' => SeatTypeEnum::BB, 'name' => 'Winner', 'stack' => 1093]),
    ]));
    $game->setRelation('events', new Collection([
        new Event(['type' => 'hand_over', 'payload' => ['shown' => $shown, 'winners' => [['name' => 'Winner', 'amount' => 19]]]]),
    ]));

    return $game;
}

it('reports actual shown cards instead of treating private Hero cards as a showdown', function (array $shown, array $expected): void {
    $game = settlementGame($shown);
    $report = (new ProtoProvider)->gameEvents($game, true);
    $events = array_values(array_filter($report['events'], fn (array $event): bool => $event['eventType'] === 'handShown'));
    expect($events)->toBe($expected)
        ->and(array_slice($report['events'], -2))->toBe([
            ['eventType' => 'playerWon', 'name' => 'Winner', 'amount' => 19],
            ['eventType' => 'gameOver'],
        ]);
})->with([
    [[['name' => 'Winner', 'cards' => ['9d', '6h']]], [['eventType' => 'handShown', 'name' => 'Winner', 'cards' => '9d,6h']]],
    [[], []],
    [[['name' => 'Arven', 'cards' => ['4c', '2s']], ['name' => 'Winner', 'cards' => ['9d', '6h']]], [['eventType' => 'handShown', 'name' => 'Arven', 'cards' => '4c,2s'], ['eventType' => 'handShown', 'name' => 'Winner', 'cards' => '9d,6h']]],
]);

/** Connected transport double; provider conversion and response handling remain real. */
function connectedSettlementProvider(): ProtoProvider
{
    $provider = new ProtoProvider;
    $socket = Mockery::mock(Client::class);
    $socket->shouldReceive('push')->andReturn(true);
    $socket->shouldReceive('close')->andReturn(true);
    foreach (['socket' => $socket, 'session' => new stdClass, 'authenticated' => true] as $name => $value) {
        (new ReflectionProperty($name === 'authenticated' ? ProtoProvider::class : SocketProvider::class, $name))->setValue($provider, $value);
    }
    $writer = new Channel(1);
    $writer->push(true);
    (new ReflectionProperty(SocketProvider::class, 'writer'))->setValue($provider, $writer);

    return $provider;
}

it('delivers a late settlement rejection once without needing a pending solve', function (): void {
    run(function (): void {
        $provider = connectedSettlementProvider();
        $game = settlementGame([]);
        $errors = [];
        try {
            $provider->over($game, function (string $reason) use (&$errors): void {
                $errors[] = $reason;
            });
            $response = ['gameId' => $game->uuid, 'error' => 'settlement rejected'];
            (new ReflectionMethod($provider, 'onMessage'))->invoke($provider, $response, 1);
            (new ReflectionMethod($provider, 'onMessage'))->invoke($provider, $response, 1);
            expect($errors)->toBe(['settlement rejected']);
        } finally {
            $provider->close();
            Mockery::close();
        }
    });
});

it('uses a stable compatible external identifier while keeping the internal UUID', function (): void {
    $game = settlementGame([]);
    $provider = new ProtoProvider;
    $ongoing = $provider->gameEvents($game);
    $over = $provider->gameEvents($game, true);
    expect($ongoing['game']['gameId'])->toBe($ongoing['game']['gameDate'].'12345_'.$game->uuid)
        ->and($over['game']['gameId'])->toBe($ongoing['game']['gameId'])
        ->and($game->uuid)->toBe('832d5fc3-98bc-4e02-b8f2-d2bff2c51ea4');
});

it('routes an external identifier back to the original solve callback', function (): void {
    run(function (): void {
        $provider = new ProtoProvider;
        $results = [];
        $uuid = '832d5fc3-98bc-4e02-b8f2-d2bff2c51ea4';
        (new ReflectionMethod($provider, 'setRequestActionCallback'))->invoke($provider, $uuid, function (RequestActionResultVo $result) use (&$results): void {
            $results[] = $result;
        });
        try {
            (new ReflectionMethod($provider, 'onMessage'))->invoke($provider, ['structType' => 'playerAction', 'gameId' => '178965798863812345_'.$uuid, 'action' => 'fold', 'amount' => 0], 1);
            expect($results)->toHaveCount(1)->and($results[0]->success)->toBeTrue();
        } finally {
            $provider->close();
        }
    });
});

it('logs each upstream frame once including authentication without retaining secrets', function (): void {
    run(function (): void {
        $container = ApplicationContext::getContainer();
        $original = $container->get(LoggerInterface::class);
        $logger = new class extends AbstractLogger
        {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        if (! $container instanceof ContainerInterface) {
            throw new LogicException('The application container must support test bindings.');
        }
        $container->set(LoggerInterface::class, $logger);
        $provider = connectedSettlementProvider();
        try {
            (new ReflectionMethod($provider, 'send'))->invoke($provider, ['token' => 'secret-token', 'descr' => 'test']);
            (new ReflectionMethod($provider, 'onFrame'))->invoke($provider, 'receive', '{"result":true,"sessionId":"secret-session","nested":{"token":"nested-secret"}}', 1, null);
            $frames = array_values(array_filter($logger->records, fn (array $record): bool => $record['message'] === 'Proto frame'));
            expect($frames)->toHaveCount(2);
            $encoded = json_encode($logger->records, JSON_THROW_ON_ERROR);
            expect($encoded)->not()->toContain('secret-token')->not()->toContain('secret-session')->not()->toContain('nested-secret');
            expect($frames[0]['context']['sent'])->toBeTrue()
                ->and($frames[0]['context']['message']['token'])->toBe('[redacted]')
                ->and($frames[1]['context']['message']['result'])->toBeTrue();
        } finally {
            $provider->close();
            $container->set(LoggerInterface::class, $original);
            Mockery::close();
        }
    });
});

it('retires an outstanding solve before reporting settlement errors', function (): void {
    run(function (): void {
        $provider = connectedSettlementProvider();
        $game = settlementGame([]);
        $results = [];
        (new ReflectionMethod($provider, 'setRequestActionCallback'))->invoke($provider, $game->uuid, function (RequestActionResultVo $result) use (&$results): void {
            $results[] = $result;
        });
        try {
            $provider->over($game);
            expect($results)->toHaveCount(1)->and($results[0]->error_code)->toBe('solve_stale');
        } finally {
            $provider->close();
            Mockery::close();
        }
    });
});

it('encodes each main-pot share followed by exactly one gameOver', function (): void {
    $game = settlementGame([]);
    $game->setRelation('events', new Collection([
        new Event(['type' => 'hand_over', 'payload' => ['winners' => [
            ['name' => 'Arven', 'amount' => 547], ['name' => 'Winner', 'amount' => 546],
        ]]]),
    ]));
    $provider = new ProtoProvider;
    $report = $provider->gameEvents($game, true);
    expect(array_slice($report['events'], -3))->toBe([
        ['eventType' => 'playerWon', 'name' => 'Arven', 'amount' => 547],
        ['eventType' => 'playerWon', 'name' => 'Winner', 'amount' => 546],
        ['eventType' => 'gameOver'],
    ])->and(array_column($report['events'], 'eventType'))->toContain('gameOver');
    expect(array_count_values(array_column($report['events'], 'eventType'))['gameOver'])->toBe(1);
    expect(array_column($provider->gameEvents($game)['events'], 'eventType'))->not->toContain('playerWon', 'gameOver');
});
