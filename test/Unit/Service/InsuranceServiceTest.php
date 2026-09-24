<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use Hyperf\Cache\Cache;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class InsuranceServiceTest extends TestCase
{
    public function test_uses_the_default_ratio_when_no_outs_specific_setting_exists(): void
    {
        $service = $this->service(['insurance_default' => InsuranceService::RATIO_2]);

        self::assertSame(9, $service->suggest(GameVoFixture::headsUp(), 2, 299, 16, 0, 18, 9));
    }

    public function test_limits_a_ratio_amount_to_the_quoted_maximum(): void
    {
        $service = $this->service(['insurance_outs_2' => InsuranceService::RATIO_2]);

        self::assertSame(18, $service->suggest(GameVoFixture::headsUp(), 2, 1000, 10, 0, 18, 9));
    }

    public function test_breakeven_ratio_uses_the_quoted_breakeven_amount(): void
    {
        $service = $this->service(['insurance_outs_2' => InsuranceService::RATIO_1]);

        self::assertSame(9, $service->suggest(GameVoFixture::headsUp(), 2, 299, 16, 0, 18, 9));
    }

    public function test_min_uses_the_quoted_minimum_even_when_it_is_zero(): void
    {
        $game = GameVoFixture::headsUp();

        self::assertSame(0, $this->service(['insurance_default' => InsuranceService::RATIO_MIN])
            ->suggest($game, 2, 299, 16, 0, 18, 9));
        self::assertSame(3, $this->service(['insurance_default' => InsuranceService::RATIO_MIN])
            ->suggest($game, 2, 299, 16, 3, 18, 9));
    }

    public function test_unconfigured_strategy_uses_the_quoted_minimum(): void
    {
        $game = GameVoFixture::headsUp();

        self::assertSame(3, $this->service(['other_setting' => 'value'])->suggest($game, 2, 299, 16, 3, 18, 9));
        self::assertSame(0, $this->service(['other_setting' => 'value'])->suggest($game, 2, 299, 16, 0, 18, 9));
    }

    public function test_invalid_quote_returns_null_because_no_valid_decision_exists(): void
    {
        self::assertNull($this->service(['insurance_default' => InsuranceService::RATIO_MIN])
            ->suggest(GameVoFixture::headsUp(), 2, 299, 16, 3, 2, 9));
    }

    /** @param array<string, string> $values */
    private function service(array $values): InsuranceService
    {
        $cache = new class($values) extends Cache
        {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get($key, $default = null): mixed
            {
                return $this->values;
            }
        };

        return new InsuranceService(new UserGameConfigService($cache));
    }
}
