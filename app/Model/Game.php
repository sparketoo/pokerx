<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * @property int $id 游戏ID
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 * @property string $uuid 游戏UUID
 * @property int $user_id 用户ID
 * @property string $game_key 平台牌局标识
 * @property string $provider 服务商
 * @property int $players 玩家数量
 * @property NetworkEnum $network 扑克网络
 * @property int $big_blind 大盲注
 * @property int $small_blind 小盲注
 * @property int $ante 前注
 * @property GameStatusEnum $status 游戏状态
 * @property int $total 本人总下注金额：包含前注+盲注+主动下注
 * @property int $winnings 本人赢得奖金
 * @property int $profit 本人游戏收益：winnings-total
 * @property int $pot 底池：主池+边池
 * @property User $user 所属用户
 * @property Collection<int, GamePlayer> $gamePlayers 游戏玩家列表
 * @property Collection<int, GameEvent> $events 游戏事件列表
 */
class Game extends Model
{
    use Concerns\BelongsToUser;

    /** @var array<string, string> */
    protected array $casts = [
        'network' => NetworkEnum::class,
        'status' => GameStatusEnum::class,
        'players' => 'integer',
        'big_blind' => 'integer',
        'small_blind' => 'integer',
        'ante' => 'integer',
        'total' => 'integer',
        'winnings' => 'integer',
        'profit' => 'integer',
        'pot' => 'integer',
    ];

    /** @return HasMany<GamePlayer, static> */
    public function gamePlayers(): HasMany
    {
        return $this->hasMany(GamePlayer::class);
    }

    /** @return HasMany<GameEvent, static> */
    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class);
    }

    public function hero(): GamePlayer
    {
        return $this->gamePlayers->where('is_hero', true)->firstOrFail();
    }
}
