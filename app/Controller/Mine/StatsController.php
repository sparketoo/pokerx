<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\GatewayException;
use App\Model\Game;
use App\Request\Mine\Stats\SummaryRequest;
use App\Request\Mine\Stats\TrendRequest;
use Carbon\CarbonImmutable as Date;
use Hyperf\Database\Model\Collection;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class StatsController extends ApiController
{
    public function summary(SummaryRequest $request): JsonResponse
    {
        $statistics = $this->statistics($this->user($request)->id, $request->filters());

        return $this->success([
            'lifetime' => $statistics['lifetime'],
            'range' => $statistics['range'],
            'as_of' => $statistics['as_of'],
        ]);
    }

    public function trend(TrendRequest $request): JsonResponse
    {
        $statistics = $this->statistics($this->user($request)->id, $request->filters());

        return $this->success([
            'items' => $statistics['trend'],
            'as_of' => $statistics['as_of'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     lifetime: array{hands: int, wins: int, invested: float, profit: float, win_rate: float|null},
     *     range: array{hands: int, wins: int, invested: float, profit: float, win_rate: float|null},
     *     trend: list<array{label: string, delta: float, cumulative: float}>,
     *     as_of: string
     * }
     */
    private function statistics(int $userId, array $filters): array
    {
        $start = (string) ($filters['start'] ?? Date::now('Asia/Shanghai')->toDateString());
        $end = (string) ($filters['end'] ?? Date::now('Asia/Shanghai')->toDateString());
        $startDate = Date::parse($start, 'Asia/Shanghai');
        $endDate = Date::parse($end, 'Asia/Shanghai');
        if ($startDate->diffInDays($endDate) > 3660 || $startDate > $endDate) {
            throw GatewayException::eventInvalid();
        }

        /** @var Collection<int, Game> $games */
        $games = Game::query()
            ->where('user_id', $userId)
            ->where('status', 'CLOSED')
            ->with(['events' => fn ($query) => $query->where('type', 'hand_over')])
            ->orderBy('id')
            ->get();

        $lifetime = ['hands' => 0, 'wins' => 0, 'invested' => 0.0, 'profit' => 0.0];
        $range = $lifetime;
        $rows = [];
        foreach ($games as $game) {
            $endedAt = $game->events->first()?->created_at;
            if ($endedAt === null) {
                continue;
            }

            $profit = (float) $game->profit;
            $day = $endedAt->copy()->timezone('Asia/Shanghai')->toDateString();
            $lifetime['hands']++;
            $lifetime['wins'] += (int) ($profit >= 0);
            $lifetime['invested'] += $game->bet_amount;
            $lifetime['profit'] += $profit;
            if ($day >= $start && $day <= $end) {
                $range['hands']++;
                $range['wins'] += (int) ($profit >= 0);
                $range['invested'] += $game->bet_amount;
                $range['profit'] += $profit;
                $rows[] = [
                    'label' => $endedAt->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
                    'day' => $day,
                    'profit' => $profit,
                ];
            }
        }

        $lifetime['win_rate'] = $lifetime['hands'] === 0 ? null : $lifetime['wins'] / $lifetime['hands'];
        $range['win_rate'] = $range['hands'] === 0 ? null : $range['wins'] / $range['hands'];

        return [
            'lifetime' => $lifetime,
            'range' => $range,
            'trend' => $this->trendRows($rows, $startDate, $endDate),
            'as_of' => Date::now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<array{label: string, day: string, profit: float}>  $rows
     * @return list<array{label: string, delta: float, cumulative: float}>
     */
    private function trendRows(array $rows, Date $start, Date $end): array
    {
        $days = (int) $start->diffInDays($end) + 1;
        $trend = [];
        $cumulative = 0.0;
        if ($days === 1) {
            foreach ($rows as $row) {
                $cumulative += $row['profit'];
                $trend[] = ['label' => $row['label'], 'delta' => $row['profit'], 'cumulative' => $cumulative];
            }

            return $trend;
        }

        $buckets = [];
        foreach ($rows as $row) {
            $key = $days > 93 ? substr($row['day'], 0, 7) : $row['day'];
            $buckets[$key] = ($buckets[$key] ?? 0.0) + $row['profit'];
        }
        for ($date = $days > 93 ? $start->startOfMonth() : $start;
            $date <= $end;
            $date = $days > 93 ? $date->addMonth() : $date->addDay()) {
            $key = $days > 93 ? $date->format('Y-m') : $date->toDateString();
            $delta = $buckets[$key] ?? 0.0;
            $cumulative += $delta;
            $trend[] = ['label' => $key, 'delta' => $delta, 'cumulative' => $cumulative];
        }

        return $trend;
    }
}
