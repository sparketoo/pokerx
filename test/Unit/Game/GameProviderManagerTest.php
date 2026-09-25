<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameProviderManager;
use App\Game\Providers\IdelProvider;
use App\Game\Providers\ProtoHttpProvider;
use App\Game\Providers\ProtoProvider;
use Tests\TestCase;

final class GameProviderManagerTest extends TestCase
{
    public function test_it_resolves_one_shared_proto_provider(): void
    {
        $manager = new GameProviderManager;

        self::assertInstanceOf(ProtoProvider::class, $manager->provider('proto'));
        self::assertSame($manager->provider('proto'), $manager->provider('proto'));
    }

    public function test_it_resolves_proto_http_provider(): void
    {
        $manager = new GameProviderManager;

        self::assertInstanceOf(ProtoHttpProvider::class, $manager->provider('proto_http'));
    }

    public function test_it_resolves_idel_provider(): void
    {
        $manager = new GameProviderManager;

        self::assertInstanceOf(IdelProvider::class, $manager->provider('idel'));
    }
}
