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
            'items' => ['required', 'array', 'list', 'min:1', 'max:13'],
            'items.*' => ['required', 'array:key,value'],
            'items.*.key' => ['required', 'string', 'regex:/^(?:insurance_(?:outs_[1-8]|default)|auto_bet_(?:check_fold|bet_raise|call_all_in|insurance))$/D', 'distinct:strict'],
            'items.*.value' => ['present', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $items = $validator->getData()['items'] ?? [];
        if (! is_array($items)) {
            return;
        }
        $delayRanges = [];
        for ($min = 0; $min <= 10; $min++) {
            for ($max = $min; $max <= 10; $max++) {
                $delayRanges[] = "{$min}-{$max}";
            }
        }
        foreach ($items as $index => $item) {
            if (! is_array($item) || ($item['value'] ?? null) === null) {
                continue;
            }
            $isDelay = is_string($item['key'] ?? null) && str_starts_with($item['key'], 'auto_bet_');
            $allowed = $isDelay ? $delayRanges : InsuranceService::RATIOS;
            $validator->addRules(["items.{$index}.value" => ['required', 'in:'.implode(',', $allowed)]]);
        }
    }

    /** @return list<array{key: string, value: ?string}> */
    public function items(): array
    {
        return $this->validated()['items'];
    }
}
