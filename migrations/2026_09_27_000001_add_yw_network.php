<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE games MODIFY COLUMN network ENUM('OK','WE','WPK','YW') NOT NULL COMMENT '扑克网络'");
        Db::statement("ALTER TABLE user_game_config MODIFY COLUMN network ENUM('OK','WE','WPK','YW') NOT NULL");
    }

    public function down(): void
    {
        if (Db::table('games')->where('network', 'YW')->exists()
            || Db::table('user_game_config')->where('network', 'YW')->exists()) {
            throw new RuntimeException('Cannot remove YW while network records exist.');
        }

        Db::statement("ALTER TABLE games MODIFY COLUMN network ENUM('OK','WE','WPK') NOT NULL COMMENT '扑克网络'");
        Db::statement("ALTER TABLE user_game_config MODIFY COLUMN network ENUM('OK','WE','WPK') NOT NULL");
    }
};
