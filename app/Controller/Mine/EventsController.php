<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Model\Event;
use App\Request\Mine\Events\IndexRequest;
use Psr\Http\Message\ResponseInterface as JsonResponse;

class EventsController extends ApiController
{
    public function index(IndexRequest $request): JsonResponse
    {
        $query = Event::query()
            ->where('events.user_id', $this->user($request)->id)
            ->with('game:id,uuid,room_number,hand_number,network');

        if ($keyword = $request->keyword()) {
            $query->where(function ($query) use ($keyword) {
                $query
                    ->where('events.type', 'like', '%'.$keyword.'%')
                    ->orWhereRaw("JSON_SEARCH(events.payload, 'all', ?) IS NOT NULL", ['%'.$keyword.'%']);
            });
        }

        $page = $query->orderBy('events.id', $request->order())
            ->cursorPaginate($request->limit(), ['events.*'], 'cursor', $request->cursor());

        return $this->success([
            'items' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }
}
