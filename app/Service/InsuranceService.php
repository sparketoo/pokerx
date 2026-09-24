<?php

declare(strict_types=1);

namespace App\Service;

use App\Vo\Game\GameVo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class InsuranceService
{
    // 最小可买金额
    public const string RATIO_MIN = 'MIN';

    // 满池，最大
    public const string RATIO_MAX = 'MAX';

    // 保底
    public const string RATIO_1 = '1';

    // 1/2
    public const string RATIO_2 = '1/2';

    public const string RATIO_3 = '1/3';

    public const string RATIO_5 = '1/5';

    public const string RATIO_8 = '1/8';

    public const array RATIOS = [
        self::RATIO_MIN,
        self::RATIO_MAX,
        self::RATIO_1,
        self::RATIO_2,
        self::RATIO_3,
        self::RATIO_5,
        self::RATIO_8,
    ];

    public function __construct(
        private readonly UserGameConfigService $configs,
    ) {}

    public function suggest(
        GameVo $game,
        int $outsCount,
        int $pot,
        float $odds,
        int $min,
        int $max,
        int $breakeven
    ): ?int {
        if ($pot < 0 || ! is_finite($odds) || $odds <= 0 || $min < 0 || $max < $min || $breakeven < 0) {
            return null;
        }

        $configs = $this->configs->all($game->userId, $game->network);
        $ratio = ($outsCount >= 1 && $outsCount <= 8 ? $configs['insurance_outs_'.$outsCount] ?? null : null)
            ?? $configs['insurance_default'] ?? null;
        if ($ratio === self::RATIO_MIN) {
            return $min;
        }

        if ($ratio === self::RATIO_MAX) {
            return $max;
        }

        if ($ratio === self::RATIO_1) {
            return min($max, max($min, $breakeven));
        }

        $denominator = match ($ratio) {
            self::RATIO_2 => 2,
            self::RATIO_3 => 3,
            self::RATIO_5 => 5,
            self::RATIO_8 => 8,
            default => null,
        };
        if ($denominator === null) {
            return $min;
        }

        $amount = BigDecimal::of($pot)
            ->dividedBy(BigDecimal::of($denominator)->multipliedBy((string) $odds), 0, RoundingMode::Floor);

        return $amount->isGreaterThan($max) ? $max : max($amount->toInt(), $min);
    }
}
