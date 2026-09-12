<?php

declare(strict_types=1);

namespace App\Controller\Mine;

use App\Controller\ApiController;
use App\Exception\AuthException;
use App\Request\Mine\Security\CancelTwoFactorRequest;
use App\Request\Mine\Security\ChangePasswordRequest;
use App\Request\Mine\Security\ConfirmTwoFactorRequest;
use App\Service\TotpService;
use App\Support\DistributedLock;
use Hyperf\HttpServer\Request;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use Psr\Http\Message\ResponseInterface as JsonResponse;

use function App\Support\di;

class SecurityController extends ApiController
{
    public function changePassword(ChangePasswordRequest $r): JsonResponse
    {
        (new DistributedLock('{'.$this->user($r)->id.'}:lock', 30))->block(3, function () use ($r) {
            $u = $this->user($r)->fresh() ?? throw AuthException::authRequired();
            if (! password_verify($r->currentPassword(), $u->password)) {
                throw AuthException::authFailed();
            }
            $this->verifyCode($u, $r->code());
            $this->revoke($u);
            $u->update(['password' => $r->newPassword()]);
        });

        return $this->success(['requires_login' => true]);
    }

    public function createTwoFactor(Request $r, TotpService $totp): JsonResponse
    {
        return (new DistributedLock('{'.$this->user($r)->id.'}:lock', 30))->block(3, function () use ($r, $totp) {
            if (($this->user($r)->fresh() ?? throw AuthException::authRequired())->two_factor_secret) {
                throw AuthException::twoFactorAlreadyEnabled();
            }
            $secret = $totp->secret();
            $id = (string) Str::uuid();
            di(Redis::class)->setex('setup:'.$this->user($r)->id, 300,
                json_encode(['id' => $id, 'secret' => $secret, 'token' => hash('sha256', $this->bearer($r))],
                    JSON_THROW_ON_ERROR));

            return $this->success([
                'setup_id' => $id, 'secret' => $secret,
                'otpauth_uri' => 'otpauth://totp/'.rawurlencode('PokerX:'.$this->user($r)->account).'?secret='.$secret.'&issuer=PokerX',
            ]);
        });
    }

    public function confirmTwoFactor(ConfirmTwoFactorRequest $r): JsonResponse
    {
        (new DistributedLock('{'.$this->user($r)->id.'}:lock', 30))->block(3, function () use ($r) {
            $u = $this->user($r)->fresh() ?? throw AuthException::authRequired();
            $setup = json_decode(di(Redis::class)->get('setup:'.$u->id) ?: 'null', true, 512, JSON_THROW_ON_ERROR);
            if (! $setup || $setup['id'] !== $r->setupId() || $setup['token'] !== hash('sha256', $this->bearer($r))) {
                throw AuthException::setupExpired();
            }
            if ($u->two_factor_secret) {
                throw AuthException::twoFactorAlreadyEnabled();
            }
            if (! password_verify($r->currentPassword(), $u->password)) {
                throw AuthException::authFailed();
            }
            $u->two_factor_secret = $setup['secret'];
            $this->verifyCode($u, $r->code());
            di(Redis::class)->del('setup:'.$u->id);
            $this->revoke($u);
            $u->update(['two_factor_secret' => $setup['secret']]);
        });

        return $this->success(['requires_login' => true]);
    }

    public function cancelTwoFactor(CancelTwoFactorRequest $r): JsonResponse
    {
        (new DistributedLock('{'.$this->user($r)->id.'}:lock', 30))->block(3, function () use ($r) {
            $v = json_decode(di(Redis::class)->get('setup:'.$this->user($r)->id) ?: 'null', true, 512,
                JSON_THROW_ON_ERROR);
            if ($v && $v['id'] === $r->setupId() && $v['token'] === hash('sha256', $this->bearer($r))) {
                di(Redis::class)->del('setup:'.$this->user($r)->id);
            }
        });

        return $this->success();
    }
}
