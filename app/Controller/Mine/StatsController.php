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
use Hyperf\DbConnection\Db as DB;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function App\Support\di;
use function App\Support\now;

class StatsController extends ApiController
{
    public function summary(SummaryRequest $r): JsonResponse
    {
        $user = $this->user($r)->id;
        $f = $r->filters();
        $start = $f['start'] ?? Date::now('Asia/Shanghai')->toDateString();
        $end = $f['end'] ?? Date::now('Asia/Shanghai')->toDateString();
        $a = Date::parse($start, 'Asia/Shanghai');
        $b = Date::parse($end, 'Asia/Shanghai');
        if ($a->diffInDays($b) > 3660 || $a > $b) {
            throw GatewayException::eventInvalid();
        }
        if (isset($f['snapshot'])) {
            $v = json_decode(di(Redis::class)->get('snapshot:'.$f['snapshot']) ?: 'null', true, 512,
                JSON_THROW_ON_ERROR);
            if (! $v || $v['user_id'] !== $user || $v['start'] !== $start || $v['end'] !== $end) {
                throw GatewayException::eventInvalid();
            }

        } else {
            $v = DB::transaction(function () use ($user, $start, $end, $a, $b) {
                /** @var Collection<int, Game> $all */
                $all = Game::query()->where('user_id', $user)->where('status', 'CLOSED')->with([
                    'events' => fn ($q) => $q->where('type', 'game_over'),
                ])->orderBy('id')->get();
                $rows = [];
                $life = ['hands' => 0, 'wins' => 0, 'invested' => 0, 'profit' => 0];
                $range = $life;
                foreach ($all as $g) {
                    $date = $g->events->first()?->created_at;
                    if (! $date) {
                        continue;
                    }
                    $day = $date->copy()->timezone('Asia/Shanghai')->toDateString();
                    $row = [
                        'id' => (string) $g->id, 'uuid' => $g->uuid, 'room_id' => $g->room_number,
                        'hand_number' => $g->hand_number, 'profit' => (float) $g->profit,
                        'invested' => (float) $g->bet_amount, 'awarded' => (float) $g->winnings,
                        'ended_at' => $date->toIso8601String(),
                        'date' => $day, 'status' => 'closed',
                    ];
                    $life['hands']++;
                    $life['wins'] += (int) ($g->profit >= 0);
                    $life['invested'] += $g->bet_amount;
                    $life['profit'] += $g->profit;
                    if ($day >= $start && $day <= $end) {
                        $rows[] = $row;
                        $range['hands']++;
                        $range['wins'] += (int) ($g->profit >= 0);
                        $range['invested'] += $g->bet_amount;
                        $range['profit'] += $g->profit;
                    }
                }
                foreach ([&$life, &$range] as &$set) {
                    $set['win_rate'] = $set['hands'] ? $set['wins'] / $set['hands'] : null;
                }
                unset($set);
                $trend = [];
                $cumulative = 0;
                $days = (int) $a->diffInDays($b) + 1;
                if ($days === 1) {
                    foreach ($rows as $row) {
                        $cumulative += $row['profit'];
                        $trend[] = ['label' => $row['uuid'], 'delta' => $row['profit'], 'cumulative' => $cumulative];
                    }
                } else {
                    $buckets = [];
                    foreach ($rows as $row) {
                        $key = $days > 93 ? substr($row['date'], 0, 7) : $row['date'];
                        $buckets[$key] = ($buckets[$key] ?? 0) + $row['profit'];
                    }
                    for ($date = $days > 93 ? $a->startOfMonth() : $a;
                        $date <= $b;
                        $date = $days > 93 ? $date->addMonth() : $date->addDay()) {
                        $key = $days > 93 ? $date->format('Y-m') : $date->toDateString();
                        $delta = $buckets[$key] ?? 0;
                        $cumulative += $delta;
                        $trend[] = ['label' => $key, 'delta' => $delta, 'cumulative' => $cumulative];
                    }
                }

                return [
                    'user_id' => $user, 'start' => $start, 'end' => $end, 'snapshot' => (string) Str::uuid(),
                    'as_of' => now()->toIso8601String(), 'lifetime' => $life, 'range' => $range, 'trend' => $trend,
                    'games' => $rows,
                ];
            });
            di(Redis::class)->setex('snapshot:'.$v['snapshot'], 900, json_encode($v, JSON_THROW_ON_ERROR));

        }
        $s = $v;

        return $this->success(array_intersect_key($s, array_flip(['lifetime', 'range', 'snapshot', 'as_of'])));
    }

    public function trend(TrendRequest $r): JsonResponse
    {
        $user = $this->user($r)->id;
        $f = $r->filters();
        $start = $f['start'] ?? Date::now('Asia/Shanghai')->toDateString();
        $end = $f['end'] ?? Date::now('Asia/Shanghai')->toDateString();
        $a = Date::parse($start, 'Asia/Shanghai');
        $b = Date::parse($end, 'Asia/Shanghai');
        if ($a->diffInDays($b) > 3660 || $a > $b) {
            throw GatewayException::eventInvalid();
        }
        if (isset($f['snapshot'])) {
            $v = json_decode(di(Redis::class)->get('snapshot:'.$f['snapshot']) ?: 'null', true, 512,
                JSON_THROW_ON_ERROR);
            if (! $v || $v['user_id'] !== $user || $v['start'] !== $start || $v['end'] !== $end) {
                throw GatewayException::eventInvalid();
            }

        } else {
            $v = DB::transaction(function () use ($user, $start, $end, $a, $b) {
                /** @var Collection<int, Game> $all */
                $all = Game::query()->where('user_id', $user)->where('status', 'CLOSED')->with([
                    'events' => fn ($q) => $q->where('type', 'game_over'),
                ])->orderBy('id')->get();
                $rows = [];
                $life = ['hands' => 0, 'wins' => 0, 'invested' => 0, 'profit' => 0];
                $range = $life;
                foreach ($all as $g) {
                    $date = $g->events->first()?->created_at;
                    if (! $date) {
                        continue;
                    }
                    $day = $date->copy()->timezone('Asia/Shanghai')->toDateString();
                    $row = [
                        'id' => (string) $g->id, 'uuid' => $g->uuid, 'room_id' => $g->room_number,
                        'hand_number' => $g->hand_number, 'profit' => (float) $g->profit,
                        'invested' => (float) $g->bet_amount, 'awarded' => (float) $g->winnings,
                        'ended_at' => $date->toIso8601String(),
                        'date' => $day, 'status' => 'closed',
                    ];
                    $life['hands']++;
                    $life['wins'] += (int) ($g->profit >= 0);
                    $life['invested'] += $g->bet_amount;
                    $life['profit'] += $g->profit;
                    if ($day >= $start && $day <= $end) {
                        $rows[] = $row;
                        $range['hands']++;
                        $range['wins'] += (int) ($g->profit >= 0);
                        $range['invested'] += $g->bet_amount;
                        $range['profit'] += $g->profit;
                    }
                }
                foreach ([&$life, &$range] as &$set) {
                    $set['win_rate'] = $set['hands'] ? $set['wins'] / $set['hands'] : null;
                }
                unset($set);
                $trend = [];
                $cumulative = 0;
                $days = (int) $a->diffInDays($b) + 1;
                if ($days === 1) {
                    foreach ($rows as $row) {
                        $cumulative += $row['profit'];
                        $trend[] = ['label' => $row['uuid'], 'delta' => $row['profit'], 'cumulative' => $cumulative];
                    }
                } else {
                    $buckets = [];
                    foreach ($rows as $row) {
                        $key = $days > 93 ? substr($row['date'], 0, 7) : $row['date'];
                        $buckets[$key] = ($buckets[$key] ?? 0) + $row['profit'];
                    }
                    for ($date = $days > 93 ? $a->startOfMonth() : $a;
                        $date <= $b;
                        $date = $days > 93 ? $date->addMonth() : $date->addDay()) {
                        $key = $days > 93 ? $date->format('Y-m') : $date->toDateString();
                        $delta = $buckets[$key] ?? 0;
                        $cumulative += $delta;
                        $trend[] = ['label' => $key, 'delta' => $delta, 'cumulative' => $cumulative];
                    }
                }

                return [
                    'user_id' => $user, 'start' => $start, 'end' => $end, 'snapshot' => (string) Str::uuid(),
                    'as_of' => now()->toIso8601String(), 'lifetime' => $life, 'range' => $range, 'trend' => $trend,
                    'games' => $rows,
                ];
            });
            di(Redis::class)->setex('snapshot:'.$v['snapshot'], 900, json_encode($v, JSON_THROW_ON_ERROR));

        }
        $s = $v;

        return $this->success(['items' => $s['trend'], 'snapshot' => $s['snapshot'], 'as_of' => $s['as_of']]);
    }
}
