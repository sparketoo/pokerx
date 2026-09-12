<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\FoundationException;
use App\Exception\GatewayException;
use App\Model\Event;
use App\Model\Game;
use App\Request\Mine\Games\DetailRequest;
use App\Request\Mine\Games\EventsRequest;
use App\Request\Mine\Games\IndexRequest;
use Carbon\CarbonImmutable as Date;
use Hyperf\Redis\Redis;
use Illuminate\Encryption\Encrypter;
use LogicException;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use Throwable;
use UnexpectedValueException;

use function App\Support\di;
use function Hyperf\Collection\collect;

class GamesController extends ApiController
{
    public function index(IndexRequest $r): JsonResponse
    {
        $f = $r->filters();
        $pending = [];
        foreach ((json_decode(di(Redis::class)->get('{'.$this->user($r)->id.'}:state') ?: 'null', true, 512,
            JSON_THROW_ON_ERROR)['games'] ?? []) as $uuid => $entry) {
            $c = $entry['context'];
            if ($c['status'] === 'open') {
                $pending[] = [
                    'uuid' => $uuid, 'room_id' => $c['room_id'], 'hand_number' => $c['hand_number'], 'status' => 'open',
                    'profit' => null, 'pending' => true,
                ];
            }
        }
        if ($r->snapshot()) {
            $start = $f['start'] ?? Date::now('Asia/Shanghai')->toDateString();
            $end = $f['end'] ?? Date::now('Asia/Shanghai')->toDateString();
            $s = json_decode(di(Redis::class)->get('snapshot:'.$r->snapshot()) ?: 'null', true, 512,
                JSON_THROW_ON_ERROR);
            if (! $s || $s['user_id'] !== $this->user($r)->id || $s['start'] !== $start || $s['end'] !== $end) {
                throw GatewayException::eventInvalid();
            }
            $games = $s['games'];
            if (! is_array($games)) {
                throw new UnexpectedValueException('Invalid statistics snapshot');
            }
            $rows = collect($games);
            if (($f['result'] ?? 'all') === 'win') {
                $rows = $rows->where('profit', '>', 0);
            }
            if (($f['result'] ?? 'all') === 'loss') {
                $rows = $rows->where('profit', '<', 0);
            }
            $rows = $r->order() === 'desc' ? $rows->reverse() : $rows;
            $offset = 0;
            if ($r->cursor()) {
                try {
                    $c = json_decode(di(Encrypter::class)->decryptString($r->cursor()), true);
                } catch (Throwable) {
                    throw GatewayException::eventInvalid();
                }
                if (($c['snapshot'] ?? null) !== $r->snapshot() || ($c['result'] ?? 'all') !== ($f['result'] ?? 'all') || ($c['order'] ?? null) !== $r->order()) {
                    throw GatewayException::eventInvalid();
                }
                $offset = $c['offset'];
            }
            $next = $offset + $r->limit(5);

            return $this->success([
                'items' => $rows->slice($offset, $r->limit(5))->values(), 'snapshot' => $s['snapshot'],
                'next_cursor' => $next < $rows->count() ? di(Encrypter::class)->encryptString(json_encode([
                    'snapshot' => $r->snapshot(), 'result' => $f['result'] ?? 'all', 'order' => $r->order(),
                    'offset' => $next,
                ], JSON_THROW_ON_ERROR)) : null,
            ]);
        }
        $user = $this->user($r)->id;
        $q = Game::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        $q->withSum('solves', 'cost');
        if (($f['result'] ?? 'all') === 'win') {
            $q->where('profit', '>', 0);
        }
        if (($f['result'] ?? 'all') === 'loss') {
            $q->where('profit', '<', 0);
        }

        $limit = $r->limit(5);
        $order = $f['order'] ?? 'desc';
        $hash = hash('sha256',
            $q->getModel()->getTable().json_encode(array_diff_key($f, array_flip(['cursor', 'limit']))));
        $max = (int) (clone $q)->max('id');
        if (isset($f['cursor'])) {
            try {
                $c = json_decode(di(Encrypter::class)->decryptString($f['cursor']), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw GatewayException::eventInvalid();
            }
            if ($c['user'] !== $user || $c['hash'] !== $hash) {
                throw GatewayException::eventInvalid();
            }
            $operator = $order === 'asc' ? '>' : '<';
            $q->where(function ($q) use ($operator, $c) {
                $q->where('created_at', $operator, $c['time'])->orWhere(fn ($q) => $q->where('created_at',
                    $c['time'])->where('id', $operator, $c['last']));
            });
            $max = $c['max'];
        }

        $rows = $q->where('id', '<=', $max)->orderBy('created_at', $order)->orderBy('id',
            $order)->limit($limit + 1)->get()->map(fn ($row
            ): Game => $row instanceof Game ? $row : throw new UnexpectedValueException('Invalid query result'));
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $page = [
            'items' => $rows, 'next_cursor' => $more ? di(Encrypter::class)->encryptString(json_encode([
                'user' => $user, 'hash' => $hash,
                'last' => ($rows->last() ?? throw new LogicException('Empty cursor page'))->getKey(),
                'time' => $rows->last()->getRawOriginal('created_at'), 'max' => $max,
            ], JSON_THROW_ON_ERROR)) : null,
        ];

        return $this->success($page + ['pending' => $pending]);
    }

    public function detail(DetailRequest $r): JsonResponse
    {
        $g = Game::query()->where('user_id', $this->user($r)->id)->where('uuid', $r->gameId())->with([
            'players', 'solves',
        ])->first();
        $live = json_decode(di(Redis::class)->get('{'.$this->user($r)->id.'}:state') ?: 'null', true, 512,
            JSON_THROW_ON_ERROR)['games'][$r->gameId()]['context'] ?? null;
        if (! $g && ! $live) {
            throw FoundationException::notFound();
        }

        return $this->success(['game' => $g, 'live' => $live, 'pending' => $live && $live['status'] !== 'closed']);
    }

    public function events(EventsRequest $r): JsonResponse
    {
        $g = Game::query()->where('user_id', $this->user($r)->id)->where('uuid', $r->gameId())->first();
        if (! $g) {
            throw FoundationException::notFound();
        }
        $user = $this->user($r)->id;
        $f = $r->filters();
        $q = Event::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        $q->where('game_id', $g->id);
        if ($r->scope() === 'mine') {
            $hero = $g->players()->where('is_hero', true)->value('name');
            $q->where(function ($q) use ($hero) {
                $q->where('payload->name', $hero)->orWhereIn('type', ['game_start', 'game_get_solve', 'game_over']);
            });
        }

        $limit = $r->limit();
        $order = $f['order'] ?? 'desc';
        $hash = hash('sha256',
            $q->getModel()->getTable().json_encode(array_diff_key($f, array_flip(['cursor', 'limit']))));
        $max = (int) (clone $q)->max('id');
        if (isset($f['cursor'])) {
            try {
                $c = json_decode(di(Encrypter::class)->decryptString($f['cursor']), true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw GatewayException::eventInvalid();
            }
            if ($c['user'] !== $user || $c['hash'] !== $hash) {
                throw GatewayException::eventInvalid();
            }
            $operator = $order === 'asc' ? '>' : '<';
            $q->where(function ($q) use ($operator, $c) {
                $q->where('created_at', $operator, $c['time'])->orWhere(fn ($q) => $q->where('created_at',
                    $c['time'])->where('id', $operator, $c['last']));
            });
            $max = $c['max'];
        }

        $rows = $q->where('id', '<=', $max)->orderBy('created_at', $order)->orderBy('id',
            $order)->limit($limit + 1)->get()->map(fn ($row
            ): Event => $row instanceof Event ? $row : throw new UnexpectedValueException('Invalid query result'));
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $page = [
            'items' => $rows, 'next_cursor' => $more ? di(Encrypter::class)->encryptString(json_encode([
                'user' => $user, 'hash' => $hash,
                'last' => ($rows->last() ?? throw new LogicException('Empty cursor page'))->getKey(),
                'time' => $rows->last()->getRawOriginal('created_at'), 'max' => $max,
            ], JSON_THROW_ON_ERROR)) : null,
        ];

        return $this->success($page);
    }
}
