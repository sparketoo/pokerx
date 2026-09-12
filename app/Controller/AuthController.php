<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AuthException;
use App\Gateway\GameRuntime;
use App\Model\CreditRecord;
use App\Model\PersonalAccessToken;
use App\Model\User;
use App\Request\LoginRequest;
use App\Support\DistributedLock;
use Hyperf\HttpServer\Request;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use Throwable;

use function App\Support\di;
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
        if (! $user || ! password_verify($password, $user->password)) {
            throw AuthException::authFailed();
        }

        $result = (new DistributedLock('{'.$user->id.'}:lock', 30))->block(3,
            function () use ($user, $password, $code) {
                $user->refresh();
                if (! $user->status->isNormal() || ! password_verify($password, $user->password)) {
                    throw AuthException::authFailed();
                }
                $this->verifyCode($user, $code);
                di(GameRuntime::class)->initialize($user->id,
                    $user->credit_balance - (int) CreditRecord::query()->where('user_id', $user->id)->where('type',
                        'GRANT')->whereNull('balance')->sum('amount'), $user->is_vip);
                $expires = now()->addDays(config('poker.token_days'));
                $secret = bin2hex(random_bytes(20));
                $accessToken = PersonalAccessToken::query()->create([
                    'tokenable_id' => $user->id, 'tokenable_type' => User::class, 'name' => 'chrome',
                    'token' => hash('sha256', $secret), 'abilities' => ['*'], 'expires_at' => $expires,
                ]);
                $plainToken = $accessToken->id.'|'.$secret;
                try {
                    di(Redis::class)->setex('token:'.hash('sha256', $plainToken), config('poker.token_days') * 86400,
                        json_encode(['user_id' => $user->id, 'expires_at' => $expires->timestamp],
                            JSON_THROW_ON_ERROR));
                    di(Redis::class)->sadd('user_tokens:'.$user->id, hash('sha256', $plainToken));
                } catch (Throwable $e) {
                    $accessToken->delete();
                    throw $e;
                }

                return [
                    'token' => $plainToken, 'expires_at' => $expires->toIso8601String(), 'user' => $this->profile($user),
                ];
            });

        return $this->success($result);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->revoke($this->user($request), $this->bearer($request));

        return $this->success();
    }
}
