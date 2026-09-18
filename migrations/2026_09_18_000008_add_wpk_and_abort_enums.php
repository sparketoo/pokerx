<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        // Identity and the existing natural-key unique index are unchanged.
        Db::statement("ALTER TABLE games MODIFY network ENUM('OK','WE','WPK') NOT NULL COMMENT '扑克网络', MODIFY status ENUM('OPEN','CLOSED','SETTLED','INCOMPLETE','ABORT') NOT NULL DEFAULT 'OPEN' COMMENT '游戏状态'");
    }

    public function down(): void
    {
        // Never truncate enum data during rollback.
        if (Db::table('games')->where('network', 'WPK')->orWhere('status', 'ABORT')->exists()) {
            throw new RuntimeException('Cannot remove WPK/ABORT enum values while records use them.');
        }
        Db::statement("ALTER TABLE games MODIFY network ENUM('OK','WE') NOT NULL COMMENT '扑克网络', MODIFY status ENUM('OPEN','CLOSED','SETTLED','INCOMPLETE') NOT NULL DEFAULT 'OPEN' COMMENT '游戏状态'");
    }
};
