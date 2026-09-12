<?php

declare(strict_types=1);

namespace App\Request\Mine;

use App\Request\FormRequest;

class UpdateNicknameRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['nickname' => 'required|string|min:1|max:20'];
    }

    public function nickname(): string
    {
        return $this->requiredText('nickname', '');
    }
}
