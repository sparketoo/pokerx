<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        Db::statement('UPDATE game_players SET blind = COALESCE(blind, 0) + post_blind + straddle_blind');
        Db::statement("ALTER TABLE game_players MODIFY blind BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本手实际支付的全部盲注'");
        Db::statement('ALTER TABLE game_players DROP COLUMN post_blind, DROP COLUMN straddle_blind');
    }

    public function down(): void
    {
        Db::statement("ALTER TABLE game_players ADD post_blind BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '盲注：补盲', ADD straddle_blind BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '盲注：自愿盲注'");
        Db::statement("UPDATE game_players AS player LEFT JOIN (SELECT game_id, LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.uid'))) AS uid, SUM(IF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'POST', CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount')) AS UNSIGNED), 0)) AS post_amount, SUM(IF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.type')) = 'STRADDLE', CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.amount')) AS UNSIGNED), 0)) AS straddle_amount FROM game_events WHERE type = 'BLIND_POSTED' GROUP BY game_id, uid) AS posted ON posted.game_id = player.game_id AND posted.uid = LOWER(player.uid) SET player.post_blind = COALESCE(posted.post_amount, 0), player.straddle_blind = COALESCE(posted.straddle_amount, 0), player.blind = player.blind - COALESCE(posted.post_amount, 0) - COALESCE(posted.straddle_amount, 0)");
        Db::statement("ALTER TABLE game_players MODIFY blind BIGINT UNSIGNED NULL COMMENT '盲注：大盲或小盲'");
    }
};
