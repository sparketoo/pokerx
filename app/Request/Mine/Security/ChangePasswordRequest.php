<?php

declare(strict_types=1);

namespace App\Request\Mine\Security;

use App\Request\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => 'required|string', 'new_password' => 'required|string|min:10|max:255',
            'confirmation' => 'required|same:new_password', 'code' => 'nullable|string|digits:6',
        ];
    }

    public function currentPassword(): string
    {
        return $this->requiredText('current_password', '');
    }

    public function newPassword(): string
    {
        return $this->requiredText('new_password', '');
    }

    public function code(): ?string
    {
        return $this->text('code');
    }
}
