<?php

declare(strict_types=1);

namespace Tests\Feature\Controller\Mine;

use App\Controller\Mine\GamesController;
use App\Exception\FoundationException;
use App\Request\Mine\Games\DetailRequest;
use App\Request\Mine\Games\EventsRequest;
use App\Request\Mine\Games\IndexRequest;
use Hyperf\DbConnection\Db;
use Hyperf\Validation\ValidationException;
use Tests\Support\DatabaseTestCase;

final class GamesControllerTest extends DatabaseTestCase
{
    public function test_index_filters_results_and_returns_database_field_names_and_enum_values(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->signIn($user);
        $this->game($user, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-22 16:00:00');
        $this->game($user, 'aaaabbbbcccc0002', 'ABORT', 150, 0, '2026-09-23 02:00:00');
        $this->game($user, 'aaaabbbbcccc0003', 'OPEN', 50, 50, '2026-09-23 16:00:00');
        $this->game($other, 'aaaabbbbcccc0004', 'OVER', 1, 999, '2026-09-23 02:00:00');

        $win = $this->call(new GamesController, 'index', IndexRequest::class, [
            'start' => '2026-09-23', 'end' => '2026-09-23', 'result' => 'win',
        ]);
        self::assertSame(['aaaabbbbcccc0001'], array_column($win['items'], 'uuid'));
        self::assertSame('OVER', $win['items'][0]['status']);
        self::assertSame('WE', $win['items'][0]['network']);
        self::assertSame(100, $win['items'][0]['total']);
        self::assertSame(100, $win['items'][0]['profit']);
        self::assertArrayNotHasKey('invested', $win['items'][0]);

        $loss = $this->call(new GamesController, 'index', IndexRequest::class, ['result' => 'loss']);
        self::assertSame(['aaaabbbbcccc0002'], array_column($loss['items'], 'uuid'));
    }

    public function test_index_cursor_pages_are_scoped_to_the_user(): void
    {
        $user = $this->user();
        $this->signIn($user);
        foreach (range(1, 3) as $number) {
            $this->game($user, sprintf('aaaabbbbcccc%04d', $number), 'OVER', 10, 10, '2026-09-23 00:00:00');
        }

        $first = $this->call(new GamesController, 'index', IndexRequest::class, ['limit' => 2, 'order' => 'asc']);
        $second = $this->call(new GamesController, 'index', IndexRequest::class, [
            'limit' => 2, 'order' => 'asc', 'cursor' => $first['next_cursor'],
        ]);

        self::assertSame(['aaaabbbbcccc0001', 'aaaabbbbcccc0002'], array_column($first['items'], 'uuid'));
        self::assertSame(['aaaabbbbcccc0003'], array_column($second['items'], 'uuid'));
        self::assertNull($second['next_cursor']);
    }

    public function test_detail_accepts_generated_game_id_and_orders_players_by_seat(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $id = $this->game($user, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-23 00:00:00');
        foreach ([2 => 'villain', 1 => 'hero'] as $seat => $uid) {
            Db::table('game_players')->insert([
                'game_id' => $id, 'seat' => $seat, 'uid' => $uid, 'name' => $uid,
                'is_hero' => $uid === 'hero', 'stack' => 1000, 'ante' => 0,
                'blind' => 50, 'bet' => 100, 'total' => 150,
            ]);
        }

        $data = $this->call(new GamesController, 'detail', DetailRequest::class, ['game_id' => 'aaaabbbbcccc0001']);

        self::assertSame('aaaabbbbcccc0001', $data['game']['uuid']);
        self::assertSame([1, 2], array_column($data['game']['gamePlayers'], 'seat'));
        self::assertSame([true, false], array_column($data['game']['gamePlayers'], 'is_hero'));
        self::assertFalse($data['pending']);
    }

    public function test_detail_rejects_other_users_game_and_malformed_id(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->signIn($user);
        $this->game($other, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-23 00:00:00');

        try {
            $this->call(new GamesController, 'detail', DetailRequest::class, ['game_id' => 'aaaabbbbcccc0001']);
            self::fail('Other users\' games must be hidden');
        } catch (FoundationException $error) {
            self::assertSame('not_found', $error->getErrorCode());
        }

        $this->expectException(ValidationException::class);
        $this->call(new GamesController, 'detail', DetailRequest::class, ['game_id' => 'short']);
    }

    public function test_events_filter_by_hero_and_sort_by_creation_time_with_cursor(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->signIn($user);
        $id = $this->game($user, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-23 00:00:00');
        Db::table('game_players')->insert([
            'game_id' => $id, 'seat' => 1, 'uid' => 'hero', 'name' => 'Alice',
            'is_hero' => true, 'stack' => 1000, 'ante' => 0, 'blind' => 50,
            'bet' => 100, 'total' => 150,
        ]);
        $this->event($user, $id, 'OVER', ['winners' => []], '2026-09-23 00:00:05');
        $this->event($user, $id, 'STAGE', ['stage' => 'PREFLOP'], '2026-09-23 00:00:01');
        $this->event($user, $id, 'ACTION', ['uid' => 'villain'], '2026-09-23 00:00:03');
        $this->event($user, $id, 'DEALT', ['cards' => ['As', 'Kh']], '2026-09-23 00:00:02');
        $this->event($user, $id, 'ACTION', ['uid' => 'hero'], '2026-09-23 00:00:03');
        $this->event($other, $id, 'ACTION', ['uid' => 'hero'], '2026-09-23 00:00:04');

        $mine = $this->call(new GamesController, 'events', EventsRequest::class, [
            'game_id' => 'aaaabbbbcccc0001', 'scope' => 'me', 'order' => 'asc',
        ]);
        self::assertSame(['DEALT', 'ACTION'], array_column($mine['items'], 'type'));
        $first = $this->call(new GamesController, 'events', EventsRequest::class, [
            'game_id' => 'aaaabbbbcccc0001', 'order' => 'asc', 'limit' => 2,
        ]);
        $second = $this->call(new GamesController, 'events', EventsRequest::class, [
            'game_id' => 'aaaabbbbcccc0001', 'order' => 'asc', 'limit' => 2,
            'cursor' => $first['next_cursor'],
        ]);
        self::assertSame(['STAGE', 'DEALT'], array_column($first['items'], 'type'));
        self::assertSame(['ACTION', 'ACTION'], array_column($second['items'], 'type'));
        self::assertSame(['villain', 'hero'], array_column(array_column($second['items'], 'payload'), 'uid'));
    }
}
