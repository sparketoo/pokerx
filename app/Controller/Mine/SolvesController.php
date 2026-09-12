<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\FoundationException;
use App\Model\Solve;
use App\Request\Mine\Solves\DetailRequest;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function App\Support\di;

class SolvesController extends ApiController
{
    public function detail(DetailRequest $r): JsonResponse
    {
        $s = json_decode(di(Redis::class)->get('{'.$this->user($r)->id.'}:state') ?: 'null', true, 512,
            JSON_THROW_ON_ERROR)['games'][$r->gameId()]['context']['solves'][$r->requestId()] ?? null;
        if ($s) {
            return $this->success($s + ['executable' => false]);
        }
        $s = Solve::query()->where('user_id', $this->user($r)->id)->whereHas('game', function ($q) use ($r) {
            $q->where('uuid', $r->gameId());
        })->whereHas('event', function ($q) use ($r) {
            $q->where('uuid', $r->requestId());
        })->first();
        if (! $s) {
            throw FoundationException::notFound();
        }

        return $this->success([
            'status' => $s->status, 'success' => $s->status->isPending() ? null : $s->status->isSucceeded(),
            'action' => $s->action, 'amount' => $s->amount, 'error_code' => $s->error_code, 'reason' => $s->reason,
            'cost' => $s->cost, 'executable' => false,
        ]);
    }
}
