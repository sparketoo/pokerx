<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\GameStatusEnum;
use App\Model\Game;
use App\Model\User;
use App\Service\CreditService;
use App\Service\TotpService;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use Tests\HttpTestCase;
use Tests\Support\TestData;

use function App\Support\di;

final class ApiTest extends HttpTestCase
{
    public function test_health_and_login(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user([
            'account' => 'api_login_'.substr(str_replace('-', '', (string) Str::uuid()), 0, 16),
        ]));

        self::assertSame(['status' => 'ok'], $this->apiRequest(fn () => $this->get('/health')));
        $login = $this->apiRequest(fn () => $this->json('/api/auth/login', [
            'account' => $user->account, 'password' => 'CorrectHorseBatteryStaple',
        ]));

        self::assertSame('success', $login['code']);
        self::assertSame((string) $user->id, $login['data']['user']['id']);
        self::assertMatchesRegularExpression('/^[1-9][0-9]*\|[a-f0-9]{64}$/', $login['data']['token']);
    }

    public function test_profile_update_and_logout(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor(TestData::inCoroutine(fn (): string => TestData::token($user)));

        self::assertSame((string) $user->id, $this->apiRequest(fn () => $this->get('/api/mine', [], $headers))['data']['id']);
        self::assertSame(['nickname' => 'Renamed'], $this->apiRequest(fn () => $this->json('/api/mine/update_nickname', ['nickname' => 'Renamed'], $headers))['data']);
        self::assertSame(['language' => 'en'], $this->apiRequest(fn () => $this->json('/api/mine/update_language', ['language' => 'en'], $headers))['data']);
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
            TestData::event($game, GameEvent::GAME_START, ['room_number' => $game->room_number]);
            TestData::event($game, GameEvent::GAME_PLAY_ACTED, ['name' => 'Hero', 'action' => 'call', 'amount' => 100]);
            TestData::event($game, GameEvent::GAME_OVER, ['winnings' => 250]);
            di(Redis::class)->set('{'.$user->id.'}:state', json_encode([
                'balance' => 1050, 'reserved' => 50,
                'games' => [$game->uuid => ['context' => ['status' => 'open', 'room_id' => $game->room_number, 'hand_number' => $game->hand_number]]],
            ], JSON_THROW_ON_ERROR));

            return $game;
        });
        $headers = $this->headersFor($token);
        $credit = $this->apiRequest(fn () => $this->get('/api/mine/credit', [], $headers));
        $records = $this->apiRequest(fn () => $this->get('/api/mine/credit/record', ['limit' => 5], $headers));
        $games = $this->apiRequest(fn () => $this->get('/api/mine/games', ['limit' => 5], $headers));
        $detail = $this->apiRequest(fn () => $this->get('/api/mine/games/detail', ['game_id' => $game->uuid], $headers));
        $events = $this->apiRequest(fn () => $this->get('/api/mine/games/events', ['game_id' => $game->uuid, 'scope' => 'mine'], $headers));
        $summary = $this->apiRequest(fn () => $this->get('/api/mine/stats/summary', [], $headers));
        $trend = $this->apiRequest(fn () => $this->get('/api/mine/stats/trend', ['snapshot' => $summary['data']['snapshot']], $headers));

        self::assertSame(['balance' => 1050, 'reserved' => 50, 'available' => 1000, 'sync_pending' => false], $credit['data']);
        self::assertNotEmpty($records['data']['items']);
        self::assertSame($game->uuid, $games['data']['items'][0]['uuid']);
        self::assertSame($game->uuid, $games['data']['pending'][0]['uuid']);
        self::assertSame($game->uuid, $detail['data']['game']['uuid']);
        self::assertTrue($detail['data']['pending']);
        self::assertCount(3, $events['data']['items']);
        self::assertSame(['hands' => 1, 'wins' => 1, 'invested' => 150, 'profit' => 100, 'win_rate' => 1], $summary['data']['lifetime']);
        self::assertSame($summary['data']['snapshot'], $trend['data']['snapshot']);
    }

    public function test_two_factor_setup_and_password_change(): void
    {
        $user = TestData::inCoroutine(fn (): User => TestData::user());
        $headers = $this->headersFor(TestData::inCoroutine(fn (): string => TestData::token($user)));
        $setup = $this->apiRequest(fn () => $this->json('/api/mine/security/create_two_factor', [], $headers));
        self::assertSame('success', $this->apiRequest(fn () => $this->json('/api/mine/security/cancel_two_factor', ['setup_id' => $setup['data']['setup_id']], $headers))['code']);
        $setup = $this->apiRequest(fn () => $this->json('/api/mine/security/create_two_factor', [], $headers));
        $confirmed = $this->apiRequest(fn () => $this->json('/api/mine/security/confirm_two_factor', [
            'setup_id' => $setup['data']['setup_id'], 'current_password' => 'CorrectHorseBatteryStaple', 'code' => (new TotpService)->code($setup['data']['secret']),
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
