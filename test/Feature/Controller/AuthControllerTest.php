<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Controller\AuthController;
use App\Exception\AuthException;
use App\Middleware\Authenticate;
use App\Request\LoginRequest;
use App\Service\TotpService;
use App\Service\UserTokenService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpServer\Request;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Support\DatabaseTestCase;

final class AuthControllerTest extends DatabaseTestCase
{
    public function test_login_normalizes_account_and_persists_a_hashed_token(): void
    {
        $user = $this->user();

        $data = $this->call(new AuthController, 'login', LoginRequest::class, [
            'account' => ' ALICE@EXAMPLE.TEST ', 'password' => 'original-password',
        ], 'POST', [], new UserTokenService);

        self::assertSame((string) $user->id, $data['user']['id']);
        self::assertSame('alice@example.test', $data['user']['account']);
        self::assertArrayNotHasKey('password', $data['user']);
        self::assertMatchesRegularExpression('/^\d+\|[a-f0-9]{64}$/', $data['token']);
        [$id, $secret] = explode('|', $data['token'], 2);
        $stored = Db::table('user_tokens')->where('id', (int) $id)->value('token');
        self::assertNotNull($stored);
        self::assertSame(hash('sha256', $secret), $stored);
        self::assertNotSame($secret, $stored);
        self::assertArrayHasKey('expires_at', $data);
    }

    public function test_login_rejects_wrong_password_and_disabled_account_without_creating_tokens(): void
    {
        $this->user();
        $this->user('disabled@example.test', status: 'DISABLED');

        foreach ([
            ['account' => 'alice@example.test', 'password' => 'wrong'],
            ['account' => 'disabled@example.test', 'password' => 'original-password'],
        ] as $credentials) {
            try {
                $this->call(new AuthController, 'login', LoginRequest::class, $credentials, 'POST', [], new UserTokenService);
                self::fail('Invalid credentials must not create a token');
            } catch (AuthException $error) {
                self::assertSame(2002, $error->getCode());
            }
        }

        self::assertSame(0, Db::table('user_tokens')->count());
    }

    public function test_login_requires_password(): void
    {
        $this->expectException(ValidationException::class);
        $this->call(new AuthController, 'login', LoginRequest::class, ['account' => 'alice@example.test'], 'POST', [], new UserTokenService);
    }

    public function test_login_requires_a_valid_code_when_two_factor_is_enabled(): void
    {
        $user = $this->user();
        $secret = (new TotpService)->secret();
        $user->update(['two_factor_secret' => $secret]);

        try {
            $this->call(new AuthController, 'login', LoginRequest::class, [
                'account' => 'alice@example.test', 'password' => 'original-password',
            ], 'POST', [], new UserTokenService);
            self::fail('A two-factor account must require its code');
        } catch (AuthException $error) {
            self::assertSame(2100, $error->getCode());
        }
        self::assertSame(0, Db::table('user_tokens')->count());

        $data = $this->call(new AuthController, 'login', LoginRequest::class, [
            'account' => 'alice@example.test',
            'password' => 'original-password',
            'two_factor_code' => (new TotpService)->code($secret),
        ], 'POST', [], new UserTokenService);
        self::assertArrayHasKey('token', $data);
    }

    public function test_logout_revokes_only_the_presented_token(): void
    {
        $user = $this->user();
        $this->signIn($user);
        $first = $this->token($user, 'first-secret');
        $second = $this->token($user, 'second-secret');

        $this->call(new AuthController, 'logout', Request::class, [], 'POST', [
            'Authorization' => 'Bearer '.$first,
        ]);

        self::assertNull((new UserTokenService)->findToken($first));
        self::assertNotNull((new UserTokenService)->findToken($second));
        self::assertSame(1, Db::table('user_tokens')->count());
    }

    public function test_expired_token_has_a_distinct_numeric_code(): void
    {
        $user = $this->user();
        $plainToken = $this->token($user);
        [$tokenId] = explode('|', $plainToken, 2);
        Db::table('user_tokens')->where('id', (int) $tokenId)->update(['expires_at' => '2020-01-01 00:00:00']);

        $request = new Psr7Request('GET', '/api/mine/profile', ['Authorization' => 'Bearer '.$plainToken]);
        $handler = new class implements RequestHandlerInterface
        {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Expired token reached the controller');
            }
        };

        try {
            (new Authenticate(new FakeProtoHttpRedis))->process($request, $handler);
            self::fail('Expired token must be rejected');
        } catch (AuthException $error) {
            self::assertSame(2001, $error->getCode());
            self::assertSame('登录已失效。', $error->getMessage());
        }
    }
}
