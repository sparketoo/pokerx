<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Controller\MineController;
use App\Model\User;
use App\Request\Mine\UpdateLanguageRequest;
use App\Request\Mine\UpdateNicknameRequest;
use Hyperf\HttpServer\Request;
use Hyperf\Validation\ValidationException;
use Tests\Support\DatabaseTestCase;

final class MineControllerTest extends DatabaseTestCase
{
    public function test_index_returns_profile_without_secret_fields(): void
    {
        $user = $this->user();
        $this->signIn($user);

        $data = $this->call(new MineController, 'index', Request::class);

        self::assertSame((string) $user->id, $data['id']);
        self::assertSame('Alice', $data['nickname']);
        self::assertSame('zh-CN', $data['language']);
        self::assertFalse($data['two_factor_enabled']);
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('two_factor_secret', $data);
    }

    public function test_update_nickname_persists_the_new_value(): void
    {
        $user = $this->user();
        $this->signIn($user);

        $data = $this->call(new MineController, 'updateNickname', UpdateNicknameRequest::class, [
            'nickname' => 'New Name',
        ], 'POST');

        self::assertSame('New Name', $data['nickname']);
        self::assertSame('New Name', User::query()->findOrFail($user->id)->nickname);
    }

    public function test_update_language_persists_supported_value_and_rejects_other_values(): void
    {
        $user = $this->user();
        $this->signIn($user);

        $data = $this->call(new MineController, 'updateLanguage', UpdateLanguageRequest::class, [
            'language' => 'en-US',
        ], 'POST');
        self::assertSame('en-US', $data['language']);
        self::assertSame('en-US', User::query()->findOrFail($user->id)->language);

        try {
            $this->call(new MineController, 'updateLanguage', UpdateLanguageRequest::class, [
                'language' => 'fr-FR',
            ], 'POST');
            self::fail('Unsupported language must be rejected');
        } catch (ValidationException) {
            self::assertSame('en-US', User::query()->findOrFail($user->id)->language);
        }
    }
}
