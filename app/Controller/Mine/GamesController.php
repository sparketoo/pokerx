<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Constants\ErrorCode;
use App\Controller\ApiController;
use App\Enum\GameEventTypeEnum;
use App\Exception\BusinessException;
use App\Model\Game;
use App\Model\GameEvent;
use App\Request\Mine\Games\DetailRequest;
use App\Request\Mine\Games\EventsRequest;
use App\Request\Mine\Games\IndexRequest;
use Carbon\CarbonImmutable as Date;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function Hyperf\Translation\__;

class GamesController extends ApiController
{
    public function index(IndexRequest $r): JsonResponse
    {
        $f = $r->filters();
        $user = $this->user($r)->id;
        $q = Game::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        if (($f['result'] ?? 'all') === 'win') {
            $q->where('profit', '>', 0);
        }
        if (($f['result'] ?? 'all') === 'loss') {
            $q->where('profit', '<', 0);
        }

        $page = $q->orderBy('id', $r->order())
            ->cursorPaginate($r->limit(5), ['*'], 'cursor', $r->cursor());

        return $this->success([
            'items' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'pending' => [],
        ]);
    }

    public function detail(DetailRequest $r): JsonResponse
    {
        $g = Game::query()->where('user_id', $this->user($r)->id)->where('uuid', $r->gameId())->with([
            'gamePlayers' => fn ($q) => $q->orderBy('seat'),
        ])->first();
        if (! $g) {
            throw new BusinessException(__('messages.common.not_found'), ErrorCode::NOT_FOUND);
        }

        return $this->success(['game' => $g, 'live' => null, 'pending' => false]);
    }

    public function events(EventsRequest $r): JsonResponse
    {
        $g = Game::query()->where('user_id', $this->user($r)->id)->where('uuid', $r->gameId())->first();
        if (! $g) {
            throw new BusinessException(__('messages.common.not_found'), ErrorCode::NOT_FOUND);
        }
        $user = $this->user($r)->id;
        $f = $r->filters();
        $q = GameEvent::query()->where('user_id', $user);
        if (isset($f['start'])) {
            $q->where('created_at', '>=', Date::parse($f['start'], 'Asia/Shanghai')->utc());
        }
        if (isset($f['end'])) {
            $q->where('created_at', '<', Date::parse($f['end'], 'Asia/Shanghai')->addDay()->utc());
        }

        $q->where('game_id', $g->id);
        if ($r->scope() === 'me') {
            $heroUid = $g->gamePlayers()->where('is_hero', true)->value('uid');
            if (! is_string($heroUid)) {
                throw new BusinessException(__('messages.common.not_found'), ErrorCode::NOT_FOUND);
            }
            $q->where(function ($q) use ($heroUid) {
                $q->where('type', GameEventTypeEnum::DEALT->name)
                    ->orWhere('payload->uid', $heroUid);
            });
        }

        $page = $q->orderBy('created_at', $r->order())->orderBy('id', $r->order())
            ->cursorPaginate($r->limit(), ['*'], 'cursor', $r->cursor());

        return $this->success([
            'items' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
