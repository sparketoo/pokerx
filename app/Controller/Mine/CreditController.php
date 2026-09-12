<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\GatewayException;
use App\Model\CreditRecord;
use App\Request\Mine\Credit\RecordRequest;
use Carbon\CarbonImmutable as Date;
use Hyperf\HttpServer\Request;
use Hyperf\Redis\Redis;
use Illuminate\Encryption\Encrypter;
use LogicException;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use Throwable;
use UnexpectedValueException;

use function App\Support\di;

class CreditController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        $s = json_decode(di(Redis::class)->get('{'.$this->user($r)->id.'}:state') ?: 'null', true, 512,
            JSON_THROW_ON_ERROR);
        if (! $s) {
            throw GatewayException::stateRecovering();
        }

        return $this->success([
            'balance' => $s['balance'], 'reserved' => $s['reserved'], 'available' => $s['balance'] - $s['reserved'],
            'sync_pending' => CreditRecord::query()->where('user_id',
                $this->user($r)->id)->whereNull('balance')->exists(),
        ]);
    }

    public function record(RecordRequest $r): JsonResponse
    {
        $f = $r->filters();
        $user = $this->user($r)->id;
        $q = CreditRecord::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        if (($f['type'] ?? 'all') !== 'all') {
            $q->where('type', strtoupper($f['type']));
        }
        $totals = [
            'grant' => (int) (clone $q)->where('type', 'GRANT')->sum('amount'),
            'consume' => -(int) (clone $q)->where('type', 'CONSUME')->sum('amount'),
        ];
        $limit = $r->limit(10);
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
            ): CreditRecord => $row instanceof CreditRecord ? $row : throw new UnexpectedValueException('Invalid query result'));
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $page = [
            'items' => $rows, 'next_cursor' => $more ? di(Encrypter::class)->encryptString(json_encode([
                'user' => $user, 'hash' => $hash,
                'last' => ($rows->last() ?? throw new LogicException('Empty cursor page'))->getKey(),
                'time' => $rows->last()->getRawOriginal('created_at'), 'max' => $max,
            ], JSON_THROW_ON_ERROR)) : null,
        ];

        $page['items']->each(fn ($v) => $v->setAttribute('before',
            $v->balance === null ? null : $v->balance - $v->amount));

        return $this->success($page + ['summary' => $totals]);
    }
}
