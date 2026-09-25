<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Constants\ErrorCode;
use App\Controller\ApiController;
use App\Exception\AuthException;
use App\Model\User;
use App\Request\Mine\Security\CancelTwoFactorRequest;
use App\Request\Mine\Security\ChangePasswordRequest;
use App\Request\Mine\Security\ConfirmTwoFactorRequest;
use App\Service\TotpService;
use Hyperf\HttpServer\Request;
use Illuminate\Encryption\Encrypter;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use Throwable;

use function App\Support\di;
use function Hyperf\Translation\__;

class SecurityController extends ApiController
{
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->user($request)->id);
        if (! password_verify($request->currentPassword(), $user->password)) {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED);
        }
        $this->verifyCode($user, $request->code());
        $user->update(['password' => $request->newPassword()]);
        $this->revoke($user);

        return $this->success(['requires_login' => true]);
    }

    public function createTwoFactor(Request $request, TotpService $totp): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->user($request)->id);
        if ($user->two_factor_secret !== null) {
            throw new AuthException(__('messages.auth.two_factor_already_enabled'), ErrorCode::BUSINESS_ERROR);
        }

        $secret = $totp->secret();
        $state = di(Encrypter::class)->encryptString(json_encode([
            'user_id' => $user->id,
            'secret' => $secret,
            'token_hash' => hash('sha256', $this->bearer($request)),
            'expires_at' => time() + 300,
        ], JSON_THROW_ON_ERROR));

        return $this->success([
            'state' => $state,
            'secret' => $secret,
            'otpauth_uri' => 'otpauth://totp/'.rawurlencode('PokerX:'.$user->account).'?secret='.$secret.'&issuer=PokerX',
        ]);
    }

    public function confirmTwoFactor(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $state = $this->state($request);
        /** @var User $user */
        $user = User::query()->findOrFail($this->user($request)->id);
        if ($user->two_factor_secret !== null) {
            throw new AuthException(__('messages.auth.two_factor_already_enabled'), ErrorCode::BUSINESS_ERROR);
        }
        if (! password_verify($request->currentPassword(), $user->password)) {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED);
        }

        $this->verifyCodeForSecret($state['secret'], $request->code());
        $user->update(['two_factor_secret' => $state['secret']]);
        $this->revoke($user);

        return $this->success(['requires_login' => true]);
    }

    public function cancelTwoFactor(CancelTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = User::query()->findOrFail($this->user($request)->id);
        if ($user->two_factor_secret === null) {
            return $this->success();
        }
        if (! password_verify($request->currentPassword() ?? '', $user->password)) {
            throw new AuthException(__('messages.auth.failed'), ErrorCode::AUTH_FAILED);
        }
        $this->verifyCode($user, $request->code());
        $user->update(['two_factor_secret' => null]);
        $this->revoke($user);

        return $this->success(['requires_login' => true]);
    }

    /**
     * @return array{user_id: int, secret: string, token_hash: string, expires_at: int}
     */
    private function state(ConfirmTwoFactorRequest $request): array
    {
        try {
            $state = json_decode(di(Encrypter::class)->decryptString($request->state()), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new AuthException(__('messages.auth.setup_expired'), ErrorCode::SETUP_EXPIRED);
        }
        if (! is_array($state)
            || ! is_int($state['user_id'] ?? null)
            || ! is_string($state['secret'] ?? null)
            || ! is_string($state['token_hash'] ?? null)
            || ! is_int($state['expires_at'] ?? null)
            || $state['user_id'] !== $this->user($request)->id
            || ! hash_equals($state['token_hash'], hash('sha256', $this->bearer($request)))
            || $state['expires_at'] < time()) {
            throw new AuthException(__('messages.auth.setup_expired'), ErrorCode::SETUP_EXPIRED);
        }

        return [
            'user_id' => $state['user_id'],
            'secret' => $state['secret'],
            'token_hash' => $state['token_hash'],
            'expires_at' => $state['expires_at'],
        ];
    }

    private function verifyCodeForSecret(string $secret, string $code): void
    {
        if ((new TotpService)->counter($secret, $code) === null) {
            throw new AuthException(__('messages.auth.two_factor_invalid'), ErrorCode::TWO_FACTOR_INVALID);
        }
    }
}
