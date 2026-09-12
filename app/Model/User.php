<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\UserStatusEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\HasMany;
use Illuminate\Encryption\Encrypter;

use function App\Support\di;

/**
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string $account
 * @property string $nickname
 * @property string $password
 * @property string $language
 * @property UserStatusEnum $status
 * @property ?string $two_factor_secret
 * @property bool $is_vip
 * @property int $credit_balance
 */
class User extends Model
{
    /** @var list<string> */
    protected array $hidden = ['password', 'two_factor_secret'];

    /** @var array<string, string> */
    protected array $casts = [
        'is_vip' => 'boolean',
        'status' => UserStatusEnum::class,
    ];

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = password_get_info($value)['algo'] !== null ? $value : password_hash($value,
            PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function getTwoFactorSecretAttribute(?string $value): ?string
    {
        return $value === null ? null : di(Encrypter::class)->decryptString($value);
    }

    public function setTwoFactorSecretAttribute(?string $value): void
    {
        $this->attributes['two_factor_secret'] = $value === null ? null : di(Encrypter::class)->encryptString($value);
    }

    /** @return HasMany<PersonalAccessToken, static> */
    public function tokens(): HasMany
    {
        $relation = $this->hasMany(PersonalAccessToken::class, 'tokenable_id');
        $relation->whereIn('tokenable_type', [self::class, 'App\\Models\\User']);

        return $relation;
    }
}
