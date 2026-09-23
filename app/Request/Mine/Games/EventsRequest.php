<?php

declare(strict_types=1);

namespace App\Request\Mine\Games;

use App\Request\QueryRequest;

class EventsRequest extends QueryRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'game_id' => 'required|string|size:16|alpha_num',
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'scope' => 'nullable|in:all,me',
            'limit' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable|in:asc,desc',
            'cursor' => 'nullable|string|max:4096',
        ];
    }

    public function gameId(): string
    {
        return (string) $this->validated()['game_id'];
    }

    public function scope(): string
    {
        return (string) ($this->validated()['scope'] ?? 'all');
    }
}
