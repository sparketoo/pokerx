<?php

namespace App\Vo\Game;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\Vo;

class PokerServerConnectionVo extends Vo
{
    public function __construct(
        public readonly int $fd,
        public readonly User $user,
        public readonly UserToken $token,

    ) {}
}
