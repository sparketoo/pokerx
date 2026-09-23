<?php

declare(strict_types=1);

namespace Tests\Feature\Controller\Mine;

use App\Controller\Mine\EventsController;
use App\Request\Mine\Events\IndexRequest;
use Tests\Support\DatabaseTestCase;

final class EventsControllerTest extends DatabaseTestCase
{
    public function test_index_searches_payload_and_orders_events_by_creation_time(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->signIn($user);
        $gameId = $this->game($user, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-23 00:00:00');
        $otherGameId = $this->game($other, 'aaaabbbbcccc0002', 'OVER', 100, 200, '2026-09-23 00:00:00');
        $this->event($user, $gameId, 'SHOW', ['uid' => 'hero'], '2026-09-23 00:00:03');
        $this->event($user, $gameId, 'STAGE', ['stage' => 'FLOP'], '2026-09-23 00:00:01');
        $this->event($user, $gameId, 'ACTION', ['uid' => 'hero'], '2026-09-23 00:00:02');
        $this->event($other, $otherGameId, 'ACTION', ['uid' => 'hero'], '2026-09-23 00:00:04');

        $data = $this->call(new EventsController, 'index', IndexRequest::class, [
            'keyword' => 'hero', 'order' => 'asc',
        ]);

        self::assertSame(['ACTION', 'SHOW'], array_column($data['items'], 'type'));
        self::assertSame(['aaaabbbbcccc0001', 'aaaabbbbcccc0001'], array_column(array_column($data['items'], 'game'), 'uuid'));
        self::assertSame('WE', $data['items'][0]['game']['network']);
    }

    public function test_index_cursor_uses_created_at_then_id_for_ties(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $gameId = $this->game($user, 'aaaabbbbcccc0001', 'OVER', 100, 200, '2026-09-23 00:00:00');
        $this->event($user, $gameId, 'STAGE', ['stage' => 'FLOP'], '2026-09-23 00:00:01');
        $this->event($user, $gameId, 'ACTION', ['uid' => 'hero'], '2026-09-23 00:00:01');
        $this->event($user, $gameId, 'SHOW', ['uid' => 'hero'], '2026-09-23 00:00:02');

        $first = $this->call(new EventsController, 'index', IndexRequest::class, ['order' => 'asc', 'limit' => 2]);
        $second = $this->call(new EventsController, 'index', IndexRequest::class, [
            'order' => 'asc', 'limit' => 2, 'cursor' => $first['next_cursor'],
        ]);

        self::assertSame(['STAGE', 'ACTION'], array_column($first['items'], 'type'));
        self::assertSame(['SHOW'], array_column($second['items'], 'type'));
        self::assertNull($second['next_cursor']);
    }
}
