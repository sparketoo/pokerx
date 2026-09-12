<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\ActionEnum;
use App\Enum\SolveStatusEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $user_id
 * @property int $game_id
 * @property int $event_id
 * @property SolveStatusEnum $status
 * @property ?ActionEnum $action
 * @property ?int $amount
 * @property int $cost
 * @property ?string $error_code
 * @property ?string $reason
 */
class Solve extends Model
{
    use Concerns\BelongsToUser;

    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = [
        'status' => SolveStatusEnum::class,
        'action' => ActionEnum::class,
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /** @return BelongsTo<Event, static> */
    /** @return BelongsTo<Event, static> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
