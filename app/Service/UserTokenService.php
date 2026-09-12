<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use App\Model\UserToken;
use App\Vo\User\NewUserToken;
use DateTimeInterface;
use Hyperf\Context\Context;

use function App\Support\now;

final class UserTokenService
{
    /** @param  list<string>  $abilities */
    public function createToken(
        User $user,
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null
    ): NewUserToken {
        $secret = $this->generateTokenString();
        /** @var UserToken $token */
        $token = $user->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewUserToken($token, $token->id.'|'.$secret);
    }

    public function generateTokenString(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Find by secret; expiry and user status are checked by validateToken(). */
    public function findToken(string $plainTextToken): ?UserToken
    {
        if (! preg_match('/^([1-9][0-9]*)\|([^|\s]+)$/D', $plainTextToken, $parts)) {
            return null;
        }
        $token = UserToken::query()->find($parts[1]);

        return $token && hash_equals($token->token, hash('sha256', $parts[2])) ? $token : null;
    }

    /** Validate a login token and record successful use. Does not bind request context. */
    public function validateToken(string $plainTextToken): ?UserToken
    {
        $token = $this->findToken($plainTextToken);
        if (! $token || ($token->expires_at !== null && $token->expires_at->lessThanOrEqualTo(now()))) {
            return null;
        }
        if (! $token->user || ! $token->user->status->isNormal()) {
            return null;
        }
        $token->forceFill(['last_used_at' => now(date_default_timezone_get())])->save();

        return $token;
    }

    public function currentAccessToken(): ?UserToken
    {
        return Context::get(UserToken::class);
    }

    public function withAccessToken(?UserToken $token): self
    {
        Context::set(UserToken::class, $token);

        return $this;
    }

    public function tokenCan(string $ability): bool
    {
        $abilities = $this->currentAccessToken()->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function tokenCant(string $ability): bool
    {
        return ! $this->tokenCan($ability);
    }

    public function revokeToken(User $user, string $plainTextToken): bool
    {
        $token = $this->findToken($plainTextToken);
        if (! $token || $token->user_id !== $user->id) {
            return false;
        }

        return (bool) $user->tokens()->where('id', $token->id)->delete();
    }

    public function revokeAllTokens(User $user): int
    {
        return $user->tokens()->delete();
    }
}
