<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Concerns\BelongsToUser;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property ?array<string> $abilities
 * @property ?User $user
 * @property ?Carbon $last_used_at
 * @property string $token
 * @property ?Carbon $expires_at
 */
class UserToken extends Model
{
    use BelongsToUser;

    /** @var array<string, string> */
    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'abilities' => 'array',
    ];

    /** @var list<string> */
    protected array $hidden = ['token'];
}
