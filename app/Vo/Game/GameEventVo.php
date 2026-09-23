<?php

namespace App\Vo\Game;

use App\Enum\GameEventTypeEnum;
use App\Vo\Vo;

class GameEventVo extends Vo
{
    /**
     * 所属游戏
     */
    public readonly GameVo $game;

    /**
     * 事件顺序
     */
    public readonly int $timestamp;

    /**
     * 所属玩家
     */
    public readonly ?GamePlayerVo $player;

    /**
     * 事件类型
     */
    public readonly GameEventTypeEnum $type;

    /**
     * 事件载荷
     *
     * @var array<string, mixed>
     */
    public readonly array $payload;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        GameVo $game,
        GameEventTypeEnum $type,
        array $payload,
        int $timestamp,
        ?GamePlayerVo $player,
    ) {
        $this->game = $game;
        $this->timestamp = $timestamp;
        $this->type = $type;
        $this->payload = $payload;
        $this->player = $player;
    }
}
