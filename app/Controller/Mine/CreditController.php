<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Model\CreditRecord;
use App\Model\User;
use App\Request\Mine\Credit\RecordRequest;
use Carbon\CarbonImmutable as Date;
use Hyperf\HttpServer\Request;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class CreditController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->user($request)->id);

        return $this->success([
            'credit_balance' => $user->credit_balance,
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
        $page = $q->orderBy('id', $r->order())
            ->cursorPaginate($r->limit(10), ['*'], 'cursor', $r->cursor());

        $items = $page->items();
        foreach ($items as $record) {
            if ($record instanceof CreditRecord) {
                $record->setAttribute('before', $record->balance === null ? null : $record->balance - $record->amount);
            }
        }

        return $this->success([
            'items' => $items,
            'next_cursor' => $page->nextCursor()?->encode(),
            'summary' => $totals,
        ]);
    }
}
