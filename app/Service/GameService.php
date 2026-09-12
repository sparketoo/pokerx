<?php

declare(strict_types=1);

namespace App\Service;

use App\Game\Reducer;
use App\Gateway\GameRuntime;
use App\Model\CreditRecord;
use App\Model\Event;
use App\Model\Game;
use App\Model\GamePlayer;
use App\Model\Log;
use App\Model\Solve;
use App\Model\User;
use App\Support\DistributedLock;
use App\Vo\Game\GameContextVo;
use Hyperf\Database\Model\Collection;
use Hyperf\DbConnection\Db as DB;
use Hyperf\Redis\Redis;
use LogicException;
use RuntimeException;

use function App\Support\di;

final class GameService
{
    public function flush(int $user): int
    {
        return (new DistributedLock('{'.$user.'}:persist', 60))->block(3, function () use ($user) {
            $this->syncGrants($user);
            $base = '{'.$user.'}:';
            $count = 0;
            foreach (di(Redis::class)->hgetall($base.'pending_games') as $uuid => $raw) {
                DB::transaction(fn () => $this->handle(GameContextVo::fromArray(json_decode($raw, true, 512,
                    JSON_THROW_ON_ERROR))));
                di(Redis::class)->eval(
                    'if redis.call("HGET",KEYS[1],ARGV[1])==ARGV[2] then return redis.call("HDEL",KEYS[1],ARGV[1]) end return 0',
                    [$base.'pending_games', $uuid, $raw],
                    1,
                );
                $count++;
            }
            $logs = di(Redis::class)->lrange($base.'logs', 0, 99);
            if ($logs) {
                DB::transaction(function () use ($logs, $user) {
                    foreach ($logs as $raw) {
                        $log = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        Log::query()->firstOrCreate(['uuid' => $log['uuid']], [
                            'user_id' => $user, 'game_id' => isset($log['game_uuid']) ? Game::query()->where('uuid',
                                $log['game_uuid'])->value('id') : null, 'direction' => $log['direction'],
                            'type' => $log['type'],
                            'payload' => Log::redact($log['payload'] + ['game_uuid' => $log['game_uuid'] ?? null]),
                            'created_at' => $log['created_at'], 'updated_at' => $log['created_at'],
                        ]);
                    }
                });
                di(Redis::class)->ltrim($base.'logs', count($logs), -1);
                $count += count($logs);
            }

            return $count;
        });
    }

    public function syncGrants(int $user): void
    {
        (new DistributedLock('{'.$user.'}:lock', 30))->block(3, function () use ($user) {
            /** @var Collection<int, CreditRecord> $grants */
            $grants = CreditRecord::query()->where('user_id', $user)->where('type',
                'GRANT')->whereNull('balance')->orderBy('id')->get();
            $runtime = di(GameRuntime::class);
            $account = User::query()->findOrFail($user);
            $runtime->initialize($user, $account->credit_balance - (int) $grants->sum('amount'), $account->is_vip);
            foreach ($grants as $grant) {
                $balance = $runtime->mutate($user, function ($state) use ($grant) {
                    if (! isset($state['grants'][$grant->uuid])) {
                        if ($state['balance'] > Reducer::MAX - $grant->amount) {
                            throw new RuntimeException('Balance overflow');
                        }
                        $state['balance'] += $grant->amount;
                        $state['grants'][$grant->uuid] = $state['balance'];
                    }

                    return [$state, [], $state['grants'][$grant->uuid]];
                });
                $grant->update(['balance' => $balance]);
            }
        });
    }

    public function handle(GameContextVo $context): void
    {
        $c = $context->toState();
        $user = User::query()->lockForUpdate()->where('id', $c['user_id'])->firstOrFail();
        if (! $user instanceof User) {
            throw new LogicException('Invalid user projection');
        }
        $g = Game::query()->firstOrCreate(['uuid' => $c['game_id']], [
            'user_id' => $c['user_id'], 'room_id' => $c['room_id'], 'hand_number' => $c['hand_number'],
            'provider' => $c['provider'], 'big_blind' => $c['big_blind'], 'ante' => $c['ante'], 'status' => 'OPEN',
            'created_at' => $c['created_at'], 'updated_at' => $c['created_at'],
        ]);
        foreach ($c['players'] as $p) {
            GamePlayer::query()->firstOrCreate(['game_id' => $g->id, 'seat' => $p['seat']], [
                'name' => $p['name'], 'is_hero' => $p['hero'], 'stack' => $p['stack'], 'seat_type' => $p['seat_type'],
                'blind_amount' => $p['amount'], 'ante' => min($c['ante'], $p['stack']),
                'cards' => $p['hero'] ? $c['events'][0]['payload']['cards'] : null, 'created_at' => $c['created_at'],
                'updated_at' => $c['created_at'],
            ]);
        }
        $expected = 1;
        foreach ($c['events'] as $e) {
            if ($e['seq'] !== $expected++) {
                throw new RuntimeException('Archive sequence gap');
            }
            $row = Event::query()->firstOrCreate(['user_id' => $c['user_id'], 'uuid' => $e['id']], [
                'game_id' => $g->id, 'seq' => $e['seq'], 'type' => $e['type'], 'payload' => $e['payload'],
                'created_at' => $e['created_at'], 'updated_at' => $e['created_at'],
            ]);
            if ($row->game_id !== $g->id || $row->seq !== $e['seq'] || $row->payload != $e['payload']) {
                throw new RuntimeException('Archive conflict');
            }
            if (! isset($c['solves'][$e['id']])) {
                continue;
            }
            $v = $c['solves'][$e['id']];
            $solve = Solve::query()->firstOrCreate(['event_id' => $row->id], [
                'user_id' => $c['user_id'], 'game_id' => $g->id, 'status' => 'PENDING', 'cost' => 0,
                'created_at' => $v['created_at'], 'updated_at' => $v['created_at'],
            ]);
            if ($solve->status->isPending() && $v['status'] !== 'pending') {
                $solve->fill([
                    'status' => strtoupper($v['status']),
                    'action' => isset($v['action']) ? strtoupper($v['action']) : null, 'amount' => $v['amount'],
                    'cost' => $v['cost'], 'error_code' => isset($v['error_code']) ? strtolower($v['error_code']) : null,
                    'reason' => $v['reason'], 'updated_at' => $v['updated_at'],
                ])->save();
                if ($v['cost'] > 0) {
                    if ($user->credit_balance < $v['cost']) {
                        throw new RuntimeException('Archived balance mismatch');
                    }
                    CreditRecord::query()->create([
                        'uuid' => $v['credit_uuid'], 'user_id' => $user->id, 'hand_id' => $g->id,
                        'solve_id' => $solve->id, 'type' => 'CONSUME', 'amount' => -$v['cost'],
                        'balance' => $v['balance'], 'created_at' => $v['updated_at'], 'updated_at' => $v['updated_at'],
                    ]);
                    $user->credit_balance -= $v['cost'];
                    $user->save();
                }
            }
        }
        if ($g->status->isOpen() && $c['status'] === 'closed') {
            $g->fill(['status' => 'CLOSED'])->save();
        }
        if ($g->status->isClosed() && $c['status'] === 'closed') {
            $g->fill([
                'status' => 'SETTLED', 'invested' => $c['invested'], 'awarded' => $c['awarded'],
                'profit' => $c['profit'],
            ])->save();
        } elseif ($g->status->isOpen() && $c['status'] === 'incomplete') {
            $g->fill(['status' => 'INCOMPLETE'])->save();
        }
    }
}
