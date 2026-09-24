<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\NetworkEnum;
use App\Model\UserGameConfig;
use Hyperf\Cache\Cache;
use Hyperf\DbConnection\Db;

final class UserGameConfigService
{
    public function __construct(protected readonly Cache $cache) {}

    /** @return array<string, mixed> */
    public function all(int $userId, NetworkEnum $network): array
    {
        $cache = $this->cache->get('user:game:config:'.$userId.':'.$network->name);
        if (empty($cache)) {
            $cache = UserGameConfig::query()->where('user_id', $userId)
                ->where('network', $network->name)
                ->orderBy('key')
                ->pluck('value', 'key')
                ->all();
            $this->cache->set('user:game:config:'.$userId.':'.$network->name, $cache, 86400);
        }

        return $cache;
    }

    /** @param  list<array{key: string, value: ?string}>  $items */
    public function save(int $userId, NetworkEnum $network, array $items): void
    {
        Db::transaction(function () use ($userId, $network, $items): void {
            foreach ($items as $item) {
                $key = ['user_id' => $userId, 'network' => $network->name, 'key' => $item['key']];
                if ($item['value'] === null) {
                    UserGameConfig::query()->where($key)->delete();
                } else {
                    UserGameConfig::query()->updateOrCreate($key, ['value' => $item['value']]);
                }
            }
        });
        $this->cache->delete('user:game:config:'.$userId.':'.$network->name);
    }
}
