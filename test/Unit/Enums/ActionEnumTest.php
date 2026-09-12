<?php

use App\Enum\ActionEnum;
use Hyperf\Stringable\Str;

it('preserves every ActionEnum case and predicate', function () {
    foreach (ActionEnum::cases() as $case) {
        expect(ActionEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (ActionEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
