<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\NetworkEnum;
use App\Model\User;
use App\Model\UserGameConfig;
use Hyperf\DbConnection\Db;

final class UserGameConfigService
{
    /** @return array<string, mixed> */
    public function all(User $user, NetworkEnum $network): array
    {
        return UserGameConfig::query()->where('user_id', $user->id)
            ->where('network', $network->name)->orderBy('key')->pluck('value', 'key')->all();
    }

    /** @param list<array{key: string, value: mixed}> $items */
    public function save(User $user, NetworkEnum $network, array $items): void
    {
        Db::transaction(function () use ($user, $network, $items): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            foreach ($items as $item) {
                $key = ['user_id' => $user->id, 'network' => $network->name, 'key' => $item['key']];
                if ($item['value'] === null) {
                    UserGameConfig::query()->where($key)->delete();
                } else {
                    UserGameConfig::query()->updateOrCreate($key, ['value' => $item['value']]);
                }
            }
        });
    }
}
