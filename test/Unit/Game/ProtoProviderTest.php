<?php

declare(strict_types=1);

use App\Enum\ActionEnum;
use App\Game\Providers\ProtoProvider;
use App\Vo\Game\RequestActionResultVo;

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
