<?php

declare(strict_types=1);

use App\Model\UserToken;
use App\Service\UserTokenService;
use Hyperf\Coroutine\Parallel;
use Swoole\Coroutine;
use Tests\Support\TestData;

use function App\Support\now;
use function Tests\run;

it('rejects malformed login tokens without a database lookup', function (string $plain): void {
    expect((new UserTokenService)->findToken($plain))->toBeNull();
})->with(['', 'secret', '0|secret', '-1|secret', '1|', '1|a|b', '1|a b', "1|a\n"]);

it('checks explicit and wildcard abilities and clears the current token', function (): void {
    $service = new UserTokenService;
    $service->withAccessToken(null);
    expect($service->tokenCan('read'))->toBeFalse()->and($service->tokenCant('read'))->toBeTrue();
    $service->withAccessToken(new UserToken(['abilities' => ['read']]));
    expect($service->tokenCan('read'))->toBeTrue()->and($service->tokenCant('write'))->toBeTrue();
    $service->withAccessToken(new UserToken(['abilities' => ['*']]));
    expect($service->tokenCan('write'))->toBeTrue();
    $service->withAccessToken(null);
    expect($service->currentAccessToken())->toBeNull();
});

it('isolates current tokens between concurrent coroutines on one service', function (): void {
    run(function (): void {
        $service = new UserTokenService;
        $parallel = new Parallel(2);
        foreach (['read', 'write'] as $ability) {
            $parallel->add(function () use ($service, $ability): bool {
                $service->withAccessToken(new UserToken(['abilities' => [$ability]]));
                Coroutine::sleep(0.001);

                return $service->tokenCan($ability) && $service->tokenCant($ability === 'read' ? 'write' : 'read');
            });
        }
        expect($parallel->wait())->toBe([true, true]);
        expect($service->currentAccessToken())->toBeNull();
    });
});

it('creates, validates, expires and revokes persisted login tokens', function (): void {
    run(function (): void {
        $user = TestData::user();
        $service = new UserTokenService;
        $plain = $service->createToken($user, 'browser', ['games:read'])->plainTextToken;
        $validated = $service->validateToken($plain);
        if ($validated === null) {
            throw new RuntimeException('A newly created token must validate.');
        }

        expect($validated->user_id)->toBe($user->id)
            ->and($validated->last_used_at)->not->toBeNull()
            ->and($service->revokeToken($user, $plain))->toBeTrue()
            ->and($service->validateToken($plain))->toBeNull();

        $expired = $service->createToken($user, 'expired', ['*'], now()->subSecond())->plainTextToken;
        expect($service->validateToken($expired))->toBeNull();
    });
});
