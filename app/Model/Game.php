<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property int $user_id
 * @property string $room_number
 * @property int $hand_number
 * @property string $provider
 * @property NetworkEnum $network
 * @property int $big_blind
 * @property int $small_blind
 * @property int $ante
 * @property GameStatusEnum $status
 * @property int $bet_amount
 * @property ?int $winnings
 * @property ?int $profit
 * @property int $pot
 * @property User $user
 * @property Collection<int, GamePlayer> $players
 * @property Collection<int, Event> $events
 */
class Game extends Model
{
    use Concerns\BelongsToUser;

    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = [
        'network' => NetworkEnum::class,
        'status' => GameStatusEnum::class,
        'big_blind' => 'integer',
        'small_blind' => 'integer',
        'ante' => 'integer',
        'bet_amount' => 'integer',
        'winnings' => 'integer',
        'profit' => 'integer',
        'pot' => 'integer',
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

    public function hero(): GamePlayer
    {
        return $this->players->where('is_hero', true)->firstOrFail();
    }
}
