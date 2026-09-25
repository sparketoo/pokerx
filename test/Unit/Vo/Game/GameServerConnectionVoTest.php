<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\Game\GameServerConnectionVo;
use Tests\TestCase;

final class GameServerConnectionVoTest extends TestCase
{
    public function test_connection_uses_user_language_unless_a_locale_is_provided(): void
    {
        $user = new User;
        $user->language = 'zh-CN';
        $token = new UserToken;

        self::assertSame('zh-CN', (new GameServerConnectionVo(1, $user, $token))->locale);
        self::assertSame('en', (new GameServerConnectionVo(2, $user, $token, 'en'))->locale);
    }

    public function test_connection_uses_authenticated_token_id_as_stable_client_id(): void
    {
        $user = new User;
        $user->language = 'zh-CN';
        $token = new UserToken;
        $token->id = 42;

        self::assertSame('42', (new GameServerConnectionVo(7, $user, $token))->clientId);
    }
}
