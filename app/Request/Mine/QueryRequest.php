<?php

declare(strict_types=1);

namespace App\Request\Mine;

use App\Request\FormRequest;

class QueryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'game_id' => 'nullable|uuid', 'request_id' => 'nullable|uuid', 'log_id' => 'nullable|integer|min:1',
            'start' => 'nullable|date_format:Y-m-d', 'end' => 'nullable|date_format:Y-m-d|after_or_equal:start',
            'snapshot' => 'nullable|string|max:4096', 'cursor' => 'nullable|string|max:4096',
            'limit' => 'nullable|integer|min:1|max:100', 'order' => 'nullable|in:asc,desc',
            'result' => 'nullable|in:all,win,loss', 'scope' => 'nullable|in:all,mine',
            'type' => 'nullable|in:all,grant,consume', 'keyword' => 'nullable|string|max:100',
            'direction' => 'nullable|in:client_in,client_out,provider_in,provider_out,internal',
            'room_id' => 'nullable|string|max:64', 'hand_number' => 'nullable|string|max:64',
        ];
    }

    public function gameId(): ?string
    {
        return $this->text('game_id');
    }

    public function requestId(): ?string
    {
        return $this->text('request_id');
    }

    public function logId(): ?int
    {
        return isset($this->validated()['log_id']) ? (int) $this->validated()['log_id'] : null;
    }

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
        return $this->requiredText('order', 'desc');
    }

    public function scope(): string
    {
        return $this->requiredText('scope', 'all');
    }

    public function cursor(): ?string
    {
        return $this->text('cursor');
    }

    public function snapshot(): ?string
    {
        return $this->text('snapshot');
    }
}
