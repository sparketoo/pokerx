<?php

declare(strict_types=1);

namespace App\Controller;

use App\Gateway\GameRuntime;
use App\Model\CreditRecord;
use App\Request\Mine\UpdateLanguageRequest;
use App\Request\Mine\UpdateNicknameRequest;
use Hyperf\HttpServer\Request;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function App\Support\di;

class MineController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        di(GameRuntime::class)->initialize($this->user($r)->id,
            $this->user($r)->credit_balance - (int) CreditRecord::query()->where('user_id',
                $this->user($r)->id)->where('type', 'GRANT')->whereNull('balance')->sum('amount'),
            $this->user($r)->is_vip);
        $s = json_decode(di(Redis::class)->get('{'.$this->user($r)->id.'}:state') ?: 'null', true, 512,
            JSON_THROW_ON_ERROR);

        return $this->success($this->profile($this->user($r)) + [
            'credit' => [
                'balance' => $s['balance'], 'reserved' => $s['reserved'],
                'available' => $s['balance'] - $s['reserved'],
            ],
        ]);
    }

    public function updateNickname(UpdateNicknameRequest $r): JsonResponse
    {
        $this->user($r)->update(['nickname' => $r->nickname()]);

        return $this->success(['nickname' => $r->nickname()]);
    }

    public function updateLanguage(UpdateLanguageRequest $r): JsonResponse
    {
        $this->user($r)->update(['language' => $r->language()]);

        return $this->success(['language' => $r->language()]);
    }
}
