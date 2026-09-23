<?php

declare(strict_types=1);

namespace Tests\Feature\Job;

use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Game\GameProviderManager;
use App\Job\GameCloseJob;
use App\Service\GameService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Container;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameCloseJobTest extends DatabaseTestCase
{
    private Container $container;

    private GameService $originalService;

    private GameService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $container = ApplicationContext::getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $service = $container->get(GameService::class);
        self::assertInstanceOf(GameService::class, $service);
        $this->originalService = $service;
        $this->service = new GameService(new GameProviderManager, new FakeProtoHttpRedis);
        $container->set(GameService::class, $this->service);
    }

    protected function tearDown(): void
    {
        $this->container->set(GameService::class, $this->originalService);

        parent::tearDown();
    }

    public function test_finished_game_keeps_its_result_when_close_job_runs(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 220]]], strtotime('2026-09-23 00:00:00 UTC') * 1000);
        $this->service->save($game);
        $this->service->store($game);

        (new GameCloseJob($game->uuid))->handle();

        self::assertSame('OVER', Db::table('games')->value('status'));
        self::assertSame(GameStatusEnum::OVER, $this->service->find($game->uuid)->status);
    }

    public function test_open_game_is_closed_when_close_job_runs(): void
    {
        $game = GameVoFixture::headsUp();
        $this->service->save($game);

        (new GameCloseJob($game->uuid))->handle();

        self::assertSame('CLOSED', Db::table('games')->value('status'));
        self::assertSame(60, (int) Db::table('games')->value('total'));
    }
}
