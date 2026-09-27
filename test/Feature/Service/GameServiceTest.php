<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Enum\GameEventTypeEnum;
use App\Game\GameProviderManager;
use App\Model\GameEvent;
use App\Service\GameService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameServiceTest extends DatabaseTestCase
{
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
}
