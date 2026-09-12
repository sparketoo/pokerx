<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\FoundationException;
use App\Exception\GatewayException;
use App\Model\Log;
use App\Request\Mine\Logs\DetailRequest;
use App\Request\Mine\Logs\ExportRequest;
use App\Request\Mine\Logs\IndexRequest;
use Carbon\CarbonImmutable as Date;
use Illuminate\Encryption\Encrypter;
use LogicException;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use Throwable;
use UnexpectedValueException;

use function App\Support\di;

class LogsController extends ApiController
{
    public function index(IndexRequest $r): JsonResponse
    {
        $f = $r->filters();
        $user = $this->user($r)->id;
        $q = Log::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        if (isset($f['direction'])) {
            $q->where('direction', strtoupper($f['direction']));
        }
        if (isset($f['keyword'])) {
            $q->where('type', 'like', '%'.addcslashes($f['keyword'], '%_').'%');
        }
        if (isset($f['game_id'])) {
            $q->where(fn ($q) => $q->whereHas('game', function ($q) use ($f) {
                $q->where('uuid', $f['game_id']);
            })->orWhere('payload->game_uuid', $f['game_id']));
        }
        if (isset($f['room_id'])) {
            $q->whereHas('game', function ($q) use ($f) {
                $q->where('room_id', $f['room_id']);
            });
        }
        if (isset($f['hand_number'])) {
            $q->whereHas('game', function ($q) use ($f) {
                $q->where('hand_number', $f['hand_number']);
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
            ): Log => $row instanceof Log ? $row : throw new UnexpectedValueException('Invalid query result'));
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

    public function detail(DetailRequest $r): JsonResponse
    {
        $l = Log::query()->where('user_id', $this->user($r)->id)->find($r->logId());
        if (! $l) {
            throw FoundationException::notFound();
        }

        return $this->success($l);
    }

    public function export(ExportRequest $r): JsonResponse
    {
        $f = $r->filters();
        $user = $this->user($r)->id;
        $q = Log::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        if (isset($f['direction'])) {
            $q->where('direction', strtoupper($f['direction']));
        }
        if (isset($f['keyword'])) {
            $q->where('type', 'like', '%'.addcslashes($f['keyword'], '%_').'%');
        }
        if (isset($f['game_id'])) {
            $q->where(fn ($q) => $q->whereHas('game', function ($q) use ($f) {
                $q->where('uuid', $f['game_id']);
            })->orWhere('payload->game_uuid', $f['game_id']));
        }
        if (isset($f['room_id'])) {
            $q->whereHas('game', function ($q) use ($f) {
                $q->where('room_id', $f['room_id']);
            });
        }
        if (isset($f['hand_number'])) {
            $q->whereHas('game', function ($q) use ($f) {
                $q->where('hand_number', $f['hand_number']);
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
            ): Log => $row instanceof Log ? $row : throw new UnexpectedValueException('Invalid query result'));
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $page = [
            'items' => $rows, 'next_cursor' => $more ? di(Encrypter::class)->encryptString(json_encode([
                'user' => $user, 'hash' => $hash,
                'last' => ($rows->last() ?? throw new LogicException('Empty cursor page'))->getKey(),
                'time' => $rows->last()->getRawOriginal('created_at'), 'max' => $max,
            ], JSON_THROW_ON_ERROR)) : null,
        ];

        $p = $page;
        $text = '';
        foreach ($p['items'] as $l) {
            $text .= json_encode([
                'id' => (string) $l->id, 'direction' => $l->direction->wire(), 'type' => $l->type,
                'payload' => $l->payload, 'created_at' => $l->created_at->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }

        return $this->success(['text' => $text, 'filename' => 'pokerx-logs.jsonl', 'next_cursor' => $p['next_cursor']]);
    }
}
