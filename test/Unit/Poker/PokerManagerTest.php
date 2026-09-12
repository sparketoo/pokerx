<?php

use App\Poker\PokerManager;
use App\Poker\Providers\MockProvider;
use App\Poker\Providers\ProviderFactory;
use Hyperf\Contract\ConfigInterface;

it('caches factories while isolating each game provider instance', function () {
    $manager = \App\Support\di(PokerManager::class);
    expect($manager->driver('mock'))->toBe($manager->driver('mock'));
    expect($manager->forGame('mock') === $manager->forGame('mock'))->toBeFalse();
});

it('supports custom provider drivers through the manager extension API', function () {
    $manager = \App\Support\di(PokerManager::class);
    $manager->extend('custom', fn () => new ProviderFactory(fn () => new MockProvider));
    \App\Support\di(ConfigInterface::class)->set('poker.test.driver', 'custom');
    expect($manager->forGame('test'))->toBeInstanceOf(MockProvider::class);
    expect(fn () => $manager->forGame('missing'))->toThrow(InvalidArgumentException::class);
});
