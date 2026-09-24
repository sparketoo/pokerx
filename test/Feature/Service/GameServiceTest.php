<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Exception\GameException;
use App\Game\GameProviderManager;
use App\Job\GameCloseJob;
use App\Model\GamePlayer;
use App\Service\GameService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Container;
use Tests\Fixtures\FakeGameQueueDriver;
use Tests\Fixtures\FakeGameQueueDriverFactory;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameServiceTest extends DatabaseTestCase
{
    private Container $container;

    private DriverFactory $originalQueueFactory;

    private FakeGameQueueDriver $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $container = ApplicationContext::getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $factory = $container->get(DriverFactory::class);
        self::assertInstanceOf(DriverFactory::class, $factory);
        $this->originalQueueFactory = $factory;
        $this->queue = new FakeGameQueueDriver;
        $container->set(DriverFactory::class, new FakeGameQueueDriverFactory($this->queue));
    }

    protected function tearDown(): void
    {
        $this->container->set(DriverFactory::class, $this->originalQueueFactory);

        parent::tearDown();
    }

    public function test_create_saves_game_and_schedules_delayed_close(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);

        $game = $service->create(1, 'room-1#1', NetworkEnum::WE, 10, 100, 50, [
            ['uid' => 'hero', 'name' => 'Alice', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Bob', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $game->uuid);
        self::assertSame('room-1#1', $game->gameKey);
        self::assertSame($game->uuid, $service->find($game->uuid)->uuid);
        self::assertCount(1, $this->queue->pushed);
        self::assertInstanceOf(GameCloseJob::class, $this->queue->pushed[0]['job']);
        self::assertSame($game->uuid, $this->queue->pushed[0]['job']->uuid);
        self::assertSame(1800, $this->queue->pushed[0]['delay']);
    }

    public function test_create_rejects_a_game_key_already_active_in_redis(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);
        $players = [
            ['uid' => 'hero', 'name' => 'Alice', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Bob', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ];

        $game = $service->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);

        try {
            (new GameService(new GameProviderManager, $redis))->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
            self::fail('A second START must not create another live game');
        } catch (GameException $error) {
            self::assertSame('game_already_exists', $error->getErrorCode());
        }
        self::assertSame($game->uuid, $service->find($game->uuid)->uuid);
        $otherUser = $service->create(2, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
        $otherNetwork = $service->create(1, 'table-42#1', NetworkEnum::OK, 0, 100, 50, $players, 1);
        self::assertNotSame($game->uuid, $otherUser->uuid);
        self::assertNotSame($game->uuid, $otherNetwork->uuid);
        self::assertCount(3, $this->queue->pushed);
    }

    public function test_create_rejects_a_game_key_already_saved_in_database(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);
        $players = [
            ['uid' => 'hero', 'name' => 'Alice', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Bob', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ];

        $game = $service->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
        $service->store($game);
        $redis->advance(3601);

        try {
            $service->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
            self::fail('A saved game must prevent another START after Redis expiry');
        } catch (GameException $error) {
            self::assertSame('game_already_exists', $error->getErrorCode());
        }
        self::assertSame(1, Db::table('games')->count());
        self::assertCount(1, $this->queue->pushed);
    }

    public function test_live_game_cannot_be_started_again_between_index_and_snapshot_expiry(): void
    {
        $redis = new FakeProtoHttpRedis;
        $redis->gameWriteDelay = 0.5;
        $service = new GameService(new GameProviderManager, $redis);
        $players = [
            ['uid' => 'hero', 'name' => 'Alice', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Bob', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ];

        $game = $service->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
        $redis->advance(3599.7);
        self::assertSame($game->uuid, $service->find($game->uuid)->uuid);

        try {
            $service->create(1, 'table-42#1', NetworkEnum::WE, 0, 100, 50, $players, 1);
            self::fail('A still readable game must prevent another START');
        } catch (GameException $error) {
            self::assertSame('game_already_exists', $error->getErrorCode());
        }
    }

    public function test_save_find_and_event_preserve_the_latest_game_snapshot(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);
        $game = GameVoFixture::headsUp();

        $service->save($game);
        $event = $service->event($game->uuid, GameEventTypeEnum::ACTION, [
            'uid' => 'hero', 'action' => 'CALL', 'amount' => 50,
        ], 1);
        $restored = $service->find($game->uuid);

        self::assertSame('hero', $event->player?->uid);
        self::assertSame(110, $restored->hero()->total());
        self::assertCount(1, $restored->events);
        self::assertSame(3600, $redis->ttl('game:'.$game->uuid));
        self::assertSame(60, $game->hero()->total());
    }

    public function test_find_rejects_missing_or_invalid_game_data(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);

        foreach (['11111111-1111-4111-8111-000000000001', '11111111-1111-4111-8111-000000000002'] as $uuid) {
            if ($uuid === '11111111-1111-4111-8111-000000000002') {
                $redis->setex('game:'.$uuid, 3600, 'invalid-game-data');
            }

            try {
                $service->find($uuid);
                self::fail('Missing or invalid game data must be rejected');
            } catch (GameException $error) {
                self::assertSame('game_uuid_not_found', $error->getErrorCode());
            }
        }
    }

    public function test_store_persists_totals_players_events_and_is_idempotent(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);
        $game = GameVoFixture::headsUp();
        $eventTime = strtotime('2026-09-23 00:00:00 UTC') * 1000;
        $game->event(GameEventTypeEnum::DEALT, ['cards' => ['As', 'Kh']], $eventTime);
        $game->event(GameEventTypeEnum::ACTION, [
            'uid' => 'hero', 'action' => 'CALL', 'amount' => 50,
        ], $eventTime + 1000);
        $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero', 'amount' => 220]],
        ], $eventTime + 2000);

        $first = $service->store($game);
        $second = $service->store($game);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, Db::table('games')->count());
        self::assertSame('OVER', Db::table('games')->value('status'));
        self::assertSame(110, (int) Db::table('games')->value('total'));
        self::assertSame(220, (int) Db::table('games')->value('winnings'));
        self::assertSame(110, (int) Db::table('games')->value('profit'));
        self::assertSame(2, Db::table('game_players')->count());
        self::assertSame(1, Db::table('game_players')->where('uid', 'hero')->value('is_hero'));
        self::assertSame('As,Kh', Db::table('game_players')->where('uid', 'hero')->value('cards'));
        self::assertSame(['DEALT', 'ACTION', 'OVER'], Db::table('game_events')->orderBy('id')->pluck('type')->all());
        self::assertSame('hero', json_decode(Db::table('game_events')->where('type', 'ACTION')->value('payload'), true, 512, JSON_THROW_ON_ERROR)['uid']);
    }

    public function test_store_persists_post_blind_separately_from_regular_blind(): void
    {
        $service = new GameService(new GameProviderManager, new FakeProtoHttpRedis);
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'hero', 'amount' => 20], strtotime('2026-09-23 00:00:00 UTC') * 1000);

        $service->store($game);

        $hero = GamePlayer::query()->where('uid', 'hero')->firstOrFail();
        self::assertSame(50, (int) $hero->blind);
        self::assertSame(20, (int) $hero->post_blind);
        self::assertSame(80, (int) $hero->total);
        self::assertSame(190, (int) Db::table('games')->value('pot'));
        self::assertSame(80, (int) Db::table('games')->value('total'));
        self::assertSame('POST_BLIND', Db::table('game_events')->value('type'));
    }

    public function test_store_persists_straddle_and_returned_bet_with_net_totals(): void
    {
        $service = new GameService(new GameProviderManager, new FakeProtoHttpRedis);
        $game = GameVoFixture::headsUp();
        $time = strtotime('2026-09-23 00:00:00 UTC') * 1000;
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], $time);
        $game->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'hero', 'amount' => 200], $time + 1);
        $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero', 'amount' => 300]],
            'returns' => [['uid' => 'hero', 'amount' => 10]],
        ], $time + 2);

        $service->store($game);

        $hero = GamePlayer::query()->where('uid', 'hero')->firstOrFail();
        self::assertSame(200, $hero->straddle_blind);
        self::assertSame(10, $hero->returned);
        self::assertSame(250, $hero->total);
        self::assertSame(360, (int) Db::table('games')->value('pot'));
        self::assertSame(50, (int) Db::table('games')->value('profit'));
        self::assertSame(['STAGE', 'STRADDLE_BLIND', 'OVER'], Db::table('game_events')->orderBy('id')->pluck('type')->all());
    }

    public function test_store_persists_the_provided_game(): void
    {
        $redis = new FakeProtoHttpRedis;
        $service = new GameService(new GameProviderManager, $redis);
        $older = GameVoFixture::headsUp();
        $latest = GameVoFixture::headsUp();
        $eventTime = strtotime('2026-09-23 00:00:00 UTC') * 1000;
        $latest->event(GameEventTypeEnum::DEALT, ['cards' => ['As', 'Kh']], $eventTime);
        $latest->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 220]]], $eventTime + 1000);
        $service->save($latest);

        $stored = $service->store($older);

        self::assertSame(GameStatusEnum::OPEN, $stored->status);
        self::assertSame(0, $stored->winnings);
        self::assertSame(0, Db::table('game_events')->count());
    }

    public function test_store_does_not_replace_a_record_with_fewer_events(): void
    {
        $service = new GameService(new GameProviderManager, new FakeProtoHttpRedis);
        $older = GameVoFixture::headsUp();
        $complete = GameVoFixture::headsUp();
        $eventTime = strtotime('2026-09-23 00:00:00 UTC') * 1000;
        $complete->event(GameEventTypeEnum::DEALT, ['cards' => ['As', 'Kh']], $eventTime);
        $complete->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 220]]], $eventTime + 1000);

        $service->store($complete);
        $stored = $service->store($older);

        self::assertSame(GameStatusEnum::OVER, $stored->status);
        self::assertSame(220, $stored->winnings);
        self::assertSame(2, Db::table('game_events')->count());
    }
}
