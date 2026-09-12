<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AuthException;
use App\Model\User;
use App\Model\UserToken;
use App\Request\LoginRequest;
use Hyperf\HttpServer\Request;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use function App\Support\now;
use function Hyperf\Config\config;

class AuthController extends ApiController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $account = $request->account();
        $password = $request->password();
        $code = $request->code();
        $user = User::query()->where('account', strtolower(trim($account)))->first();
        if (! $user || ! $user->status->isNormal() || ! password_verify($password, $user->password)) {
            throw AuthException::authFailed();
        }

        $this->verifyCode($user, $code);
        $expires = now()->addDays(config('poker.token_days'));
        $secret = bin2hex(random_bytes(20));
        $accessToken = UserToken::query()->create([
            'tokenable_id' => $user->id, 'tokenable_type' => User::class, 'name' => 'chrome',
            'token' => hash('sha256', $secret), 'abilities' => ['*'], 'expires_at' => $expires,
        ]);
        $plainToken = $accessToken->id.'|'.$secret;

        return $this->success([
            'token' => $plainToken,
            'expires_at' => $expires->toIso8601String(),
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->revoke($this->user($request), $this->bearer($request));

        return $this->success();
    }
}
