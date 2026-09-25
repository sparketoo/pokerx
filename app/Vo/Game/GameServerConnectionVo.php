<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\Vo;

class GameServerConnectionVo extends Vo
{
    public readonly string $clientId;

    public readonly string $locale;

    public ?int $lastMessageTimestamp = null;

    public bool $ready = true;

    public function __construct(
        public readonly int $fd,
        public readonly User $user,
        public readonly UserToken $token,
        ?string $locale = null
    ) {
        $this->locale = $locale ?: $this->user->language;
        $this->clientId = (string) $this->token->id;
    }
}
