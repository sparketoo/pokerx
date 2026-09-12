<?php

declare(strict_types=1);

namespace App\Request\Mine\Games;

use App\Request\Mine\QueryRequest;

class EventsRequest extends QueryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace(parent::rules(), ['game_id' => 'required|uuid']);
    }
}
