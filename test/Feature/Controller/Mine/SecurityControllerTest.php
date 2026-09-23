<?php

declare(strict_types=1);

namespace Tests\Feature\Controller\Mine;

use App\Controller\Mine\SecurityController;
use App\Exception\AuthException;
use App\Model\User;
use App\Request\Mine\Security\CancelTwoFactorRequest;
use App\Request\Mine\Security\ChangePasswordRequest;
use App\Request\Mine\Security\ConfirmTwoFactorRequest;
use App\Service\TotpService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Request;
use Tests\Support\DatabaseTestCase;

final class SecurityControllerTest extends DatabaseTestCase
{
    public function test_change_password_updates_hash_and_revokes_all_tokens(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->token($user, 'first-secret');
        $this->token($user, 'second-secret');

        $data = $this->call(new SecurityController, 'changePassword', ChangePasswordRequest::class, [
            'current_password' => 'original-password',
            'new_password' => 'replacement-password',
            'confirmation' => 'replacement-password',
        ], 'POST');

        self::assertTrue($data['requires_login']);
        self::assertTrue(password_verify('replacement-password', User::query()->findOrFail($user->id)->password));
        self::assertSame(0, Db::table('user_tokens')->count());
    }

    public function test_change_password_rejects_wrong_current_password_without_revoking_tokens(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->token($user);

        try {
            $this->call(new SecurityController, 'changePassword', ChangePasswordRequest::class, [
                'current_password' => 'wrong-password',
                'new_password' => 'replacement-password',
                'confirmation' => 'replacement-password',
            ], 'POST');
            self::fail('Wrong password must be rejected');
        } catch (AuthException $error) {
            self::assertSame('auth_failed', $error->getErrorCode());
        }

        self::assertTrue(password_verify('original-password', User::query()->findOrFail($user->id)->password));
        self::assertSame(1, Db::table('user_tokens')->count());
    }

    public function test_create_two_factor_returns_a_bearer_bound_state_without_enabling_it(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $token = $this->token($user);

        $data = $this->call(new SecurityController, 'createTwoFactor', Request::class, [], 'POST', [
            'Authorization' => 'Bearer '.$token,
        ], new TotpService);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $data['secret']);
        self::assertStringContainsString('secret='.$data['secret'], $data['otpauth_uri']);
        self::assertNotSame($data['secret'], $data['state']);
        self::assertNull(User::query()->findOrFail($user->id)->two_factor_secret);
    }

    public function test_confirm_two_factor_enables_secret_and_revokes_tokens(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $token = $this->token($user);
        $controller = new SecurityController;
        $headers = ['Authorization' => 'Bearer '.$token];
        $setup = $this->call($controller, 'createTwoFactor', Request::class, [], 'POST', $headers, new TotpService);

        $data = $this->call($controller, 'confirmTwoFactor', ConfirmTwoFactorRequest::class, [
            'state' => $setup['state'],
            'current_password' => 'original-password',
            'code' => (new TotpService)->code($setup['secret']),
        ], 'POST', $headers);

        self::assertTrue($data['requires_login']);
        self::assertSame($setup['secret'], User::query()->findOrFail($user->id)->two_factor_secret);
        self::assertSame(0, Db::table('user_tokens')->count());
    }

    public function test_confirm_two_factor_rejects_state_bound_to_another_token(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $first = $this->token($user, 'first-secret');
        $second = $this->token($user, 'second-secret');
        $controller = new SecurityController;
        $setup = $this->call($controller, 'createTwoFactor', Request::class, [], 'POST', [
            'Authorization' => 'Bearer '.$first,
        ], new TotpService);

        try {
            $this->call($controller, 'confirmTwoFactor', ConfirmTwoFactorRequest::class, [
                'state' => $setup['state'],
                'current_password' => 'original-password',
                'code' => (new TotpService)->code($setup['secret']),
            ], 'POST', ['Authorization' => 'Bearer '.$second]);
            self::fail('The setup state must be bound to its original token');
        } catch (AuthException $error) {
            self::assertSame('setup_expired', $error->getErrorCode());
        }

        self::assertNull(User::query()->findOrFail($user->id)->two_factor_secret);
    }

    public function test_cancel_two_factor_clears_secret_and_revokes_tokens(): void
    {
        $user = $this->user();
        $secret = (new TotpService)->secret();
        $user->update(['two_factor_secret' => $secret]);
        $this->signIn($user);
        $this->token($user);

        $data = $this->call(new SecurityController, 'cancelTwoFactor', CancelTwoFactorRequest::class, [
            'current_password' => 'original-password',
            'code' => (new TotpService)->code($secret),
        ], 'POST');

        self::assertTrue($data['requires_login']);
        self::assertNull(User::query()->findOrFail($user->id)->two_factor_secret);
        self::assertSame(0, Db::table('user_tokens')->count());
    }

    public function test_cancel_two_factor_is_idempotent_when_disabled(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $this->token($user);

        $data = $this->call(new SecurityController, 'cancelTwoFactor', CancelTwoFactorRequest::class, [], 'POST');

        self::assertSame([], $data);
        self::assertSame(1, Db::table('user_tokens')->count());
    }
}
