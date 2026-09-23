<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\GameException;
use App\Model\Game;
use App\Request\Mine\Stats\SummaryRequest;
use App\Request\Mine\Stats\TrendRequest;
use Carbon\CarbonImmutable as Date;
use Hyperf\Database\Model\Builder;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use RuntimeException;

class StatsController extends ApiController
{
    public function summary(SummaryRequest $request): JsonResponse
    {
        $statistics = $this->statistics($this->user($request)->id, $request->filters(), false);

        return $this->success([
            'lifetime' => $statistics['lifetime'],
            'range' => $statistics['range'],
            'as_of' => $statistics['as_of'],
        ]);
    }

    public function trend(TrendRequest $request): JsonResponse
    {
        $statistics = $this->statistics($this->user($request)->id, $request->filters(), true);

        return $this->success([
            'items' => $statistics['trend'],
            'as_of' => $statistics['as_of'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     lifetime: array{hands: int, wins: int, total: int, profit: int, win_rate: float|null},
     *     range: array{hands: int, wins: int, total: int, profit: int, win_rate: float|null},
     *     trend: list<array{label: string, profit: int, cumulative_profit: int}>,
     *     as_of: string
     * }
     */
    private function statistics(int $userId, array $filters, bool $includeTrend): array
    {
        $today = Date::now('Asia/Shanghai')->toDateString();
        $start = (string) ($filters['start'] ?? $today);
        $end = (string) ($filters['end'] ?? $today);
        $startDate = Date::parse($start, 'Asia/Shanghai');
        $endDate = Date::parse($end, 'Asia/Shanghai');
        if ($startDate->diffInDays($endDate) > 3660 || $startDate > $endDate) {
            throw GameException::eventInvalid();
        }

        $games = Game::query()->where('user_id', $userId);
        $rangeGames = (clone $games)
            ->where('created_at', '>=', $startDate->utc())
            ->where('created_at', '<', $endDate->addDay()->utc());

        $lifetime = $this->totals($games);
        $range = $this->totals(clone $rangeGames);

        return [
            'lifetime' => $lifetime,
            'range' => $range,
            'trend' => $includeTrend ? $this->trendRows($rangeGames, $startDate, $endDate) : [],
            'as_of' => Date::now()->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<Game>  $query
     * @return array{hands: int, wins: int, total: int, profit: int, win_rate: float|null}
     */
    private function totals(Builder $query): array
    {
        $row = $query->selectRaw('COUNT(*) AS hand_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN profit > 0 THEN 1 ELSE 0 END), 0) AS win_count')
            ->selectRaw('COALESCE(SUM(total), 0) AS total_sum')
            ->selectRaw('COALESCE(SUM(profit), 0) AS profit_sum')
            ->first();
        if (! $row instanceof Game) {
            throw new RuntimeException('Unable to aggregate game statistics');
        }

        $hands = (int) $row->getAttribute('hand_count');
        $wins = (int) $row->getAttribute('win_count');

        return [
            'hands' => $hands,
            'wins' => $wins,
            'total' => (int) $row->getAttribute('total_sum'),
            'profit' => (int) $row->getAttribute('profit_sum'),
            'win_rate' => $hands === 0 ? null : $wins / $hands,
        ];
    }

    /**
     * @param  Builder<Game>  $games
     * @return list<array{label: string, profit: int, cumulative_profit: int}>
     */
    private function trendRows(Builder $games, Date $start, Date $end): array
    {
        $days = (int) $start->diffInDays($end) + 1;
        $trend = [];
        $cumulative = 0;
        $buckets = [];
        foreach ($games->orderBy('created_at')->orderBy('id')->cursor() as $game) {
            $createdAt = Date::parse((string) $game->getRawOriginal('created_at'), 'UTC')
                ->timezone('Asia/Shanghai');
            $profit = $game->profit;
            if ($days === 1) {
                $cumulative += $profit;
                $trend[] = [
                    'label' => $createdAt->format('Y-m-d H:i'),
                    'profit' => $profit,
                    'cumulative_profit' => $cumulative,
                ];

                continue;
            }

            $key = $days > 93 ? $createdAt->format('Y-m') : $createdAt->toDateString();
            $buckets[$key] = ($buckets[$key] ?? 0) + $profit;
        }
        if ($days === 1) {
            return $trend;
        }

        for ($date = $days > 93 ? $start->startOfMonth() : $start;
            $date <= $end;
            $date = $days > 93 ? $date->addMonth() : $date->addDay()) {
            $key = $days > 93 ? $date->format('Y-m') : $date->toDateString();
            $profit = $buckets[$key] ?? 0;
            $cumulative += $profit;
            $trend[] = ['label' => $key, 'profit' => $profit, 'cumulative_profit' => $cumulative];
        }

        return $trend;
    }
}
