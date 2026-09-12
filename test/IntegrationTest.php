<?php

declare(strict_types=1);

use App\Enum\ActionEnum;
use App\Exception\PokerException;
use App\Gateway\GameRuntime;
use App\Model\CreditRecord;
use App\Model\Event;
use App\Model\Game;
use App\Model\Solve;
use App\Model\User;
use App\Service\GameService;
use App\Vo\Game\GetSolveVo;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\Coroutine\Parallel;
use Hyperf\Redis\Redis;
use Swoole\Coroutine;
use Tests\Fixtures\GameFixture;

use function App\Support\di;
use function Tests\run;

/** @return array<string, mixed> */
function runtimeState(int $user): array
{
    return json_decode(di(Redis::class)->get('{'.$user.'}:state'), true, 512, JSON_THROW_ON_ERROR);
}
function runtimeUser(int $balance = 1000, bool $vip = false): User
{
    $user = User::query()->create(['account' => 'test_'.bin2hex(random_bytes(10)), 'nickname' => 'Test', 'password' => 'password1234', 'credit_balance' => $balance, 'is_vip' => $vip]);
    di(GameRuntime::class)->initialize($user->id, $balance, $vip);

    return $user;
}
/** @return array{string, string} */
function pendingSolve(User $user): array
{
    $uuid = '';
    $request = '';
    foreach (array_slice(GameFixture::events(), 0, 8) as $event) {
        if ($uuid !== '') {
            $event['payload']['hand_id'] = $uuid;
        }
        $uuid = di(GameRuntime::class)->accept($user->id, 'owner', $event)['context']->game->id;
        $request = $event['id'];
    }

    return [$uuid, $request];
}

it('deduplicates simultaneous starts across coroutine Redis connections', function (): void {
    run(function (): void {
        $user = runtimeUser();
        $parallel = new Parallel(8);
        for ($i = 0; $i < 8; $i++) {
            $parallel->add(fn () => di(GameRuntime::class)->accept($user->id, 'owner', GameFixture::events()[0]));
        }
        $results = $parallel->wait();
        expect(array_unique(array_map(fn ($r) => $r['context']->game->id, $results)))->toHaveCount(1);
        expect(array_filter($results, fn ($r) => ! $r['duplicate']))->toHaveCount(1);
    });
});
it('completes and persists concurrent duplicate results without double charging', function (): void {
    run(function (): void {
        $user = runtimeUser(100);
        [$uuid,$request] = pendingSolve($user);
        $parallel = new Parallel(4);
        for ($i = 0; $i < 4; $i++) {
            $parallel->add(fn () => di(GameRuntime::class)->complete($user->id, $uuid, $request, GetSolveVo::success(ActionEnum::CHECK, 0)));
        }
        $results = $parallel->wait();
        expect(array_filter($results, fn (GetSolveVo $r) => $r->success))->toHaveCount(1);
        $parallel = new Parallel(4);
        for ($i = 0; $i < 4; $i++) {
            $parallel->add(fn () => di(GameService::class)->flush($user->id));
        }
        $parallel->wait();
        expect(runtimeState($user->id)['balance'])->toBe(0)->and(runtimeState($user->id)['reserved'])->toBe(0);
        expect(CreditRecord::query()->where('user_id', $user->id)->count())->toBe(1);
        expect($user->refresh()->credit_balance)->toBe(0);
    });
});
it('archives all facts and settlement once after a complete game', function (): void {
    run(function (): void {
        $user = runtimeUser();
        $uuid = '';
        foreach (GameFixture::events() as $event) {
            if ($uuid !== '') {
                $event['payload']['hand_id'] = $uuid;
            }
            $uuid = di(GameRuntime::class)->accept($user->id, 'owner', $event)['context']->game->id;
            if ($event['type'] === 'game_get_solve') {
                di(GameRuntime::class)->complete($user->id, $uuid, $event['id'], GetSolveVo::success(ActionEnum::CHECK, 0));
            }
        }
        expect(Game::query()->where('user_id', $user->id)->count())->toBe(0);
        di(GameService::class)->flush($user->id);
        di(GameService::class)->flush($user->id);
        $game = Game::query()->where('uuid', $uuid)->firstOrFail();
        expect($game->status->isSettled())->toBeTrue()->and($game->profit)->toBe(1350);
        expect(Event::query()->where('user_id', $user->id)->count())->toBe(22);
        expect(Solve::query()->where('user_id', $user->id)->count())->toBe(3);
        expect($user->refresh()->credit_balance)->toBe(700);
    });
});
it('retains failed snapshots and rolls back partial SQL before retry', function (): void {
    run(function (): void {
        $user = runtimeUser();
        $runtime = di(GameRuntime::class);
        $uuid = $runtime->accept($user->id, 'owner', GameFixture::events()[0])['context']->game->id;
        $runtime->incomplete($user->id, $uuid);
        $key = '{'.$user->id.'}:pending_games';
        $redis = di(Redis::class);
        $valid = $redis->hget($key, $uuid);
        $bad = json_decode($valid, true);
        $bad['events'][0]['seq'] = 2;
        $redis->hset($key, $uuid, json_encode($bad));
        expect(fn () => di(GameService::class)->flush($user->id))->toThrow(RuntimeException::class);
        expect(Game::query()->where('user_id', $user->id)->count())->toBe(0)->and($redis->hlen($key))->toBe(1);
        $redis->hset($key, $uuid, $valid);
        di(GameService::class)->flush($user->id);
        expect(Game::query()->where('uuid', $uuid)->firstOrFail()->status->isIncomplete())->toBeTrue();
        expect($redis->hlen($key))->toBe(0);
    });
});
it('keeps zero-balance VIP solves free', function (): void {
    run(function (): void {
        $user = runtimeUser(0, true);
        [$uuid, $request] = pendingSolve($user);
        expect(runtimeState($user->id)['reserved'])->toBe(0);
        expect(di(GameRuntime::class)->complete($user->id, $uuid, $request, GetSolveVo::success(ActionEnum::CHECK, 0))->success)->toBeTrue();
        di(GameService::class)->flush($user->id);
        expect(CreditRecord::query()->where('user_id', $user->id)->count())->toBe(0)->and($user->refresh()->credit_balance)->toBe(0);
    });
});
it('releases the original reservation when VIP status changes', function (): void {
    run(function (): void {
        $user = runtimeUser(100);
        [$uuid, $request] = pendingSolve($user);
        $user->update(['is_vip' => true]);
        di(GameRuntime::class)->initialize($user->id, 100, true);
        di(GameRuntime::class)->complete($user->id, $uuid, $request, GetSolveVo::failure(PokerException::solveTimeout()));
        expect(runtimeState($user->id)['reserved'])->toBe(0)->and(runtimeState($user->id)['balance'])->toBe(100);
    });
});
it('isolates translation and database connections between coroutines', function (): void {
    run(function (): void {
        $parallel = new Parallel(2);
        foreach (['en', 'zh-CN'] as $locale) {
            $parallel->add(function () use ($locale): string {
                $translator = di(TranslatorInterface::class);
                $translator->setLocale($locale);
                Coroutine::sleep(.01);

                return $translator->getLocale();
            }, $locale);
        }
        expect($parallel->wait())->toBe(['en' => 'en', 'zh-CN' => 'zh-CN']);
    });
});
