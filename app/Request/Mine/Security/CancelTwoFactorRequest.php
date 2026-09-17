<?php

declare(strict_types=1);

namespace App\Request\Mine\Security;

use App\Request\FormRequest;

class CancelTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => 'nullable|string',
            'code' => 'nullable|string|digits:6',
        ];
    }

    public function currentPassword(): ?string
    {
        return $this->validate('current_password');
    }

    public function code(): ?string
    {
        return $this->validate('code');
    }
}
