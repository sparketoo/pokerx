<?php

declare(strict_types=1);

namespace App\Model\Concerns;

use App\Model\User;
use Hyperf\Database\Model\Relations\BelongsTo;

trait BelongsToUser
{
    /** @return BelongsTo<User, static> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
