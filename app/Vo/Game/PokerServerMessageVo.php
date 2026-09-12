<?php

namespace App\Vo\Game;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\Vo;

class PokerServerMessageVo extends Vo
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $fd,
        public readonly User $user,
        public readonly UserToken $token,
        public readonly string $id,
        public readonly string $type,
        public readonly array $payload,
        public readonly int $timestamp,
    ) {}
}
