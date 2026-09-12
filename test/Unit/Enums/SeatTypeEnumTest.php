<?php

use App\Enum\SeatTypeEnum;
use Hyperf\Stringable\Str;

it('preserves every SeatTypeEnum case and predicate', function () {
    foreach (SeatTypeEnum::cases() as $case) {
        expect(SeatTypeEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (SeatTypeEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
