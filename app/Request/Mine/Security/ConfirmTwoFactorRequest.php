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
            'setup_id' => 'required|uuid', 'current_password' => 'required|string', 'code' => 'required|string|digits:6',
        ];
    }

    public function setupId(): string
    {
        return $this->requiredText('setup_id', '');
    }

    public function currentPassword(): string
    {
        return $this->requiredText('current_password', '');
    }

    public function code(): string
    {
        return $this->requiredText('code', '');
    }
}
