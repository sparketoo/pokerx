<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\TotpService;
use InvalidArgumentException;
use Tests\TestCase;

final class TotpServiceTest extends TestCase
{
    public function test_code_matches_a_known_sha1_totp_vector(): void
    {
        $service = new TotpService;

        self::assertSame('287082', $service->code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59));
    }

    public function test_generated_secret_is_compatible_with_code_and_counter(): void
    {
        $service = new TotpService;
        $secret = $service->secret();
        $code = $service->code($secret);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        self::assertSame(intdiv(time(), 30), $service->counter($secret, $code));
        self::assertNull($service->counter($secret, $service->code($secret, time() - 90)));
    }

    public function test_invalid_secret_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TotpService)->code('NOT-BASE32');
    }
}
