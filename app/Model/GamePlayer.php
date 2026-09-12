<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\SeatTypeEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property int $game_id
 * @property int $seat
 * @property string $name
 * @property bool $is_hero
 * @property int $stack
 * @property SeatTypeEnum $seat_type
 * @property ?int $blind_amount
 * @property int $ante
 * @property list<string>|null $cards
 */
class GamePlayer extends Model
{
    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = [
        'seat_type' => SeatTypeEnum::class,
        'cards' => 'array',
        'is_hero' => 'boolean',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
