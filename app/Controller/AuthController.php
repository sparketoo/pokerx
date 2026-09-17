<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AuthException;
use App\Model\User;
use App\Request\LoginRequest;
use App\Service\UserTokenService;
use Hyperf\HttpServer\Request;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function App\Support\now;
use function Hyperf\Config\config;

class AuthController extends ApiController
{
    public function login(LoginRequest $request, UserTokenService $tokens): JsonResponse
    {
        $account = $request->account();
        $password = $request->password();
        $twoFactorCode = $request->twoFactorCode();
        $user = User::query()->where('account', strtolower(trim($account)))->first();
        if (! $user || ! $user->status->isNormal() || ! password_verify($password, $user->password)) {
            throw AuthException::authFailed();
        }

        $this->verifyCode($user, $twoFactorCode);
        $expires = now()->addDays(config('poker.token_days'));
        $plainToken = $tokens->createToken($user, 'chrome', ['*'], $expires)->plainTextToken;

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
