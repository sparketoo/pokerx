<?php

declare(strict_types=1);

namespace Tests\Unit\Exception;

use App\Exception\GameException;
use Tests\TestCase;

final class GameExceptionTest extends TestCase
{
    public function test_game_exceptions_have_specific_translations(): void
    {
        self::assertSame('未找到牌局。', GameException::gameUuidNotFound('missing')->getLocaleMessage('zh-CN'));
        self::assertSame('Game not found.', GameException::gameUuidNotFound('missing')->getLocaleMessage('en'));
        self::assertSame('决策服务处理失败。', GameException::providerFailed('secret')->getLocaleMessage('zh-CN'));
    }
}
