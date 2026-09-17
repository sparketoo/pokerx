<?php

declare(strict_types=1);

namespace App\Request\Mine\Games;

use App\Request\QueryRequest;

class IndexRequest extends QueryRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'result' => 'nullable|in:all,win,loss',
            'limit' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable|in:asc,desc',
            'cursor' => 'nullable|string|max:4096',
        ];
    }
}
