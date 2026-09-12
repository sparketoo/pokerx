<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property int $user_id
 * @property int $game_id
 * @property int $seq
 * @property string $type
 * @property array<string, mixed> $payload
 */
class Event extends Model
{
    use Concerns\BelongsToUser;

    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = ['payload' => 'array'];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
