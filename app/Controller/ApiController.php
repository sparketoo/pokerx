<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\AuthException;
use App\Model\PersonalAccessToken;
use App\Model\User;
use App\Service\TotpService;
use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Model\Model;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpServer\Request;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use UnitEnum;

use function App\Support\di;
use function Hyperf\Config\config;

abstract class ApiController extends AbstractController
{
    protected function user(Request $request): User
    {
        $user = Context::get(User::class);
        if (! $user instanceof User) {
            throw AuthException::authRequired();
        }

        return $user;
    }

    protected function bearer(Request $request): string
    {
        return preg_match('/^Bearer (.+)$/D', $request->header('authorization') ?? '',
            $matches) ? $matches[1] : throw AuthException::authRequired();
    }

    protected function success(mixed $data = null): JsonResponse
    {
        return di(ResponseInterface::class)->json([
            'code' => 'success', 'message' => 'ok', 'data' => $this->normalize($data),
        ]);
    }

    private function normalize(mixed $v): mixed
    {
        if ($v instanceof UnitEnum) {
            return strtolower($v->name);
        }
        if ($v instanceof Model) {
            $out = [];
            foreach (array_keys($v->getAttributes()) as $k) {
                if (! in_array($k, $v->getHidden(), true)) {
                    $value = $v->getAttribute($k);
                    $out[$k] = is_int($value) && ($k === 'id' || str_ends_with($k,
                        '_id')) ? (string) $value : $this->normalize($value);
                }
            }
            foreach ($v->getRelations() as $k => $val) {
                $out[$k] = $this->normalize($val);
            }

            return $out;
        }
        if ($v instanceof Collection) {
            $v = $v->all();
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format(DATE_ATOM);
        }
        if (is_array($v)) {
            foreach ($v as $k => $val) {
                $v[$k] = $this->normalize($val);
            }
        }

        return $v;
    }

    protected function verifyCode(User $user, ?string $code): void
    {
        if (! $user->two_factor_secret) {
            return;
        }
        if (! $code) {
            throw AuthException::twoFactorRequired();
        }
        $counter = di(TotpService::class)->counter($user->two_factor_secret, $code);
        $key = 'totp:'.$user->id.':'.hash('sha256', $user->two_factor_secret).':'.$counter;
        if ($counter === null || ! di(Redis::class)->set($key, '1', ['nx', 'ex' => 120])) {
            throw AuthException::twoFactorInvalid();
        }
    }

    protected function revoke(User $user, ?string $plain = null): void
    {
        $r = di(Redis::class);
        if ($plain !== null) {
            $hash = hash('sha256', $plain);
            $r->setex('revoked:'.$hash, config('poker.token_days') * 86400, '1');
            $r->del('token:'.$hash);
            di(Request::class);
            PersonalAccessToken::query()->where('id', (int) explode('|', $plain, 2)[0])->where('tokenable_id',
                $user->id)->delete();

            return;
        }
        $r->set('denied:'.$user->id, '1');
        $user->tokens()->delete();
        foreach (di(Redis::class)->smembers('user_tokens:'.$user->id) as $hash) {
            di(Redis::class)->del('token:'.$hash);
        }
        di(Redis::class)->del('user_tokens:'.$user->id);
        $r->del('denied:'.$user->id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(User $u): array
    {
        return [
            'is_vip' => $u->is_vip, 'id' => (string) $u->id, 'account' => $u->account, 'nickname' => $u->nickname,
            'language' => $u->language, 'two_factor_enabled' => $u->two_factor_secret !== null,
        ];
    }
}
