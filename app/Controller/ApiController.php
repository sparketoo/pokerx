<?php

declare(strict_types=1);

namespace App\Controller;

use App\Constants\ErrorCode;
use App\Exception\AuthException;
use App\Model\User;
use App\Model\UserToken;
use App\Service\TotpService;
use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Model\Model;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpServer\Request;
use Psr\Http\Message\ResponseInterface as JsonResponse;
use UnitEnum;

use function App\Support\di;
use function Hyperf\Translation\__;

abstract class ApiController extends AbstractController
{
    protected function user(Request $request): User
    {
        $user = Context::get(User::class);
        if (! $user instanceof User) {
            throw new AuthException(__('messages.auth.required'), ErrorCode::AUTH_REQUIRED);
        }

        return $user;
    }

    protected function bearer(Request $request): string
    {
        return preg_match('/^Bearer (.+)$/D', $request->header('authorization') ?? '',
            $matches) ? $matches[1] : throw new AuthException(__('messages.auth.required'), ErrorCode::AUTH_REQUIRED);
    }

    protected function success(mixed $data = null): JsonResponse
    {
        return di(ResponseInterface::class)->json([
            'code' => ErrorCode::SUCCESS,
            'message' => 'ok',
            'data' => $this->normalize($data),
        ]);
    }

    private function normalize(mixed $v): mixed
    {
        if ($v instanceof UnitEnum) {
            return $v->name;
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
            throw new AuthException(__('messages.auth.two_factor_required'), ErrorCode::TWO_FACTOR_REQUIRED);
        }
        if (di(TotpService::class)->counter($user->two_factor_secret, $code) === null) {
            throw new AuthException(__('messages.auth.two_factor_invalid'), ErrorCode::TWO_FACTOR_INVALID);
        }
    }

    protected function revoke(User $user, ?string $plain = null): void
    {
        if ($plain !== null) {
            UserToken::query()->where('id', (int) explode('|', $plain, 2)[0])->where('user_id',
                $user->id)->delete();

            return;
        }
        $user->tokens()->delete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(User $u): array
    {
        return [
            'id' => (string) $u->id,
            'account' => $u->account,
            'nickname' => $u->nickname,
            'language' => $u->language,
            'two_factor_enabled' => $u->two_factor_secret !== null,
        ];
    }
}
