<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE game_players MODIFY name VARCHAR(128) NOT NULL COMMENT '玩家名称'");
    }

    public function down(): void
    {
        if (Db::table('game_players')->whereRaw('CHAR_LENGTH(name) > 64')->exists()) {
            throw new RuntimeException('Cannot shorten game_players.name while names exceed 64 characters.');
        }

        Db::statement("ALTER TABLE game_players MODIFY name VARCHAR(64) NOT NULL COMMENT '玩家名称'");
    }
};
