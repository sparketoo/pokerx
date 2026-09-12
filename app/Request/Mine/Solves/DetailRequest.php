<?php

declare(strict_types=1);

namespace App\Request\Mine\Solves;

use App\Request\Mine\QueryRequest;

class DetailRequest extends QueryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace(parent::rules(), ['game_id' => 'required|uuid', 'request_id' => 'required|uuid']);
    }
}
