<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Enum\NetworkEnum;
use App\Model\UserGameConfig;
use Tests\TestCase;

final class UserGameConfigTest extends TestCase
{
    public function test_network_and_json_value_are_cast_from_stored_values(): void
    {
        self::assertTrue(class_exists(UserGameConfig::class));

        $config = new UserGameConfig;
        $config->setRawAttributes([
            'network' => 'WPK_CLUB',
            'value' => '"0.125"',
        ], true);

        self::assertSame(NetworkEnum::WPK_CLUB, $config->network);
        self::assertSame('0.125', $config->value);
    }
}
