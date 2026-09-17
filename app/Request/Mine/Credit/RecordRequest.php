<?php

declare(strict_types=1);

namespace App\Request\Mine\Credit;

use App\Request\QueryRequest;

class RecordRequest extends QueryRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'type' => 'nullable|in:all,grant,consume',
            'limit' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable|in:asc,desc',
            'cursor' => 'nullable|string|max:4096',
        ];
    }
}
