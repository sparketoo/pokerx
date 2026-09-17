<?php

declare(strict_types=1);

use App\Enum\NetworkEnum;

it('preserves every NetworkEnum case and predicate', function (): void {
    foreach (NetworkEnum::cases() as $case) {
        expect(NetworkEnum::fromNameOrFail($case->name))->toBe($case);

        foreach (NetworkEnum::cases() as $other) {
            expect($case->is($other))->toBe($case === $other);
        }
    }

    expect(NetworkEnum::OK->isOk())->toBeTrue()
        ->and(NetworkEnum::OK->isWe())->toBeFalse()
        ->and(NetworkEnum::WE->isWe())->toBeTrue()
        ->and(NetworkEnum::WE->isOk())->toBeFalse();
});
