<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Model\Event;
use App\Model\Game;
use App\Model\User;
use App\Service\CreditService;
use App\Service\TotpService;
use Hyperf\Stringable\Str;
use Tests\HttpTestCase;
use Tests\Support\TestData;

final class ApiTest extends HttpTestCase
{
    public function test_health_and_login(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user([
            'account' => 'api_login_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16),
        ]));

        self::assertSame(['status' => 'ok'], $this->apiRequest(fn () => $this->get('/api/health'))['data']);
        $login = $this->apiRequest(fn () => $this->json('/api/auth/login', [
            'account' => $user->account, 'password' => 'CorrectHorseBatteryStaple',
        ]));

        self::assertSame('success', $login['code']);
        self::assertSame((string) $user->id, $login['data']['user']['id']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*\|[a-f0-9]{64}$/', $login['data']['token']);

        $twoFactorUser = TestData::inCoroutine(fn (): User => TestData::user([
            'account' => 'api_2fa_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16),
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]));
        $legacyCodeLogin = $this->apiRequest(fn () => $this->json('/api/auth/login', [
            'account' => $twoFactorUser->account,
            'password' => 'CorrectHorseBatteryStaple',
            'code' => (new TotpService)->code('JBSWY3DPEHPK3PXP'),
        ]));
        $twoFactorLogin = $this->apiRequest(fn () => $this->json('/api/auth/login', [
            'account' => $twoFactorUser->account,
            'password' => 'CorrectHorseBatteryStaple',
            'two_factor_code' => (new TotpService)->code('JBSWY3DPEHPK3PXP'),
        ]));

        self::assertSame('two_factor_required', $legacyCodeLogin['code']);
        self::assertSame('success', $twoFactorLogin['code']);
    }

    public function test_profile_update_and_logout(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor(TestData::inCoroutine(fn (): string => TestData::token($user)));

        self::assertSame((string) $user->id, $this->apiRequest(fn () => $this->get('/api/mine', [], $headers))['data']['id']);
        self::assertSame(['nickname' => 'Renamed'], $this->apiRequest(fn () => $this->json('/api/mine/update_nickname', ['nickname' => 'Renamed'], $headers))['data']);
        self::assertSame(['language' => 'en-US'], $this->apiRequest(fn () => $this->json('/api/mine/update_language', ['language' => 'en-US'], $headers))['data']);
        self::assertSame('success', $this->apiRequest(fn () => $this->json('/api/auth/logout', [], $headers))['code']);
        self::assertSame('auth_required', $this->apiRequest(fn () => $this->get('/api/mine', [], $headers))['code']);
    }

    public function test_credit_game_event_and_statistics_endpoints(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $token = TestData::inCoroutine(fn (): string => TestData::token($user));
        $game = TestData::inCoroutine(function () use ($user): Game {
            (new CreditService)->recharge($user, 50, 'api test credit');
            $game = TestData::game($user, ['status' => GameStatusEnum::CLOSED, 'bet_amount' => 150, 'winnings' => 250, 'profit' => 100]);
            TestData::players($game);
            TestData::event($game, GameEvent::HAND_START, ['room_number' => $game->room_number]);
            TestData::event($game, GameEvent::PLAYER_ACTED, ['name' => 'Hero', 'action' => 'call', 'amount' => 100]);
            $handOver = TestData::event($game, GameEvent::HAND_OVER, ['winnings' => 250]);
            $handOver->update(['created_at' => '2026-09-17 10:42:00.000000']);

            return $game;
        });
        $headers = $this->headersFor($token);
        $credit = $this->apiRequest(fn () => $this->get('/api/mine/credit', [], $headers));
        $records = $this->apiRequest(fn () => $this->get('/api/mine/credit/record', ['limit' => 5], $headers));
        $games = $this->apiRequest(fn () => $this->get('/api/mine/games', ['limit' => 5], $headers));
        $detail = $this->apiRequest(fn () => $this->get('/api/mine/games/detail', ['game_id' => $game->uuid], $headers));
        $events = $this->apiRequest(fn () => $this->get('/api/mine/games/events', ['game_id' => $game->uuid, 'scope' => 'mine'], $headers));
        $firstEventPage = $this->apiRequest(fn () => $this->get('/api/mine/games/events', [
            'game_id' => $game->uuid,
            'limit' => 1,
        ], $headers));
        $secondEventPage = $this->apiRequest(fn () => $this->get('/api/mine/games/events', [
            'game_id' => $game->uuid,
            'limit' => 1,
            'cursor' => $firstEventPage['data']['next_cursor'],
        ], $headers));
        $summary = $this->apiRequest(fn () => $this->get('/api/mine/stats/summary', [], $headers));
        $trend = $this->apiRequest(fn () => $this->get('/api/mine/stats/trend', [
            'start' => '2026-09-17',
            'end' => '2026-09-17',
        ], $headers));

        self::assertSame(['credit_balance' => 1050], $credit['data']);
        self::assertNotEmpty($records['data']['items']);
        self::assertSame($game->uuid, $games['data']['items'][0]['uuid']);
        self::assertSame([], $games['data']['pending']);
        self::assertSame($game->uuid, $detail['data']['game']['uuid']);
        self::assertFalse($detail['data']['pending']);
        self::assertCount(3, $events['data']['items']);
        self::assertNotNull($firstEventPage['data']['next_cursor']);
        self::assertCount(1, $secondEventPage['data']['items']);
        self::assertNotSame($firstEventPage['data']['items'][0]['id'], $secondEventPage['data']['items'][0]['id']);
        self::assertSame(['hands' => 1, 'wins' => 1, 'invested' => 150, 'profit' => 100, 'win_rate' => 1], $summary['data']['lifetime']);
        self::assertSame([['label' => '2026-09-17 18:42', 'delta' => 100, 'cumulative' => 100]], $trend['data']['items']);
        self::assertArrayHasKey('as_of', $summary['data']);
        self::assertArrayHasKey('as_of', $trend['data']);
    }

    public function test_events_endpoint_lists_current_user_events_with_game_context(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $token = TestData::inCoroutine(fn (): string => TestData::token($user));
        $game = TestData::inCoroutine(function () use ($user): Game {
            $game = TestData::game($user, ['room_number' => 'events-room', 'hand_number' => 128]);
            TestData::event($game, GameEvent::HAND_START, ['room_number' => 'events-room']);
            TestData::event($game, GameEvent::PLAYER_ACTED, [
                'name' => 'Hero',
                'action' => 'call',
                'amount' => 400,
            ]);

            return $game;
        });
        TestData::inCoroutine(function (): Event {
            $otherUser = TestData::user();
            $otherGame = TestData::game($otherUser);

            return TestData::event($otherGame, GameEvent::PLAYER_ACTED, ['action' => 'call']);
        });
        $headers = $this->headersFor($token);

        $firstPage = $this->apiRequest(fn () => $this->get('/api/mine/events', ['limit' => 1], $headers));
        $secondPage = $this->apiRequest(fn () => $this->get('/api/mine/events', [
            'limit' => 1,
            'cursor' => $firstPage['data']['next_cursor'],
        ], $headers));
        $searched = $this->apiRequest(fn () => $this->get('/api/mine/events', ['keyword' => 'call'], $headers));

        self::assertSame('success', $firstPage['code']);
        self::assertCount(1, $firstPage['data']['items']);
        self::assertSame($game->uuid, $firstPage['data']['items'][0]['game']['uuid']);
        self::assertSame('events-room', $firstPage['data']['items'][0]['game']['room_number']);
        self::assertSame(128, $firstPage['data']['items'][0]['game']['hand_number']);
        self::assertSame('we', $firstPage['data']['items'][0]['game']['network']);
        self::assertArrayHasKey('payload', $firstPage['data']['items'][0]);
        self::assertArrayHasKey('updated_at', $firstPage['data']['items'][0]);
        self::assertNotNull($firstPage['data']['next_cursor']);
        self::assertCount(1, $secondPage['data']['items']);
        self::assertNotSame($firstPage['data']['items'][0]['id'], $secondPage['data']['items'][0]['id']);
        self::assertCount(1, $searched['data']['items']);
        self::assertSame(GameEvent::PLAYER_ACTED, $searched['data']['items'][0]['type']);
        self::assertSame('call', $searched['data']['items'][0]['payload']['action']);
    }

    public function test_game_detail_and_events_keep_their_business_order(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $token = TestData::inCoroutine(fn (): string => TestData::token($user));
        $game = TestData::inCoroutine(function () use ($user): Game {
            $game = TestData::game($user, ['ante' => 10]);
            $game->players()->createMany([
                ['seat' => 2, 'name' => 'Big blind', 'is_hero' => false, 'stack' => 1000, 'seat_type' => 'BB', 'blind_amount' => 100, 'bet_amount' => 160, 'cards' => []],
                ['seat' => 1, 'name' => 'Hero', 'is_hero' => true, 'stack' => 1000, 'seat_type' => 'SB', 'blind_amount' => 50, 'bet_amount' => 230, 'cards' => []],
            ]);
            TestData::event($game, GameEvent::HAND_START, ['room_number' => $game->room_number], 1);
            TestData::event($game, GameEvent::FORCE_BET, ['extra_bets' => [['name' => 'Hero', 'amount' => 20]]], 2);
            TestData::event($game, GameEvent::PLAYER_ACTED, ['name' => 'Big blind', 'action' => 'bet', 'amount' => 50], 3);
            TestData::event($game, GameEvent::PLAYER_ACTED, ['name' => 'Hero', 'action' => 'call', 'amount' => 150], 4);

            return $game;
        });
        $headers = $this->headersFor($token);
        $detail = $this->apiRequest(fn () => $this->get('/api/mine/games/detail', ['game_id' => $game->uuid], $headers));
        $events = $this->apiRequest(fn () => $this->get('/api/mine/games/events', [
            'game_id' => $game->uuid,
            'order' => 'asc',
        ], $headers));

        self::assertSame([1, 2], array_column($detail['data']['game']['players'], 'seat'));
        self::assertSame([230, 160], array_column($detail['data']['game']['players'], 'bet_amount'));
        self::assertSame([1, 2, 3, 4], array_column($events['data']['items'], 'seq'));
    }

    public function test_events_endpoint_trims_search_keywords_and_handles_empty_results(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $token = TestData::inCoroutine(fn (): string => TestData::token($user));
        TestData::inCoroutine(function () use ($user): Event {
            $game = TestData::game($user);

            return TestData::event($game, GameEvent::PLAYER_ACTED, ['action' => 'call']);
        });
        $headers = $this->headersFor($token);

        $trimmed = $this->apiRequest(fn () => $this->get('/api/mine/events', ['keyword' => ' call '], $headers));
        $empty = $this->apiRequest(fn () => $this->get('/api/mine/events', ['keyword' => 'missing-event'], $headers));

        self::assertSame('success', $trimmed['code']);
        self::assertCount(1, $trimmed['data']['items']);
        self::assertSame('call', $trimmed['data']['items'][0]['payload']['action']);
        self::assertSame([], $empty['data']['items']);
        self::assertNull($empty['data']['next_cursor']);
    }

    public function test_events_endpoint_rejects_invalid_query_parameters(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $token = TestData::inCoroutine(fn (): string => TestData::token($user));
        $response = $this->apiRequest(fn () => $this->get('/api/mine/events', [
            'limit' => 0,
            'order' => 'newest',
        ], $this->headersFor($token)));

        self::assertSame('event_invalid', $response['code']);
        self::assertArrayHasKey('limit', $response['details']);
        self::assertArrayHasKey('order', $response['details']);
    }

    public function test_two_factor_setup_and_password_change(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor(TestData::inCoroutine(fn (): string => TestData::token($user)));
        $setup = $this->apiRequest(fn () => $this->json('/api/mine/security/create_two_factor', [], $headers));
        self::assertSame('success', $this->apiRequest(fn () => $this->json('/api/mine/security/cancel_two_factor', [], $headers))['code']);
        $setup = $this->apiRequest(fn () => $this->json('/api/mine/security/create_two_factor', [], $headers));
        $confirmed = $this->apiRequest(fn () => $this->json('/api/mine/security/confirm_two_factor', [
            'state' => $setup['data']['state'], 'current_password' => 'CorrectHorseBatteryStaple', 'code' => (new TotpService)->code($setup['data']['secret']),
        ], $headers));

        $passwordUser = TestData::inCoroutine(fn (): User => TestData::user());
        $passwordHeaders = $this->headersFor(TestData::inCoroutine(fn (): string => TestData::token($passwordUser)));
        $changed = $this->apiRequest(fn () => $this->json('/api/mine/security/change_password', [
            'current_password' => 'CorrectHorseBatteryStaple', 'new_password' => 'ChangedPassword123', 'confirmation' => 'ChangedPassword123',
        ], $passwordHeaders));
        $login = $this->apiRequest(fn () => $this->json('/api/auth/login', ['account' => $passwordUser->account, 'password' => 'ChangedPassword123']));

        self::assertSame(['requires_login' => true], $confirmed['data']);
        self::assertSame(['requires_login' => true], $changed['data']);
        self::assertSame('success', $login['code']);
    }

    /** @return array<string, string> */
    private function headersFor(string $token): array
    {
        return ['authorization' => 'Bearer '.$token];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function apiRequest(callable $request): mixed
    {
        return TestData::inCoroutine($request);
    }
}
