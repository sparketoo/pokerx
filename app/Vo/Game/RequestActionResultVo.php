<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Exception\AppException;
use App\Exception\GameException;
use App\Vo\Vo;

use function Hyperf\Translation\__;

final class RequestActionResultVo extends Vo
{
    private function __construct(
        public readonly bool $success,
        public readonly ?ActionEnum $action,
        public readonly ?int $amount = null,
        public readonly ?AppException $exception = null,
    ) {}

    public static function success(ActionEnum $action, int|float $amount): self
    {
        if (! is_int($amount) || $amount < 0 || $amount > PHP_INT_MAX
            || (in_array($action, [ActionEnum::FOLD, ActionEnum::CHECK], true) && $amount !== 0)) {
            throw new GameException(__('messages.game.action_amount_invalid'), ErrorCode::BUSINESS_ERROR, ['action' => $action->name, 'amount' => $amount]);
        }

        return new self(true, $action, $amount);
    }

    public static function failure(AppException $exception): self
    {
        return new self(false, null, null, $exception);
    }
}
