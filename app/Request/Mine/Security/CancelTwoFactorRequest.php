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
        return ['setup_id' => 'required|uuid'];
    }

    public function setupId(): string
    {
        return $this->requiredText('setup_id', '');
    }
}
