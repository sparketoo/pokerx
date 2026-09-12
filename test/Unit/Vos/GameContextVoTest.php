<?php

use App\Model\GamePlayer;
use App\Vo\Game\GameContextVo;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameStartedVo;
use App\Vo\Game\PlayerVo;
use App\Vo\Game\StageStartedVo;
use Hyperf\Collection\Collection;
use Tests\Fixtures\GameFixture;

it('exposes typed game players stage and event payloads and round trips a snapshot', function () {
    $context = GameFixture::context(6);
    expect($context->game->stage?->isFlop())->toBeTrue();
    expect($context->players)->toBeInstanceOf(Collection::class);
    expect($context->players->get(0))->toBeInstanceOf(PlayerVo::class);
    expect($context->hero()->isHero)->toBeTrue();
    expect($context->events->last())->toBeInstanceOf(GameEventVo::class);
    expect($context->events->last()?->payload)->toBeInstanceOf(StageStartedVo::class);
    $copy = GameContextVo::fromArray($context->toState());
    expect($copy->toState())->toBe($context->toState());
    expect($copy->jsonSerialize())->toHaveKey('game')->toHaveKey('players');
    expect($copy->solves)->toHaveCount(0);
});

it('uses an unsaved player model for seated attributes without changing the event wire format', function () {
    $context = GameFixture::context(1);
    $payload = $context->events->first()?->payload;
    if (! $payload instanceof GameStartedVo) {
        throw new RuntimeException('Missing start payload');
    }
    $player = $payload->players->first();
    expect($player)->toBeInstanceOf(GamePlayer::class);
    expect($player?->exists)->toBeFalse();
    expect($payload->jsonSerialize())->toEqual(GameFixture::events()[0]['payload']);
});
