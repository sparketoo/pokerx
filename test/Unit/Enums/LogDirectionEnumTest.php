<?php

use App\Enum\LogDirectionEnum;
use Hyperf\Stringable\Str;

it('preserves every LogDirectionEnum case and predicate', function () {
    foreach (LogDirectionEnum::cases() as $case) {
        expect(LogDirectionEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (LogDirectionEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
