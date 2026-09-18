<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Constants\GameEvent;
use App\Controller\ApiController;
use App\Exception\FoundationException;
use App\Model\Event;
use App\Model\Game;
use App\Request\Mine\Games\DetailRequest;
use App\Request\Mine\Games\EventsRequest;
use App\Request\Mine\Games\IndexRequest;
use Carbon\CarbonImmutable as Date;
use Psr\Http\Message\ResponseInterface as JsonResponse;

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
            'players' => fn ($q) => $q->orderBy('seat'),
        ])->first();
        if (! $g) {
            throw FoundationException::notFound();
        }

        return $this->success(['game' => $g, 'live' => null, 'pending' => false]);
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
                $q->where('payload->name', $hero)->orWhereIn('type', [
                    GameEvent::HAND_START,
                    GameEvent::FORCE_BET,
                    GameEvent::HAND_CARD,
                    GameEvent::REQUEST_ACTION,
                    GameEvent::HAND_OVER,
                    GameEvent::GAME_ABORT,
                ]);
            });
        }

        $page = $q->orderBy('seq', $r->order())
            ->cursorPaginate($r->limit(), ['*'], 'cursor', $r->cursor());

        return $this->success([
            'items' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
