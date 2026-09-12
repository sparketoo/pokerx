<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\GameStatusEnum;
use App\Enum\StageEnum;
use App\Vo\Vo;
use Hyperf\Collection\Collection;

final class GameVo extends Vo
{
    /** @param  Collection<int, string>  $board */
    public function __construct(
        public readonly string $id,
        public readonly int $userId,
        public readonly string $roomId,
        public readonly string $handNumber,
        public readonly string $provider,
        public readonly string $createdAt,
        public readonly int $bigBlind,
        public readonly int $ante,
        public readonly ?StageEnum $stage,
        public readonly GameStatusEnum $status,
        public readonly Collection $board,
        public readonly int $lastSeq,
        public readonly int $revision,
        public readonly ?int $invested,
        public readonly ?int $awarded,
        public readonly ?int $profit,
    ) {}
}
