<?php

declare(strict_types=1);

namespace App\Request\Mine\Security;

use App\Request\FormRequest;

class ConfirmTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'state' => 'required|string|max:4096',
            'current_password' => 'required|string',
            'code' => 'required|string|digits:6',
        ];
    }

    public function state(): string
    {
        return $this->validate('state');
    }

    public function currentPassword(): string
    {
        return $this->validate('current_password');
    }

    public function code(): string
    {
        return $this->validate('code');
    }
}
