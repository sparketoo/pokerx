<?php

declare(strict_types=1);

use App\Enum\GameEventTypeEnum;
use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        $types = implode("','", GameEventTypeEnum::names());
        Db::statement("ALTER TABLE game_events MODIFY COLUMN type ENUM('{$types}') NOT NULL COMMENT '事件类型'");
    }

    public function down(): void
    {
        // 旧记录可能已使用该类型，且新建表也包含它；回滚时保留枚举值以免丢失事件。
    }
};
