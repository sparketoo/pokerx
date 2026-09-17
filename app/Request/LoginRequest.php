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
            'two_factor_code' => 'nullable|string|digits:6',
        ];
    }

    public function account(): string
    {
        return $this->validate('account');
    }

    public function password(): string
    {
        return $this->validate('password');
    }

    public function twoFactorCode(): ?string
    {
        return $this->validate('two_factor_code');
    }
}
