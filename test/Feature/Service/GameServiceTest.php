<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Constants\ErrorCode;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Exception\GameException;
use App\Game\GameProviderManager;
use App\Model\Game;
use App\Model\GameEvent;
use App\Service\GameService;
use App\Vo\Game\GameVo;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameServiceTest extends DatabaseTestCase
{
    public function test_squid_hands_use_their_own_platform_snapshots(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $room = 'squid-'.bin2hex(random_bytes(4));
        $players = [
            ['uid' => 'hero', 'name' => 'Hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ];
        $first = $service->create(1, $room.'#1', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 0);
        foreach ($players as $player) {
            $service->event($first->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
                'uid' => $player['uid'], 'count' => 0,
            ], time() * 1000, 1, 'client-a');
        }
        $service->event($first->uuid, GameEventTypeEnum::GOT_SQUID, [
            'uid' => 'hero', 'count' => 2,
        ], time() * 1000, 1, 'client-a');

        $second = $service->create(1, $room.'#2', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 1);
        $service->event($second->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'hero', 'count' => 1,
        ], time() * 1000, 1, 'client-a');
        $service->event($second->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'villain', 'count' => 0,
        ], time() * 1000, 1, 'client-a');
        self::assertSame(1, $second->squidPlayed);
        self::assertSame(1, $service->find($second->uuid)->squidPlayers['hero']);

        $third = $service->create(1, $room.'#3', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 0);
        $service->event($third->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'hero', 'count' => 0,
        ], time() * 1000, 1, 'client-a');
        self::assertSame(0, $third->squidPlayed);
        self::assertSame(0, $service->find($third->uuid)->squidPlayers['hero']);
    }

    public function test_squid_start_uses_platform_snapshot_when_joining_an_active_round(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $room = 'squid-'.bin2hex(random_bytes(4));
        $game = $service->create(1, $room.'#9', NetworkEnum::OK, 0, 6, 3, [
            ['uid' => 'hero', 'name' => 'Hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ], 1, 'client-a', 'SQUID', 'HUNT', 24, 8, 3, 3);
        $service->event($game->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'hero', 'count' => 1,
        ], time() * 1000, 1, 'client-a');
        $service->event($game->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'villain', 'count' => 2,
        ], time() * 1000, 1, 'client-a');

        self::assertSame(3, $game->squidPlayed);
        self::assertSame(['hero' => 1, 'villain' => 2], $service->find($game->uuid)->squidPlayers);
    }

    public function test_squid_start_requires_played_total_but_accepts_platform_player_counts(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $players = [
            ['uid' => 'hero', 'name' => 'Hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ];
        $room = 'squid-'.bin2hex(random_bytes(4));

        try {
            $service->create(1, $room.'#1', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
                'SQUID', 'HUNT', 24, 8, 1);
            self::fail('The squid progress is required.');
        } catch (GameException $error) {
            self::assertSame(ErrorCode::EVENT_INVALID, $error->getCode());
        }

        $game = $service->create(1, $room.'#2', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 2);
        $service->event($game->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'hero', 'count' => 1,
        ], time() * 1000, 1, 'client-a');
        $service->event($game->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'villain', 'count' => 2,
        ], time() * 1000, 1, 'client-a');
        self::assertSame(['hero' => 1, 'villain' => 2], $service->find($game->uuid)->squidPlayers);
    }

    public function test_late_award_updates_only_its_own_hand(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $room = 'squid-'.bin2hex(random_bytes(4));
        $players = [
            ['uid' => 'hero', 'name' => 'Hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'name' => 'Villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ];
        $first = $service->create(1, $room.'#1', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 0);
        foreach ($players as $player) {
            $service->event($first->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
                'uid' => $player['uid'], 'count' => 0,
            ], time() * 1000, 1, 'client-a');
        }
        $second = $service->create(1, $room.'#2', NetworkEnum::OK, 0, 6, 3, $players, 1, 'client-a',
            'SQUID', 'HUNT', 24, 8, 1, 2);
        $service->event($second->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'hero', 'count' => 2,
        ], time() * 1000, 1, 'client-a');
        $service->event($second->uuid, GameEventTypeEnum::PLAYER_HAS_SQUID, [
            'uid' => 'villain', 'count' => 0,
        ], time() * 1000, 1, 'client-a');

        $award = $service->event($first->uuid, GameEventTypeEnum::GOT_SQUID, [
            'uid' => 'hero', 'count' => 2,
        ], time() * 1000, 1, 'client-a');
        self::assertSame('hero', $award->payload['uid']);
        self::assertSame(2, $second->squidPlayed);
        self::assertSame(1, $service->find($first->uuid)->events->filter(
            fn ($event): bool => $event->type->isGotSquid()
        )->count());
    }

    public function test_same_user_can_store_one_game_per_hero_in_a_shared_hand(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        foreach (['alice', 'bob'] as $index => $heroUid) {
            $game = new GameVo(1, sprintf('11111111-1111-4111-8111-%012d', $index + 1), NetworkEnum::OK, 'table#1', 2, 1, 0, [
                ['uid' => 'alice', 'seat' => 1, 'stack' => 100, 'hero' => $heroUid === 'alice'],
                ['uid' => 'bob', 'seat' => 2, 'stack' => 100, 'hero' => $heroUid === 'bob'],
            ], 1, 'client-'.$index);
            $service->store($game);
        }

        self::assertSame(2, Game::query()->where('user_id', 1)->where('network', 'OK')->where('game_key', 'table#1')->count());
    }

    public function test_store_persists_insurance_events_and_profit(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $game = GameVoFixture::headsUp();
        $now = time() * 1000;
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kh', 'Qd']], $now);
        $game->event(GameEventTypeEnum::INSURANCE_PURCHASED, [
            'uid' => 'hero', 'stage' => 'FLOP', 'pot_id' => 1, 'odds' => '2.50', 'amount' => 40,
        ], $now + 1);
        $game->event(GameEventTypeEnum::INSURANCE_PURCHASED, [
            'uid' => 'hero', 'stage' => 'FLOP', 'pot_id' => 2, 'odds' => '3.00', 'amount' => 20,
        ], $now + 2);
        $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'villain', 'amount' => 0]], 'insurance_payout' => 100,
        ], $now + 3);
        $record = $service->store($game);

        self::assertNotNull($record);
        self::assertSame(60, $record->insurance_amount);
        self::assertSame(100, $record->insurance_payout);
        self::assertSame(30, $record->profit);
        $events = GameEvent::query()->where('game_id', $record->id)->orderBy('id')->get();
        $types = [];
        $payloads = [];
        foreach ($events as $event) {
            self::assertInstanceOf(GameEvent::class, $event);
            $types[] = $event->type->name;
            $payloads[] = $event->payload;
        }
        self::assertSame(['STAGE', 'INSURANCE_PURCHASED', 'INSURANCE_PURCHASED', 'OVER'], $types);
        $firstPurchase = $payloads[1];
        $secondPurchase = $payloads[2];
        $over = $payloads[3];
        self::assertSame(['FLOP', 1, '2.50', 40], [$firstPurchase['stage'], $firstPurchase['pot_id'], $firstPurchase['odds'], $firstPurchase['amount']]);
        self::assertSame(['FLOP', 2, '3.00', 20], [$secondPurchase['stage'], $secondPurchase['pot_id'], $secondPurchase['odds'], $secondPurchase['amount']]);
        self::assertSame(100, $over['insurance_payout']);

        $storedAgain = $service->store($game);
        self::assertSame($record->id, $storedAgain?->id);
        self::assertSame(4, GameEvent::query()->where('game_id', $record->id)->count());
    }

    public function test_store_persists_squid_hand_metadata_and_award_event(): void
    {
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $container->get(Redis::class),
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000102', NetworkEnum::OK, 'room#102', 6, 3, 0, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ], 1, 'client-a', gameType: 'SQUID', squidMode: 'HUNT', squidCost: 24, squidNumber: 8,
            squidRound: 1, squidPlayed: 2);
        $game->event(GameEventTypeEnum::PLAYER_HAS_SQUID, ['uid' => 'hero', 'count' => 2], time() * 1000);
        $game->event(GameEventTypeEnum::PLAYER_HAS_SQUID, ['uid' => 'villain', 'count' => 0], time() * 1000);
        $game->event(GameEventTypeEnum::GOT_SQUID, ['uid' => 'hero', 'count' => 1], time() * 1000);
        $game->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 20]]], time() * 1000);

        $record = $service->store($game);

        self::assertNotNull($record);
        self::assertSame('SQUID', $record->game_type);
        self::assertSame('HUNT', $record->squid_mode);
        self::assertSame(24, $record->squid_cost);
        self::assertSame(8, $record->squid_number);
        self::assertSame(1, $record->squid_round);
        self::assertSame(2, $record->squid_played);
        self::assertSame(['hero' => 2, 'villain' => 0], $record->squid_players);
        $types = [];
        foreach (GameEvent::query()->where('game_id', $record->id)->orderBy('id')->get() as $event) {
            self::assertInstanceOf(GameEvent::class, $event);
            $types[] = $event->type->name;
        }
        self::assertSame(['PLAYER_HAS_SQUID', 'PLAYER_HAS_SQUID', 'GOT_SQUID', 'OVER'], $types);
    }
}
