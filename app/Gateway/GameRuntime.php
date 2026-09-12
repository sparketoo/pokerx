<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Exception\AppException;
use App\Exception\GatewayException;
use App\Exception\PokerException;
use App\Game\Reducer;
use App\Model\Log;
use App\Vo\Game\GameContextVo;
use App\Vo\Game\GetSolveVo;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use LogicException;

use function App\Support\di;
use function App\Support\now;
use function Hyperf\Config\config;

final class GameRuntime
{
    public function __construct(private readonly Reducer $reducer) {}

    public function initialize(int $user, int $balance, bool $isVip = false): void
    {
        $key = '{'.$user.'}:state';
        if (! di(Redis::class)->exists($key) && di(Redis::class)->exists('{'.$user.'}:pending_games')) {
            throw GatewayException::stateRecovering();
        }
        di(Redis::class)->setnx($key, json_encode([
            'is_vip' => $isVip, 'balance' => $balance, 'reserved' => 0, 'games' => [], 'operations' => [],
            'grants' => [],
        ], JSON_THROW_ON_ERROR));
        di(Redis::class)->sadd('users', (string) $user);
        $this->mutate($user, function (array $state) use ($isVip) {
            $state['is_vip'] = $isVip;

            return [$state, [], null];
        });
    }

    public function mutate(int $user, callable $operation): mixed
    {
        $base = '{'.$user.'}:';
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $raw = di(Redis::class)->get($base.'state');
            if (! $raw) {
                throw GatewayException::stateRecovering();
            }
            [$next, $task, $result] = $operation(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            $game = ($task['archive'] ?? false) ? $task['game_id'] : '';
            $context = $game ? json_encode($next['games'][$game]['context'], JSON_THROW_ON_ERROR) : '';
            $log = isset($task['log']) ? json_encode($task['log'] + ['created_at' => now('UTC')->format('Y-m-d H:i:s.u')],
                JSON_THROW_ON_ERROR) : '';
            $ok = di(Redis::class)->eval(
                'if redis.call("GET",KEYS[1])~=ARGV[1] then return 0 end redis.call("SET",KEYS[1],ARGV[2]); if ARGV[3]~="" then redis.call("HSET",KEYS[2],ARGV[3],ARGV[4]) end if ARGV[5]~="" then redis.call("RPUSH",KEYS[3],ARGV[5]) end return 1',
                [
                    $base.'state', $base.'pending_games', $base.'logs', $raw, json_encode($next, JSON_THROW_ON_ERROR),
                    $game, $context, $log,
                ],
                3,
            );
            if ($ok === 1) {
                return $result;
            }
        }
        throw GatewayException::stateRecovering();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function log(int $user, string $direction, string $type, array $payload, ?string $game = null): void
    {
        di(Redis::class)->rpush('{'.$user.'}:logs', json_encode([
            'uuid' => (string) Str::uuid(), 'direction' => $direction, 'type' => $type,
            'payload' => Log::redact($payload), 'game_uuid' => $game,
            'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{context: GameContextVo, duplicate: bool, solve: ?GetSolveVo}
     */
    public function accept(int $user, string $connection, array $event): array
    {
        $this->reducer->validateEnvelope($event);

        return $this->mutate($user, function ($state) use ($user, $connection, $event) {
            $id = $event['id'];
            $existing = $state['operations'][$id] ?? null;
            if ($existing) {
                if ($existing['event'] != $event) {
                    throw GatewayException::eventConflict();
                }
                $c = GameContextVo::fromArray($state['games'][$existing['game_id']]['context']);

                return [$state, [], ['context' => $c, 'duplicate' => true, 'solve' => null]];
            }
            if ($event['type'] === 'game_start') {
                if ($event['seq'] !== 1) {
                    throw GatewayException::eventSequenceGap(['expected_seq' => 1]);
                }
                $uuid = (string) Str::uuid();
                $context = $this->reducer->start($user, $uuid, $event);
            } else {
                $uuid = $event['payload']['hand_id'] ?? '';
                if (! is_string($uuid) || ! isset($state['games'][$uuid])) {
                    throw PokerException::handNotStarted();
                }
                $game = $state['games'][$uuid];
                $context = GameContextVo::fromArray($game['context']);
                if ($game['owner'] !== $connection && json_decode(di(Redis::class)->get('connection:'.$game['owner']) ?: 'null',
                    true, 512, JSON_THROW_ON_ERROR) !== null) {
                    throw GatewayException::gameInUse();
                }
            }
            $next = $this->reducer->apply($context, $event);
            $data = $next->toState();
            $solve = null;
            $archive = $event['type'] === 'game_over';
            if ($context->pending && $event['type'] !== 'game_get_solve') {
                $old = $context->pending->requestId;
                $state['reserved'] -= $context->pending->reservedCost;
                $data['solves'][$old] = array_replace($data['solves'][$old], [
                    'status' => 'stale', 'success' => false, 'error_code' => 'solve_stale', 'reason' => '局面已经变化',
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
                $data['pending'] = null;
                $archive = true;
            }
            if ($event['type'] === 'game_get_solve') {
                $cost = ($state['is_vip'] ?? false) ? 0 : config('poker.cost');
                $failure = $context->pending ? PokerException::solveInProgress() : ($cost > $state['balance'] - $state['reserved'] ? PokerException::insufficientPoints() : null);
                $data['solves'][$id] = [
                    'status' => $failure ? 'failed' : 'pending', 'success' => null, 'action' => null, 'amount' => null,
                    'cost' => 0, 'error_code' => $failure?->getErrorCode(), 'reason' => $failure?->getMessage(),
                    'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ];
                if ($failure) {
                    $data['solves'][$id]['success'] = false;
                    $solve = GetSolveVo::failure($failure);
                } else {
                    $state['reserved'] += $cost;
                    $data['pending'] = [
                        'reserved_cost' => $cost, 'request_id' => $id, 'revision' => $data['revision'],
                        'deadline' => microtime(true) + ($event['payload']['delay'] ?? 9000) / 1000 + 10,
                    ];
                }
            }
            $state['games'][$uuid] = ['owner' => $connection, 'updated_at' => time(), 'context' => $data];
            $state['operations'][$id] = ['event' => $event, 'game_id' => $uuid];
            $task = [
                'game_id' => $uuid, 'archive' => $archive, 'log' => [
                    'uuid' => (string) Str::uuid(), 'direction' => 'CLIENT_IN', 'type' => $event['type'],
                    'payload' => $event, 'game_uuid' => $uuid,
                ],
            ];

            return [
                $state, $task, ['context' => GameContextVo::fromArray($data), 'duplicate' => false, 'solve' => $solve],
            ];
        });
    }

    public function complete(
        int $user,
        string $uuid,
        string $request,
        GetSolveVo $result,
        bool $authorized = true
    ): GetSolveVo {
        return $this->mutate($user, function ($s) use ($uuid, $request, $result, $authorized) {
            if (! isset($s['games'][$uuid])) {
                throw PokerException::handNotStarted();
            }
            $c = $s['games'][$uuid]['context'];
            if (($c['pending']['request_id'] ?? null) !== $request) {
                return [$s, [], GetSolveVo::failure(PokerException::solveStale())];
            }
            if (! $authorized || $c['pending']['revision'] !== $c['revision'] || $c['status'] !== 'open') {
                $result = GetSolveVo::failure(PokerException::solveStale());
            }
            if (microtime(true) > $c['pending']['deadline']) {
                $result = GetSolveVo::failure(PokerException::solveTimeout());
            }
            if ($result->success) {
                // Validate the advice against current factual chips without applying the hypothetical move.
                $fake = [
                    'id' => (string) Str::uuid(), 'type' => 'game_player_acted', 'seq' => $c['last_seq'] + 1,
                    'payload' => [
                        'hand_id' => $uuid, 'name' => $c['hero_name'],
                        'action' => ($result->action ?? throw new LogicException('Successful solve has no action'))->wire(),
                        'amount' => $result->amount,
                    ],
                ];
                try {
                    $this->reducer->apply(GameContextVo::fromArray($c), $fake);
                } catch (AppException) {
                    $result = GetSolveVo::failure(PokerException::providerRejected());
                }
            }
            $cost = $c['pending']['reserved_cost'] ?? config('poker.cost');
            $s['reserved'] -= $cost;
            $status = $result->success ? 'succeeded' : match ($result->error_code) {
                'solve_timeout' => 'timed_out',
                'solve_stale' => 'stale',
                default => 'failed'
            };
            $record = array_replace($c['solves'][$request], $result->jsonSerialize(), [
                'status' => $status, 'cost' => $result->success ? $cost : 0,
                'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
            if ($result->success) {
                $s['balance'] -= $cost;
                $record['balance'] = $s['balance'];
                $record['credit_uuid'] = (string) Str::uuid();
            }
            $c['solves'][$request] = $record;
            $c['pending'] = null;
            $s['games'][$uuid]['context'] = $c;

            return [$s, ['game_id' => $uuid, 'archive' => true], $result];
        });
    }

    public function incomplete(int $user, string $uuid): void
    {
        $this->mutate($user, function ($s) use ($uuid) {
            $g = $s['games'][$uuid] ?? null;
            if (! $g || $g['context']['status'] !== 'open') {
                return [$s, [], null];
            }
            $c = $g['context'];
            if ($c['pending']) {
                $id = $c['pending']['request_id'];
                $cost = $c['pending']['reserved_cost'] ?? config('poker.cost');
                $s['reserved'] -= $cost;
                $c['solves'][$id] = array_replace($c['solves'][$id], [
                    'status' => 'stale', 'success' => false, 'error_code' => 'solve_stale', 'reason' => '牌局中断',
                    'updated_at' => now('UTC')->format('Y-m-d H:i:s.u'),
                ]);
            }
            $c['pending'] = null;
            $c['status'] = 'incomplete';
            $s['games'][$uuid]['context'] = $c;

            return [$s, ['game_id' => $uuid, 'archive' => true], null];
        });
    }
}
