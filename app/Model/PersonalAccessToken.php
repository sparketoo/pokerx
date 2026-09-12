<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;

/**
 * @property int $id
 * @property int $tokenable_id
 * @property string $token
 * @property ?Carbon $expires_at
 */
class PersonalAccessToken extends Model
{
    /** @var array<string, string> */
    protected array $casts = ['expires_at' => 'datetime', 'abilities' => 'array'];

    /** @var list<string> */
    protected array $hidden = ['token'];
}
