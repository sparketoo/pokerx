<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Db::statement("ALTER TABLE game_events MODIFY type ENUM('START','POST_BLIND','STRADDLE_BLIND','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
        Db::statement("UPDATE game_events SET payload = JSON_SET(payload, '$.type', IF(type = 'POST_BLIND', 'POST', 'STRADDLE')), type = 'BLIND_POSTED' WHERE type IN ('POST_BLIND', 'STRADDLE_BLIND')");
        Db::statement("ALTER TABLE game_events MODIFY type ENUM('START','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
    }

    public function down(): void
    {
        if (Db::table('game_events')->where('type', 'BLIND_POSTED')->whereIn('payload->type', ['SB', 'BB'])->exists()) {
            throw new RuntimeException('Cannot roll back recorded SB or BB events');
        }

        Db::statement("ALTER TABLE game_events MODIFY type ENUM('START','POST_BLIND','STRADDLE_BLIND','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
        Db::statement("UPDATE game_events SET type = IF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'POST', 'POST_BLIND', 'STRADDLE_BLIND'), payload = JSON_REMOVE(payload, '$.type') WHERE type = 'BLIND_POSTED'");
        Db::statement("ALTER TABLE game_events MODIFY type ENUM('START','POST_BLIND','STRADDLE_BLIND','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL COMMENT '事件类型'");
    }
};
