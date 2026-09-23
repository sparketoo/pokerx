<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Model\GameEvent;
use App\Request\Mine\Events\IndexRequest;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class EventsController extends ApiController
{
    public function index(IndexRequest $request): JsonResponse
    {
        $query = GameEvent::query()
            ->where('game_events.user_id', $this->user($request)->id)
            ->with('game:id,uuid,room_number,hand_number,network');

        if ($keyword = $request->keyword()) {
            $query->where(function ($query) use ($keyword) {
                $query
                    ->where('game_events.type', 'like', '%'.$keyword.'%')
                    ->orWhereRaw("JSON_SEARCH(game_events.payload, 'all', ?) IS NOT NULL", ['%'.$keyword.'%']);
            });
        }

        $page = $query->orderBy('game_events.created_at', $request->order())
            ->orderBy('game_events.id', $request->order())
            ->cursorPaginate($request->limit(), ['game_events.*'], 'cursor', $request->cursor());

        return $this->success([
            'items' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
