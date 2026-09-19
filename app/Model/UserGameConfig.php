<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\NetworkEnum;

/**
 * @property int $id
 * @property int $user_id
 * @property NetworkEnum $network
 * @property string $key
 * @property mixed $value
 */
class UserGameConfig extends Model
{
    use Concerns\BelongsToUser;

    public bool $timestamps = false;

    protected ?string $table = 'user_game_config';

    /** @var array<string, string> */
    protected array $casts = ['network' => NetworkEnum::class, 'value' => 'json'];
}
