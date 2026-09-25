<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Constants\ErrorCode;
use App\Enum\ActionEnum;
use App\Exception\GameException;
use App\Vo\Game\RequestActionResultVo;
use Tests\TestCase;

use function Hyperf\Translation\__;

final class RequestActionResultVoTest extends TestCase
{
    public function test_success_keeps_integer_amount_and_failure_keeps_exception(): void
    {
        $success = RequestActionResultVo::success(ActionEnum::CALL, 50);
        $error = new GameException(__('messages.game.action_amount_invalid'), ErrorCode::BUSINESS_ERROR);
        $failure = RequestActionResultVo::failure($error);

        self::assertTrue($success->success);
        self::assertSame(ActionEnum::CALL, $success->action);
        self::assertSame(50, $success->amount);
        self::assertFalse($failure->success);
        self::assertSame($error, $failure->exception);
    }

    public function test_non_integer_negative_and_nonzero_passive_action_amounts_are_rejected(): void
    {
        foreach ([
            [ActionEnum::CALL, 1.5],
            [ActionEnum::BET, -1],
            [ActionEnum::CHECK, 1],
            [ActionEnum::FOLD, 1],
        ] as [$action, $amount]) {
            try {
                RequestActionResultVo::success($action, $amount);
                self::fail('Invalid action amount must be rejected');
            } catch (GameException $error) {
                self::assertSame(1000, $error->getCode());
            }
        }
    }
}
