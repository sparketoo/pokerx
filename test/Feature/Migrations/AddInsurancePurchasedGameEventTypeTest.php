<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Hyperf\DbConnection\Db;
use Tests\Support\DatabaseTestCase;

final class AddInsurancePurchasedGameEventTypeTest extends DatabaseTestCase
{
    public function test_existing_events_survive_and_insurance_events_can_be_stored(): void
    {
        Db::statement("ALTER TABLE game_events MODIFY COLUMN type ENUM('START','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
        Db::table('game_events')->insert([
            'user_id' => 1,
            'game_id' => 1,
            'type' => 'OVER',
            'timestamp' => 1,
            'payload' => '{}',
        ]);

        $path = dirname(__DIR__, 3).'/migrations/2026_09_28_000001_add_insurance_purchased_game_event_type.php';
        self::assertFileExists($path);
        $migration = require $path;
        $migration->up();

        Db::table('game_events')->insert([
            'user_id' => 1,
            'game_id' => 1,
            'type' => 'INSURANCE_PURCHASED',
            'timestamp' => 2,
            'payload' => '{}',
        ]);

        self::assertSame(['OVER', 'INSURANCE_PURCHASED'], Db::table('game_events')->orderBy('id')->pluck('type')->all());
    }
}
