<?php

declare(strict_types=1);

namespace App\Model;

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\GameStatusEnum;
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
 * @property string $game_type
 * @property float $big_blind
 * @property float $small_blind
 * @property float $ante
 * @property GameStatusEnum $status
 * @property float $bet_amount
 * @property ?float $winnings
 * @property ?float $profit
 * @property float $pot
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
        'status' => GameStatusEnum::class,
        'big_blind' => 'float',
        'small_blind' => 'float',
        'ante' => 'float',
        'bet_amount' => 'float',
        'winnings' => 'float',
        'profit' => 'float',
        'pot' => 'float',
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
