<?php

declare(strict_types=1);

namespace App\Vo\User;

use App\Model\UserToken;
use App\Vo\Vo;

final class NewUserToken extends Vo
{
    public function __construct(
        public readonly UserToken $accessToken,
        public readonly string $plainTextToken,
    ) {}
}
