<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\LogDirectionEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

use function Hyperf\Config\config;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $uuid
 * @property ?int $user_id
 * @property ?int $game_id
 * @property LogDirectionEnum $direction
 * @property string $type
 * @property array<string, mixed> $payload
 * @property ?string $error_code
 */
class Log extends Model
{
    use Concerns\BelongsToUser;

    /** @var array<string, string> */
    protected array $casts = [
        'direction' => LogDirectionEnum::class,
        'payload' => 'array',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (preg_match('/token|password|secret|otpauth|authorization|^code$/i', (string) $key)) {
                $value = '[redacted]';
            } elseif (is_string($value) && config('poker.proto.token') && str_contains($value,
                config('poker.proto.token'))) {
                $value = str_replace(config('poker.proto.token'), '[redacted]', $value);
            } elseif (is_array($value)) {
                $value = self::redact($value);
            }
        }

        return $data;
    }
}
