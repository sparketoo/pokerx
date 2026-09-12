<?php

use App\Enum\SolveStatusEnum;
use Hyperf\Stringable\Str;

it('preserves every SolveStatusEnum case and predicate', function () {
    foreach (SolveStatusEnum::cases() as $case) {
        expect(SolveStatusEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (SolveStatusEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
