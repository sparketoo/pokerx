<?php

declare(strict_types=1);

namespace App\Request\Mine\GameConfig;

use App\Service\InsuranceService;
use Hyperf\Validation\Validator;

class SaveRequest extends IndexRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return parent::rules() + [
            'items' => ['required', 'array', 'list', 'min:1', 'max:9'],
            'items.*' => ['required', 'array:key,value'],
            'items.*.key' => ['required', 'string', 'regex:/^insurance_(?:outs_[1-8]|default)$/D', 'distinct:strict'],
            'items.*.value' => ['present', 'nullable', 'string', 'in:'.implode(',', InsuranceService::RATIOS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $items = $validator->getData()['items'] ?? [];
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['value'] ?? null) !== null) {
                $validator->addRules(["items.{$index}.value" => ['required']]);
            }
        }
    }

    /** @return list<array{key: string, value: ?string}> */
    public function items(): array
    {
        return $this->validated()['items'];
    }
}
