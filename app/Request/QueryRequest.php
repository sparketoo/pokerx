<?php

declare(strict_types=1);

namespace App\Request;

abstract class QueryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->validated();
    }

    public function limit(int $default = 50): int
    {
        return (int) ($this->validated()['limit'] ?? $default);
    }

    public function order(): string
    {
        return $this->input('order', 'desc');
    }

    public function cursor(): ?string
    {
        return $this->input('cursor');
    }
}
