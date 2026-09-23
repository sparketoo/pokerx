<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\GameEventTypeEnum;
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id 游戏事件记录ID
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 * @property int $user_id 用户ID
 * @property int $game_id 游戏ID
 * @property int $timestamp 事件时间戳
 * @property GameEventTypeEnum $type 事件类型
 * @property array<string, mixed> $payload 事件数据
 * @property User $user 所属用户
 * @property Game $game 所属游戏
 */
class GameEvent extends Model
{
    use Concerns\BelongsToUser;

    /** @var array<string, string> */
    protected array $casts = [
        'timestamp' => 'integer',
        'type' => GameEventTypeEnum::class,
        'payload' => 'array',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
