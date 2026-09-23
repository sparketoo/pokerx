<?php

declare(strict_types=1);

namespace App\Request\Mine\Games;

use App\Request\QueryRequest;

class DetailRequest extends QueryRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['game_id' => 'required|string|size:16|alpha_num'];
    }

    public function gameId(): string
    {
        return (string) $this->validated()['game_id'];
    }
}
