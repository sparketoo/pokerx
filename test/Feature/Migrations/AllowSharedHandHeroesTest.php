<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Hyperf\Database\Migrations\Migration;
use Hyperf\DbConnection\Db;
use ReflectionMethod;
use Tests\Support\DatabaseTestCase;

final class AllowSharedHandHeroesTest extends DatabaseTestCase
{
    public function test_existing_games_gain_hero_uid_and_can_share_a_game_key(): void
    {
        $this->createLegacyGamesTable();
        Db::table('games')->insert([
            'user_id' => 1, 'network' => 'WE', 'game_key' => 'hand-1',
        ]);
        Db::table('game_players')->insert([
            'game_id' => 1, 'uid' => 'hero-a', 'is_hero' => 1,
        ]);

        $migration = require dirname(__DIR__, 3).'/migrations/2026_09_28_000002_allow_shared_hand_heroes.php';
        self::assertInstanceOf(Migration::class, $migration);
        $up = new ReflectionMethod($migration, 'up');
        $up->invoke($migration);
        $up->invoke($migration);

        self::assertSame('hero-a', Db::table('games')->where('id', 1)->value('hero_uid'));
        self::assertSame('NO', Db::select("SHOW COLUMNS FROM games LIKE 'hero_uid'")[0]->Null);
        self::assertCount(1, Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_hero_uid_unique' AND Seq_in_index = 1"));
        self::assertSame([], Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_unique'"));

        Db::table('games')->insert([
            'user_id' => 1, 'network' => 'WE', 'game_key' => 'hand-1', 'hero_uid' => 'hero-b',
        ]);
        self::assertSame(2, Db::table('games')->count());
    }

    public function test_interrupted_migration_resumes_after_hero_uid_was_added(): void
    {
        $this->createLegacyGamesTable('hero_uid VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,');
        Db::table('games')->insert([
            'user_id' => 1, 'network' => 'WE', 'game_key' => 'hand-2', 'hero_uid' => 'hero-a',
        ]);
        Db::table('game_players')->insert([
            'game_id' => 1, 'uid' => 'hero-a', 'is_hero' => 1,
        ]);

        $migration = require dirname(__DIR__, 3).'/migrations/2026_09_28_000002_allow_shared_hand_heroes.php';
        self::assertInstanceOf(Migration::class, $migration);
        (new ReflectionMethod($migration, 'up'))->invoke($migration);

        self::assertCount(1, Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_hero_uid_unique' AND Seq_in_index = 1"));
        self::assertSame([], Db::select("SHOW INDEX FROM games WHERE Key_name = 'games_user_id_network_game_key_unique'"));
    }

    private function createLegacyGamesTable(string $heroUidColumn = ''): void
    {
        Db::statement('DROP TEMPORARY TABLE games');
        Db::statement('DROP TEMPORARY TABLE game_players');
        Db::statement("CREATE TEMPORARY TABLE games (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            network VARCHAR(16) NOT NULL,
            game_key VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
            {$heroUidColumn}
            UNIQUE KEY games_user_id_network_game_key_unique (user_id, network, game_key)
        )");
        Db::statement('CREATE TEMPORARY TABLE game_players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            game_id BIGINT UNSIGNED NOT NULL,
            uid VARCHAR(64) NOT NULL,
            is_hero BOOLEAN NOT NULL
        )');
    }
}
