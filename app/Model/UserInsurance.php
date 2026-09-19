<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\StageEnum;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $game_id
 * @property int $pot_id
 * @property StageEnum $stage
 * @property list<string> $outs
 * @property int $remaining_card_num
 * @property string $odds
 * @property int $breakeven
 * @property int $min_insurance
 * @property int $max_insurance
 * @property int $pot
 * @property int $amount
 */
class UserInsurance extends Model
{
    use Concerns\BelongsToUser;

    /** @var array<string, string> */
    protected array $casts = [
        'pot_id' => 'integer',
        'stage' => StageEnum::class,
        'outs' => 'array',
        'remaining_card_num' => 'integer',
        // Hyperf's decimal cast goes through float; preserve the database decimal string.
        'odds' => 'string',
        'breakeven' => 'integer',
        'min_insurance' => 'integer',
        'max_insurance' => 'integer',
        'pot' => 'integer',
        'amount' => 'integer',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
