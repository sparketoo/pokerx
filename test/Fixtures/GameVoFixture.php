<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Enum\NetworkEnum;
use App\Vo\Game\GameVo;

final class GameVoFixture
{
    public static function headsUp(string $uuid = 'aaaabbbbcccc0001', int $ante = 10): GameVo
    {
        return new GameVo(1, $uuid, NetworkEnum::WE, 'room-1', 1, 100, 50, $ante, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1);
    }
}
