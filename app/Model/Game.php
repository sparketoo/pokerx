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
 * @property ?float $bet_amount
 * @property ?float $winnings
 * @property ?float $profit
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

    public function getAllPot(): float
    {
        $sum = 0.0;
        $streetContributions = [];
        $currentBet = 0.0;
        foreach ($this->players as $player) {
            $blind = (float) $player->blind_amount;
            $streetContributions[$player->name] = $blind;
            $currentBet = max($currentBet, $blind);
            $sum += $blind;
        }
        foreach ($this->events as $event) {
            if ($event->type === GameEvent::GAME_STAGE && ($event->payload['stage'] ?? null) !== 'preflop') {
                $streetContributions = [];
                $currentBet = 0.0;

                continue;
            }
            if ($event->type !== GameEvent::GAME_PLAY_ACTED) {
                continue;
            }
            $name = (string) ($event->payload['name'] ?? '');
            $amount = (float) ($event->payload['amount'] ?? 0);
            $action = ActionEnum::fromNameOrFail(strtoupper((string) ($event->payload['action'] ?? '')));
            if ($action->isRaise()) {
                $target = $currentBet + $amount;
                $sum += max(0, $target - ($streetContributions[$name] ?? 0));
                $streetContributions[$name] = $target;
                $currentBet = $target;

                continue;
            }
            if ($action->isCall() || $action->isBet()) {
                $sum += max(0, $amount - ($streetContributions[$name] ?? 0));
                $streetContributions[$name] = $amount;
                $currentBet = max($currentBet, $amount);

                continue;
            }
            if ($action->isAllIn()) {
                $sum += $amount;
                $streetContributions[$name] = ($streetContributions[$name] ?? 0) + $amount;
            }
        }

        return $sum;
    }
}
