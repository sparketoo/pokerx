<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\UserToken;
use Carbon\Carbon;
use Tests\TestCase;

final class ModelTest extends TestCase
{
    public function test_date_time_values_are_formatted_for_the_shanghai_database_session(): void
    {
        $token = new UserToken;
        $token->expires_at = Carbon::parse('2026-09-23T00:30:00+00:00');

        self::assertSame('2026-09-23 08:30:00.000000', $token->getAttributes()['expires_at']);
    }

    public function test_date_time_values_follow_app_timestamp(): void
    {
        $previous = getenv('APP_TIMESTAMP');
        putenv('APP_TIMESTAMP=Asia/Tokyo');

        try {
            $token = new UserToken;
            $token->expires_at = Carbon::parse('2026-09-23T00:30:00+00:00');

            self::assertSame('2026-09-23 09:30:00.000000', $token->getAttributes()['expires_at']);
        } finally {
            $previous === false ? putenv('APP_TIMESTAMP') : putenv('APP_TIMESTAMP='.$previous);
        }
    }
}
