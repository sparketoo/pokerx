<?php

declare(strict_types=1);

namespace App\Request\Mine\Stats;

use App\Request\QueryRequest;

class TrendRequest extends QueryRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'start' => 'nullable|date_format:Y-m-d',
            'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
        ];
    }
}
