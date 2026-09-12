<?php

use App\Game\Reducer;
use App\Poker\Providers\ProtoProvider;
use App\Vo\Game\PlayerVo;
use Tests\Fixtures\GameFixture as F;

it('expands all client facts to the expected upstream full history', function () {
    $x = json_decode(file_get_contents(dirname(__DIR__, 5).'/docs/GATEWAY-V1-EXAMPLES.json') ?: throw new RuntimeException('Missing fixture'), true);
    $provider = new ProtoProvider(['url' => 'ws://127.0.0.1:1', 'token' => 'mock']);
    $actual = $provider->history(F::context(), true);
    $expected = $x['packets']['upstream_full_game_log'];
    expect($actual['events'])->toBe($expected['events'])->and($actual['game']['buttonSetToSeat'])->toBe(1)->and($actual['structType'])->toBe('fullGameLog');
});
it('only emits showdown facts in the final fullGameLog', function () {
    $p = new ProtoProvider([]);
    $actual = $p->history(F::context(), false);
    expect(array_column($actual['events'], 'eventType'))->not->toContain('gameOver');
});

it('sends ante only on the game and never as an individual blindPosted event', function () {
    $event = F::events()[0];
    $event['payload']['ante'] = 10;
    $reducer = new Reducer;
    $context = $reducer->apply($reducer->start(1, 'ante-game', $event), $event);
    $history = (new ProtoProvider([]))->history($context);
    expect($history['game']['ante'])->toBe(10);
    expect(array_column($history['events'], 'blindType'))->toBe(['SB', 'BB']);
    expect($context->players->sum(fn (PlayerVo $player) => $player->state->invested))->toBe(180);
});
