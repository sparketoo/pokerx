<?php

declare(strict_types=1);

use App\Enum\GameEventTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('game_type', 16)->default('NL')->comment('牌局类型');
            $table->string('squid_mode', 16)->nullable()->comment('鱿鱼模式');
            $table->unsignedBigInteger('squid_cost')->nullable()->comment('单个鱿鱼价值');
            $table->unsignedInteger('squid_number')->nullable()->comment('本轮鱿鱼总数');
            $table->unsignedInteger('squid_round')->nullable()->comment('鱿鱼轮次');
            $table->unsignedInteger('squid_played')->nullable()->comment('本手开始前已发出的鱿鱼数');
            $table->json('squid_players')->nullable()->comment('本手开始前各玩家持有数');
        });

        $cases = implode(',', array_map(static fn (string $name): string => "'{$name}'", GameEventTypeEnum::names()));
        Db::statement("ALTER TABLE game_events MODIFY type ENUM({$cases}) NOT NULL COMMENT '事件类型'");
    }

    public function down(): void
    {
        if (Db::table('game_events')->where('type', 'GOT_SQUID')->exists()) {
            throw new RuntimeException('Cannot remove GOT_SQUID from game_events while squid awards exist.');
        }
        $cases = implode(',', array_map(static fn (string $name): string => "'{$name}'",
            array_filter(GameEventTypeEnum::names(), static fn (string $name): bool => $name !== 'GOT_SQUID')));
        Db::statement("ALTER TABLE game_events MODIFY type ENUM({$cases}) NOT NULL COMMENT '事件类型'");

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn([
                'game_type', 'squid_mode', 'squid_cost', 'squid_number',
                'squid_round', 'squid_played', 'squid_players',
            ]);
        });
    }
};
