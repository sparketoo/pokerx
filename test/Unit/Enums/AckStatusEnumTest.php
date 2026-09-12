<?php

use App\Enum\AckStatusEnum;
use Hyperf\Stringable\Str;

it('preserves every AckStatusEnum case and predicate', function () {
    foreach (AckStatusEnum::cases() as $case) {
        expect(AckStatusEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (AckStatusEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
