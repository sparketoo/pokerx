<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\GameStatusEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * @property Collection<int, Event> $events
 * @property Collection<int, Solve> $solves
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property int $user_id
 * @property string $room_id
 * @property string $hand_number
 * @property string $provider
 * @property int $big_blind
 * @property int $ante
 * @property GameStatusEnum $status
 * @property ?int $invested
 * @property ?int $awarded
 * @property ?int $profit
 */
class Game extends Model
{
    use Concerns\BelongsToUser;

    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = [
        'status' => GameStatusEnum::class,
    ];

    /** @return HasMany<GamePlayer, static> */
    public function players(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /** @return HasMany<Event, static> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @return HasMany<Solve, static> */
    public function solves(): HasMany
    {
        return $this->hasMany(Solve::class);
    }
}
