<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\ActionEnum;
use App\Exception\AppException;
use App\Vo\Vo;
use InvalidArgumentException;

final class RequestActionResultVo extends Vo
{
    private function __construct(
        public readonly bool $success,
        public readonly ?ActionEnum $action,
        public readonly ?int $amount,
        public readonly ?string $error_code,
        public readonly ?string $reason
    ) {}

    public static function success(ActionEnum $action, int|float $amount): self
    {
        // Reject fractional/unsafe upstream advice rather than silently truncating it.
        if (! is_int($amount) || $amount < 0 || $amount > 9007199254740991
            || (in_array($action, [ActionEnum::FOLD, ActionEnum::CHECK], true) && $amount !== 0)) {
            throw new InvalidArgumentException('Invalid action amount');
        }

        return new self(true, $action, $amount, null, null);
    }

    public static function failure(AppException $exception, ?string $reason = null): self
    {
        return new self(false, null, null, $exception->getErrorCode(),
            $reason ?? $exception->getMessage());
    }
}
