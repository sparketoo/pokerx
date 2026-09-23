<?php

declare(strict_types=1);

namespace Tests\Feature\Controller\Mine;

use App\Controller\Mine\StatsController;
use App\Request\Mine\Stats\SummaryRequest;
use App\Request\Mine\Stats\TrendRequest;
use Tests\Support\DatabaseTestCase;

final class StatsControllerTest extends DatabaseTestCase
{
    public function test_summary_counts_every_status_from_games_and_uses_shanghai_date_boundaries(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $this->signIn($user);
        $this->game($user, 'aaaabbbbcccc0001', 'CLOSED', 200, 100, '2026-09-22 15:59:59');
        $this->game($user, 'aaaabbbbcccc0002', 'OPEN', 50, 0, '2026-09-22 16:00:00');
        $this->game($user, 'aaaabbbbcccc0003', 'OVER', 750, 1000, '2026-09-23 04:00:00');
        $this->game($user, 'aaaabbbbcccc0004', 'ABORT', 350, 0, '2026-09-23 15:59:59');
        $this->game($other, 'aaaabbbbcccc0005', 'OVER', 1, 9999, '2026-09-23 04:00:00');

        $data = $this->call(new StatsController, 'summary', SummaryRequest::class, [
            'start' => '2026-09-23', 'end' => '2026-09-23',
        ]);

        self::assertSame([
            'hands' => 4, 'wins' => 1, 'total' => 1350, 'profit' => -250, 'win_rate' => 0.25,
        ], $data['lifetime']);
        self::assertSame([
            'hands' => 3, 'wins' => 1, 'total' => 1150, 'profit' => -150, 'win_rate' => 1 / 3,
        ], $data['range']);
        self::assertArrayHasKey('as_of', $data);
    }

    public function test_trend_uses_game_creation_order_and_integer_profit(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->game($user, 'aaaabbbbcccc0001', 'OVER', 750, 1000, '2026-09-22 16:30:00');
        $this->game($user, 'aaaabbbbcccc0002', 'ABORT', 350, 0, '2026-09-23 04:00:00');
        $this->game($user, 'aaaabbbbcccc0003', 'OPEN', 100, 100, '2026-09-23 04:30:00');

        $data = $this->call(new StatsController, 'trend', TrendRequest::class, [
            'start' => '2026-09-23', 'end' => '2026-09-23',
        ]);

        self::assertSame([
            ['label' => '2026-09-23 00:30', 'profit' => 250, 'cumulative_profit' => 250],
            ['label' => '2026-09-23 12:00', 'profit' => -350, 'cumulative_profit' => -100],
            ['label' => '2026-09-23 12:30', 'profit' => 0, 'cumulative_profit' => -100],
        ], $data['items']);
    }
}
