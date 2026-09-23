<?php

declare(strict_types=1);

namespace Tests\Unit\Vo;

use App\Enum\GameStatusEnum;
use App\Vo\Vo;
use Tests\TestCase;

final class VoTest extends TestCase
{
    public function test_json_serialization_uses_snake_case_and_database_enum_names(): void
    {
        $vo = new class extends Vo
        {
            public string $roomNumber = 'room-1';

            public GameStatusEnum $status = GameStatusEnum::OPEN;

            /** @var array<string, mixed> */
            public array $payload = ['states' => [GameStatusEnum::ABORT, GameStatusEnum::OVER]];
        };

        self::assertSame([
            'room_number' => 'room-1',
            'status' => 'OPEN',
            'payload' => ['states' => ['ABORT', 'OVER']],
        ], $vo->jsonSerialize());
    }
}
