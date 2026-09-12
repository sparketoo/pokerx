<?php

declare(strict_types=1);

use App\Service\Support\DistributedLock;
use Hyperf\Redis\Redis;

use function App\Support\di;
use function Tests\run;

it('releases its redis lock after success and after an exception', function (): void {
    run(function (): void {
        $key = 'test:lock:'.str_replace('.', '', uniqid('', true));
        $lock = new DistributedLock($key, 5);

        expect($lock->block(0.1, fn (): string => 'locked'))->toBe('locked')
            ->and(di(Redis::class)->get($key))->toBeFalse();
        expect(fn () => $lock->block(0.1, function (): void {
            throw new RuntimeException('expected');
        }))->toThrow(RuntimeException::class, 'expected')
            ->and(di(Redis::class)->get($key))->toBeFalse();
    });
});
