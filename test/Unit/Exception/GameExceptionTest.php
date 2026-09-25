<?php

declare(strict_types=1);

namespace Tests\Unit\Exception;

use App\Constants\ErrorCode;
use App\Exception\GameException;
use RuntimeException;
use Tests\TestCase;

use function Hyperf\Translation\__;

final class GameExceptionTest extends TestCase
{
    public function test_exception_keeps_its_creation_site_numeric_code_and_previous_error(): void
    {
        $previous = new RuntimeException('Original failure');
        $line = __LINE__ + 1;
        $error = new GameException(__('messages.game.not_found'), ErrorCode::GAME_NOT_FOUND, previous: $previous);

        self::assertSame(__FILE__, $error->getFile());
        self::assertSame($line, $error->getLine());
        self::assertSame(3001, $error->getCode());
        self::assertSame('未找到牌局。', $error->getMessage());
        self::assertSame($previous, $error->getPrevious());
    }

    public function test_message_is_translated_at_creation_for_an_explicit_locale(): void
    {
        $error = new GameException(__('messages.game.not_found', [], 'en'), ErrorCode::GAME_NOT_FOUND);

        self::assertSame('Game not found.', $error->getMessage());
        self::assertSame('Game not found.', __('messages.game.not_found', [], 'en-US'));
    }
}
