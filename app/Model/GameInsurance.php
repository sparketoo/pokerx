<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $game_id
 * @property string $record_key
 * @property string $type
 * @property ?string $stage
 * @property ?int $pot_id
 * @property ?string $odds
 * @property int $amount
 * @property int $payout
 * @property int $timestamp
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Game $game
 */
class GameInsurance extends Model
{
    /** @var array<string, string> */
    protected array $casts = [
        'pot_id' => 'integer',
        'odds' => 'decimal:2',
        'amount' => 'integer',
        'payout' => 'integer',
        'timestamp' => 'integer',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
