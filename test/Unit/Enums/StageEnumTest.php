<?php

use App\Enum\StageEnum;
use Hyperf\Stringable\Str;

it('preserves every StageEnum case and predicate', function () {
    foreach (StageEnum::cases() as $case) {
        expect(StageEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (StageEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
