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
 * @property float $stack 初始筹码
 * @property SeatTypeEnum $seat_type 座位类型
 * @property ?float $blind_amount 盲注
 * @property list<string>|null $cards 已知手牌
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
        'stack' => 'float',
        'blind_amount' => 'float',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
