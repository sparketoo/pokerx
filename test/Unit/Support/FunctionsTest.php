<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Tests\TestCase;

use function App\Support\appTimezone;
use function App\Support\appTimezoneOffset;
use function App\Support\now;

final class FunctionsTest extends TestCase
{
    public function test_now_defaults_to_shanghai_time(): void
    {
        self::assertSame('Asia/Shanghai', now()->getTimezone()->getName());
        self::assertSame('+08:00', now()->format('P'));
    }

    public function test_app_timestamp_controls_the_timezone_and_database_offset(): void
    {
        $previous = getenv('APP_TIMESTAMP');
        putenv('APP_TIMESTAMP=Asia/Tokyo');

        try {
            self::assertSame('Asia/Tokyo', appTimezone());
            self::assertSame('+09:00', appTimezoneOffset());
        } finally {
            $previous === false ? putenv('APP_TIMESTAMP') : putenv('APP_TIMESTAMP='.$previous);
        }
    }

    public function test_now_follows_the_php_default_timezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        try {
            self::assertSame('Asia/Tokyo', now()->getTimezone()->getName());
            self::assertSame('+09:00', now()->format('P'));
        } finally {
            date_default_timezone_set($previous);
        }
    }
}
