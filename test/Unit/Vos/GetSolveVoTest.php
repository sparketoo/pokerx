<?php

use App\Enum\ActionEnum;
use App\Exception\PokerException;
use App\Vo\Game\GetSolveVo;

it('uses typed enum cases and explicit wire serialization', function () {
    $v = GetSolveVo::success(ActionEnum::ALL_IN, 100);
    expect($v->action)->toBe(ActionEnum::ALL_IN)->and($v->jsonSerialize()['action'])->toBe('all_in');
});
it('carries safe failure reasons without an action', function () {
    $v = GetSolveVo::failure(PokerException::solveTimeout(), 'timeout');
    expect($v->success)->toBeFalse()->and($v->action)->toBeNull()->and($v->reason)->toBe('timeout');
});
it('rejects invalid amounts', function ($action, $amount) {
    expect(fn () => GetSolveVo::success($action, $amount))->toThrow(InvalidArgumentException::class);
})->with([[ActionEnum::CHECK, 1], [ActionEnum::FOLD, 3], [ActionEnum::BET, -1], [ActionEnum::BET, 9007199254740992]]);
