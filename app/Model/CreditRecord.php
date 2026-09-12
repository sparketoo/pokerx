<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\CreditRecordTypeEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property int $user_id
 * @property ?int $hand_id
 * @property ?int $solve_id
 * @property CreditRecordTypeEnum $type
 * @property int $amount
 * @property ?int $balance
 * @property ?string $description
 */
class CreditRecord extends Model
{
    use Concerns\BelongsToUser;

    /**
     * @return array<string, string>
     */
    /** @var array<string, string> */
    protected array $casts = [
        'type' => CreditRecordTypeEnum::class,
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'hand_id');
    }

    /** @return BelongsTo<Solve, static> */
    public function solve(): BelongsTo
    {
        return $this->belongsTo(Solve::class);
    }
}
