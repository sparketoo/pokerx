<?php

declare(strict_types=1);

namespace App\Request\Mine\Logs;

use App\Request\Mine\QueryRequest;

class DetailRequest extends QueryRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace(parent::rules(), ['log_id' => 'required|integer|min:1']);
    }
}
