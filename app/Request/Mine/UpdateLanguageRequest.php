<?php

declare(strict_types=1);

namespace App\Request\Mine;

use App\Request\FormRequest;

class UpdateLanguageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['language' => 'required|in:zh-CN,en-US'];
    }

    public function language(): string
    {
        return $this->validate('language');
    }
}
