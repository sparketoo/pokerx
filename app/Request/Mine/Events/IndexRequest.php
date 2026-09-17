<?php

declare(strict_types=1);

namespace App\Request\Mine\Events;

use App\Request\QueryRequest;

class IndexRequest extends QueryRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'keyword' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:100',
            'order' => 'nullable|in:asc,desc',
            'cursor' => 'nullable|string|max:4096',
        ];
    }

    public function keyword(): ?string
    {
        $keyword = $this->validated()['keyword'] ?? null;

        $keyword = is_string($keyword) ? trim($keyword) : null;

        return $keyword !== '' ? $keyword : null;
    }
}
