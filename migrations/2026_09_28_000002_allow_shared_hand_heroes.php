<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

return new class extends Migration
{
    public function up(): void
    {
        if (Db::select("SHOW COLUMNS FROM games LIKE 'hero_uid'", [], false) === []) {
            Schema::table('games', function (Blueprint $table): void {
                $table->string('hero_uid', 64)->nullable()->collation('utf8mb4_bin')->after('game_key');
            });
        }
        Db::statement('UPDATE games AS g INNER JOIN game_players AS p ON p.game_id = g.id AND p.is_hero = 1 SET g.hero_uid = p.uid');
        Db::statement('ALTER TABLE games MODIFY hero_uid VARCHAR(64) COLLATE utf8mb4_bin NOT NULL');
        if (Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_hero_uid_unique'", [], false) === []) {
            Schema::table('games', function (Blueprint $table): void {
                $table->unique(['user_id', 'network', 'game_key', 'hero_uid']);
            });
        }
        if (Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_unique'", [], false) !== []) {
            Schema::table('games', function (Blueprint $table): void {
                $table->dropUnique('games_user_id_network_game_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table): void {
            $table->dropUnique('games_user_network_game_key_hero_uid_unique');
            $table->unique(['user_id', 'network', 'game_key']);
            $table->dropColumn('hero_uid');
        });
    }
};
