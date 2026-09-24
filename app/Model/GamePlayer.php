<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsTo;

/**
 * @property int $id 游戏玩家记录ID
 * @property Carbon $created_at 创建时间
 * @property Carbon $updated_at 更新时间
 * @property int $game_id 游戏ID
 * @property int $seat 座位号
 * @property string $name 玩家名称
 * @property bool $is_hero 是否本人
 * @property int $stack 初始总筹码
 * @property int $ante 前注
 * @property ?int $blind 普通盲注：大盲或小盲
 * @property int $post_blind 额外补交的活盲
 * @property int $bet 主动下注金额
 * @property int $total 累计投注：主动下注+前注+普通盲注+补盲
 * @property ?string $cards 已知手牌
 * @property Game $game 所属游戏
 */
class GamePlayer extends Model
{
    /** @var array<string, string> */
    protected array $casts = [
        'seat' => 'integer',
        'cards' => 'string',
        'is_hero' => 'boolean',
        'stack' => 'integer',
        'ante' => 'integer',
        'blind' => 'integer',
        'post_blind' => 'integer',
        'bet' => 'integer',
        'total' => 'integer',
    ];

    /** @return BelongsTo<Game, static> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
