<?php

declare(strict_types=1);

use App\Enum\ActionEnum;
use App\Vo\Game\RequestActionResultVo;

it('preserves integer recommendation amounts without scaling', function (): void {
    expect(RequestActionResultVo::success(ActionEnum::CALL, 2)->amount)->toBe(2)
        ->and(RequestActionResultVo::success(ActionEnum::RAISE, 725)->amount)->toBe(725)
        ->and(RequestActionResultVo::success(ActionEnum::CHECK, 0)->amount)->toBe(0);
});

it('rejects fractional negative and unsafe recommendation amounts without truncation', function (): void {
    foreach ([INF, NAN, -1, 0.02, 1.25, 9007199254740992] as $amount) {
        expect(fn () => RequestActionResultVo::success(ActionEnum::CALL, $amount))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => RequestActionResultVo::success(ActionEnum::CHECK, 1))->toThrow(InvalidArgumentException::class);
});
