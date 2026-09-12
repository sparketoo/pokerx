<?php

declare(strict_types=1);

namespace App\Request;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account' => 'required|string|max:64',
            'password' => 'required|string|max:255',
            'code' => 'nullable|string|digits:6',
        ];
    }

    public function account(): string
    {
        return $this->requiredText('account', '');
    }

    public function password(): string
    {
        return $this->requiredText('password', '');
    }

    public function code(): ?string
    {
        return $this->text('code');
    }
}
