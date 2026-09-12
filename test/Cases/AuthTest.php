<?php

declare(strict_types=1);

namespace Tests\Cases;

use App\Model\PersonalAccessToken;
use App\Model\User;
use App\Service\TotpService;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

use function App\Support\di;
use function Tests\run;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        run(fn () => di(Redis::class)->del('rate:login:unknown:'.intdiv(time(), 60)));
    }

    private function account(): User
    {
        return User::query()->create(['account' => 'auth_'.bin2hex(random_bytes(8)), 'nickname' => 'Test', 'password' => 'password1234']);
    }

    public function test_invalid_credentials_and_frozen_account(): void
    {
        run(function (): void {
            $u = $this->account();
            $this->post('/api/auth/login', ['account' => $u->account, 'password' => 'wrong'])->assertJsonPath('code', 'auth_failed');
            $u->update(['status' => 'FROZEN']);
            $this->post('/api/auth/login', ['account' => $u->account, 'password' => 'password1234'])->assertJsonPath('code', 'auth_failed');
        });
    }

    public function test_totp_required_and_replay_rejected(): void
    {
        run(function (): void {
            $u = $this->account();
            $totp = di(TotpService::class);
            $secret = $totp->secret();
            $u->update(['two_factor_secret' => $secret]);
            $body = ['account' => $u->account, 'password' => 'password1234'];
            $this->post('/api/auth/login', $body)->assertJsonPath('code', 'two_factor_required');
            $body['code'] = $totp->code($secret);
            $this->post('/api/auth/login', $body)->assertJsonPath('code', 'success');
            $this->post('/api/auth/login', $body)->assertJsonPath('code', 'two_factor_invalid');
            self::assertNotSame($secret, $u->getRawOriginal('two_factor_secret'));
            self::assertSame($secret, $u->refresh()->two_factor_secret);
        });
    }

    public function test_token_expiry_tampering_and_revocation(): void
    {
        run(function (): void {
            $u = $this->account();
            $response = $this->post('/api/auth/login', ['account' => $u->account, 'password' => 'password1234']);
            $token = $response->json('data.token');
            self::assertIsString($token);
            PersonalAccessToken::query()->where('tokenable_id', $u->id)->update(['tokenable_type' => 'App\\Models\\User']);
            self::assertSame(1, $u->tokens()->count());
            $headers = ['authorization' => 'Bearer '.$token];
            $this->get('/api/mine', [], $headers)->assertJsonPath('data.id', (string) $u->id);
            $this->get('/api/mine', [], ['authorization' => 'Bearer '.$token.'invalid'])->assertJsonPath('code', 'auth_required');
            $this->post('/api/auth/logout', [], $headers)->assertJsonPath('code', 'success');
            $this->get('/api/mine', [], $headers)->assertJsonPath('code', 'auth_required');
            $new = $this->post('/api/auth/login', ['account' => $u->account, 'password' => 'password1234'])->json('data.token');
            self::assertIsString($new);
            PersonalAccessToken::query()->where('tokenable_id', $u->id)->update(['expires_at' => '2000-01-01 00:00:00']);
            $this->get('/api/mine', [], ['authorization' => 'Bearer '.$new])->assertJsonPath('code', 'auth_required');
        });
    }

    public function test_password_change_revokes_every_token(): void
    {
        run(function (): void {
            $u = $this->account();
            $body = ['account' => $u->account, 'password' => 'password1234'];
            $one = $this->post('/api/auth/login', $body)->json('data.token');
            $two = $this->post('/api/auth/login', $body)->json('data.token');
            self::assertIsString($one);
            self::assertIsString($two);
            $this->post('/api/mine/security/change_password', ['current_password' => 'password1234', 'new_password' => 'updatedpass123', 'confirmation' => 'updatedpass123'], ['authorization' => 'Bearer '.$one])->assertJsonPath('code', 'success');
            $this->get('/api/mine', [], ['authorization' => 'Bearer '.$two])->assertJsonPath('code', 'auth_required');
            $this->post('/api/auth/login', $body)->assertJsonPath('code', 'auth_failed');
            $this->post('/api/auth/login', ['account' => $u->account, 'password' => 'updatedpass123'])->assertJsonPath('code', 'success');
        });
    }

    public function test_validation_and_private_route(): void
    {
        run(function (): void {
            $this->post('/api/auth/login', ['account' => 'bad'])->assertJsonPath('code', 'event_invalid');
            $this->post('/api/auth/login', ['account' => ['bad'], 'password' => 'password1234'])->assertJsonPath('code', 'event_invalid');
            $this->get('/api/mine')->assertJsonPath('code', 'auth_required');
        });
    }
}
