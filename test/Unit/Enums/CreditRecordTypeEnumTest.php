<?php

use App\Enum\CreditRecordTypeEnum;
use Hyperf\Stringable\Str;

it('preserves every CreditRecordTypeEnum case and predicate', function () {
    foreach (CreditRecordTypeEnum::cases() as $case) {
        expect(CreditRecordTypeEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (CreditRecordTypeEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
