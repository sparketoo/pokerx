<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Enum\GameEventTypeEnum;
use App\Game\GameProviderManager;
use App\Service\GameService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\GameVoFixture;
use Tests\Support\DatabaseTestCase;

final class GameServiceTest extends DatabaseTestCase
{
    public function test_store_persists_insurance_ledger_and_profit(): void
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
        self::assertSame(['PURCHASE', 'PURCHASE', 'PAYOUT'], Db::table('game_insurances')->where('game_id', $record->id)->orderBy('id')->pluck('type')->all());
        self::assertSame([1, 2, null], Db::table('game_insurances')->where('game_id', $record->id)->orderBy('id')->pluck('pot_id')->all());
        self::assertSame(1, Db::table('game_insurances')->where('game_id', $record->id)->where('payout', 100)->count());
    }
}
