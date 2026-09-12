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
        return ['language' => 'required|in:zh-CN,en'];
    }

    public function language(): string
    {
        return $this->requiredText('language', '');
    }
}
