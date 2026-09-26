<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Model\User;
use App\Vo\Vo;

class GameServerConnectionVo extends Vo
{
    public ?int $lastMessageTimestamp = null;

    public bool $ready = false;

    public function __construct(
        public readonly int $fd,
        public readonly User $user,
        public readonly string $clientId,
    ) {}
}
