<?php

declare(strict_types=1);

namespace App\Request;

abstract class FormRequest extends \Hyperf\Validation\Request\FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function validate(string $key, mixed $default = null): mixed
    {
        return $this->validated()[$key] ?? $default;
    }
}
