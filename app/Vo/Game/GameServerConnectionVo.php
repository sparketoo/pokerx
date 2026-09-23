<?php

namespace App\Vo\Game;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\Vo;

class GameServerConnectionVo extends Vo
{
    public readonly string $locale;

    public ?int $lastMessageTimestamp = null;

    public function __construct(
        public readonly int $fd,
        public readonly User $user,
        public readonly UserToken $token,
        ?string $locale = null
    ) {
        $this->locale = $locale ?: $this->user->language;
    }
}
