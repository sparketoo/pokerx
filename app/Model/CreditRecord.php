<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\CreditRecordTypeEnum;
use Carbon\Carbon;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property int $user_id
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
}
