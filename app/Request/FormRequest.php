<?php

declare(strict_types=1);

namespace App\Request;

use App\Exception\GatewayException;

abstract class FormRequest extends \Hyperf\Validation\Request\FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function requiredText(string $key, ?string $default = null): string
    {
        return $this->text($key, $default) ?? throw GatewayException::eventInvalid();
    }

    protected function text(string $key, ?string $default = null): ?string
    {
        return $this->validated()[$key] ?? $default;
    }
}
