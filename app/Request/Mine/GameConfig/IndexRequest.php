<?php

declare(strict_types=1);

namespace App\Request\Mine\GameConfig;

use App\Enum\NetworkEnum;
use App\Request\QueryRequest;

class IndexRequest extends QueryRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['network' => ['required', 'in:ok,we,wpk']];
    }

    public function network(): NetworkEnum
    {
        return NetworkEnum::fromNameOrFail(strtoupper($this->validated()['network']));
    }
}
