<?php

use App\Enum\UserStatusEnum;
use Hyperf\Stringable\Str;

it('preserves every UserStatusEnum case and predicate', function () {
    foreach (UserStatusEnum::cases() as $case) {
        expect(UserStatusEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (UserStatusEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
