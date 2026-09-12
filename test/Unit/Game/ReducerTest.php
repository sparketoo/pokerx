<?php

use App\Exception\AppException;
use App\Game\Reducer;
use Tests\Fixtures\GameFixture as F;

it('replays the complete hand with exact contributions and profit', function () {
    $c = F::context();
    expect($c->game->invested)->toBe(1300)->and($c->game->awarded)->toBe(2650)->and($c->game->profit)->toBe(1350)->and($c->game->lastSeq)->toBe(22);
});
it('rejects invalid starts', function (Closure $change) {
    $e = F::events()[0];
    $e['payload'] = $change($e['payload']);
    expect(fn () => (new Reducer)->start(1, 'x', $e))->toThrow(AppException::class);
})->with([
    'missing hero' => [fn ($p) => array_replace($p, ['players' => array_map(fn ($v) => array_replace($v, ['hero' => false]), $p['players'])])],
    'duplicate seat' => [function ($p) {
        $p['players'][1]['seat'] = 1;

        return $p;
    }],
    'bad position' => [function ($p) {
        $p['players'][0]['seat_type'] = 'UNKNOWN';

        return $p;
    }],
    'nonblind payment' => [function ($p) {
        $p['players'][0]['amount'] = 50;

        return $p;
    }],
    'duplicate cards' => [fn ($p) => array_replace($p, ['cards' => ['As', 'As']])],
    'unsafe integer' => [fn ($p) => array_replace($p, ['big_blind' => 9007199254740992])],
    'removed button field' => [fn ($p) => $p + ['button_set_to_seat' => 1]],
]);
it('requires exact sequential events', function () {
    $c = F::context(2);
    $e = F::events()[2];
    $e['seq'] = 4;
    expect(fn () => (new Reducer)->apply($c, $e))->toThrow(AppException::class);
});
it('rejects invalid action payloads', function (Closure $change) {
    $c = F::context(2);
    $e = F::events()[2];
    $e['payload'] = $change($e['payload']);
    expect(fn () => (new Reducer)->apply($c, $e))->toThrow(AppException::class);
})->with([
    [fn ($p) => array_replace($p, ['amount' => -1])], [fn ($p) => array_replace($p, ['name' => 'not_here'])], [fn ($p) => array_replace($p, ['action' => 'check', 'amount' => 0])], [fn ($p) => array_replace($p, ['amount' => 999999999])], [fn ($p) => array_replace($p, ['action' => 'all-in'])],
]);
it('does not mutate the prior immutable context', function () {
    $c = F::context(2);
    $next = (new Reducer)->apply($c, F::events()[2]);
    expect($c->game->lastSeq)->toBe(2)->and($next->game->lastSeq)->toBe(3);
});
it('does not change investments on a solve request', function () {
    $c = F::context(7);
    $next = (new Reducer)->apply($c, F::events()[7]);
    expect($next->toState()['player_states'])->toBe($c->toState()['player_states'])->and($next->game->revision)->toBe($c->game->revision);
});
it('rejects a pot inconsistent with the observed facts', function () {
    $c = F::context(7);
    $e = F::events()[7];
    $e['payload']['pot_for_alpha']++;
    expect(fn () => (new Reducer)->apply($c, $e))->toThrow(AppException::class);
});
it('rejects events after the hand is closed', function () {
    expect(fn () => (new Reducer)->apply(F::context(), F::events()[2]))->toThrow(AppException::class);
});
