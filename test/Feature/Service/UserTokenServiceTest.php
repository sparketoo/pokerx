<?php

declare(strict_types=1);

namespace Tests\Feature\Service;

use App\Model\UserToken;
use App\Service\UserTokenService;
use Hyperf\Context\Context;
use Hyperf\DbConnection\Db;
use Tests\Support\DatabaseTestCase;

final class UserTokenServiceTest extends DatabaseTestCase
{
    public function test_create_and_find_token_store_only_the_secret_hash(): void
    {
        $user = $this->user();
        $service = new UserTokenService;

        $created = $service->createToken($user, 'test-client', ['games:read']);
        $found = $service->findToken($created->plainTextToken);

        self::assertInstanceOf(UserToken::class, $found);
        self::assertSame($user->id, $found->user_id);
        self::assertSame('test-client', $found->name);
        self::assertSame(['games:read'], $found->abilities);
        self::assertNotSame($created->plainTextToken, $found->token);
        self::assertNull($service->findToken('malformed'));
        self::assertNull($service->findToken(explode('|', $created->plainTextToken)[0].'|wrong-secret'));
    }

    public function test_validate_token_rejects_expired_or_disabled_user_and_records_valid_use(): void
    {
        $user = $this->user();
        $service = new UserTokenService;
        $valid = $service->createToken($user, 'valid', ['*']);
        $expired = $service->createToken($user, 'expired', ['*'], new \DateTimeImmutable('2020-01-01'));

        self::assertNull($service->validateToken($expired->plainTextToken));
        $used = $service->validateToken($valid->plainTextToken);
        self::assertInstanceOf(UserToken::class, $used);
        self::assertNotNull(UserToken::query()->findOrFail($used->id)->last_used_at);

        $user->update(['status' => 'DISABLED']);
        self::assertNull($service->validateToken($valid->plainTextToken));
    }

    public function test_access_abilities_are_checked_from_context(): void
    {
        $user = $this->user();
        $service = new UserTokenService;
        $token = $service->createToken($user, 'limited', ['games:read'])->accessToken;
        $previous = Context::get(UserToken::class);

        try {
            $service->withAccessToken($token);
            self::assertSame($token, $service->currentAccessToken());
            self::assertTrue($service->tokenCan('games:read'));
            self::assertTrue($service->tokenCant('games:write'));
        } finally {
            Context::set(UserToken::class, $previous);
        }
    }

    public function test_revoke_token_is_user_scoped_and_revoke_all_removes_remaining_tokens(): void
    {
        $user = $this->user();
        $other = $this->user('other@example.test');
        $service = new UserTokenService;
        $first = $service->createToken($user, 'first')->plainTextToken;
        $second = $service->createToken($user, 'second')->plainTextToken;

        self::assertFalse($service->revokeToken($other, $first));
        self::assertNotNull($service->findToken($first));
        self::assertTrue($service->revokeToken($user, $first));
        self::assertNull($service->findToken($first));
        self::assertNotNull($service->findToken($second));
        self::assertSame(1, $service->revokeAllTokens($user));
        self::assertSame(0, Db::table('user_tokens')->count());
    }
}
