<?php

declare(strict_types=1);

namespace Tests\Support;

use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\Grammar;
use Hyperf\Di\Container;
use PDO;

/** Isolated relational fixture; deliberately never connects to MySQL or Redis. */
final class InMemoryGameDatabase
{
    public static function install(): ConnectionResolverInterface
    {
        $container = ApplicationContext::getContainer();
        assert($container instanceof Container);
        $previous = $container->get(ConnectionResolverInterface::class);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_vip INTEGER)');
        $pdo->exec("CREATE TABLE games (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, user_id INTEGER, network TEXT, room_number TEXT, hand_number INTEGER, provider TEXT, big_blind INTEGER, small_blind INTEGER, ante INTEGER, status TEXT DEFAULT 'OPEN', bet_amount INTEGER, winnings INTEGER DEFAULT 0, profit INTEGER, pot INTEGER, created_at TEXT, updated_at TEXT)");
        $pdo->exec('CREATE TABLE game_players (id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, seat INTEGER, name TEXT, is_hero INTEGER, stack INTEGER, seat_type TEXT, blind_amount INTEGER, bet_amount INTEGER, cards TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT UNIQUE, user_id INTEGER, game_id INTEGER, seq INTEGER, type TEXT, payload TEXT, created_at TEXT, updated_at TEXT, UNIQUE(game_id, seq))');
        $connection = new Connection($pdo);
        $connection->setQueryGrammar(new class extends Grammar
        {
            /**
             * @param  array<string, mixed>  $values
             */
            protected function compileUpdateColumns(Builder $query, array $values): string
            {
                return parent::compileUpdateColumns($query, array_combine(
                    array_map(static fn (string $key): string => str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key, array_keys($values)),
                    array_values($values),
                ));
            }
        });
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => $connection]));

        return $previous;
    }
}
