<?php

declare(strict_types=1);

use App\Enum\GameEventTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        $cases = implode(',', array_map(static fn (string $name): string => "'{$name}'", GameEventTypeEnum::names()));
        Db::statement("ALTER TABLE game_events MODIFY type ENUM({$cases}) NOT NULL COMMENT '事件类型'");
    }

    public function down(): void
    {
        if (Db::table('game_events')->where('type', 'PLAYER_HAS_SQUID')->exists()) {
            throw new RuntimeException('Cannot remove PLAYER_HAS_SQUID while player counts exist.');
        }
        $cases = implode(',', array_map(static fn (string $name): string => "'{$name}'",
            array_filter(GameEventTypeEnum::names(), static fn (string $name): bool => $name !== 'PLAYER_HAS_SQUID')));
        Db::statement("ALTER TABLE game_events MODIFY type ENUM({$cases}) NOT NULL COMMENT '事件类型'");
    }
};
