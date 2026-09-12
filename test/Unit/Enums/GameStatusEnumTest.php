<?php

use App\Enum\GameStatusEnum;
use Hyperf\Stringable\Str;

it('preserves every GameStatusEnum case and predicate', function () {
    foreach (GameStatusEnum::cases() as $case) {
        expect(GameStatusEnum::fromNameOrFail($case->name))->toBe($case);
        $method = 'is'.Str::studly(strtolower($case->name));
        foreach (GameStatusEnum::cases() as $other) {
            expect($other->$method())->toBe($other === $case);
        }
    }
});
