<?php

declare(strict_types=1);

namespace App\Request\Mine\GameConfig;

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
            'items.*.value' => ['present', 'nullable', 'string', 'max:32', 'regex:/^(?:0|1|full|0\.[0-9]*[1-9][0-9]*)$/D'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $items = $validator->getData()['items'] ?? [];
        if (! is_array($items)) {
            return;
        }
        foreach ($items as $index => $item) {
            // Null clears a setting; a non-null blank string is not a valid ratio.
            if (is_array($item) && ($item['value'] ?? null) !== null) {
                $validator->addRules(["items.{$index}.value" => ['required']]);
            }
        }
    }
}
