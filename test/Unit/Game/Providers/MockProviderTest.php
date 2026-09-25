<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Game\Providers\MockProvider;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Swoole\Event;
use Tests\TestCase;

final class MockProviderTest extends TestCase
{
    public function test_preflop_call_uses_the_blind_difference(): void
    {
        $game = $this->game();

        $result = $this->request(new MockProvider(1), $game);

        self::assertTrue($result->success);
        self::assertSame(ActionEnum::CALL, $result->action);
        self::assertSame(50, $result->amount);
    }

    public function test_new_street_resets_round_bets_but_keeps_spent_stack(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CALL', 'amount' => 50], 1);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kd', 'Qc']], 2);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'BET', 'amount' => 70], 3);

        $result = $this->request(new MockProvider(1), $game);

        self::assertSame(ActionEnum::CALL, $result->action);
        self::assertSame(70, $result->amount);
    }

    public function test_check_and_short_stack_all_in(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CALL', 'amount' => 50], 1);
        $result = $this->request(new MockProvider(1), $game);
        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame(0, $result->amount);

        $short = $this->game(120, 'short-game-12345');
        $short->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'RAISE', 'amount' => 100], 1);
        $result = $this->request(new MockProvider(1), $short);
        self::assertSame(ActionEnum::ALL_IN, $result->action);
        self::assertSame(60, $result->amount);
    }

    public function test_posted_blind_is_live_in_preflop_and_reduces_remaining_stack(): void
    {
        $game = new GameVo(1, '22222222-2222-4222-8222-222222222223', NetworkEnum::OK, 'room#129', 2, 1, 2, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 4, 'hero' => true],
            ['uid' => 'small', 'seat' => 2, 'stack' => 100, 'hero' => false],
            ['uid' => 'big', 'seat' => 3, 'stack' => 100, 'hero' => false],
        ], 1, 1);
        $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'hero', 'amount' => 2], 1);

        $result = $this->request(new MockProvider(1), $game);

        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame(0, $result->amount);
    }

    public function test_straddle_is_a_live_preflop_bet_for_action_advice(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], 1);
        $game->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'hero', 'amount' => 100], 2);

        $result = $this->request(new MockProvider(1), $game);

        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame(0, $result->amount);
    }

    public function test_duplicate_request_and_failure_are_reported(): void
    {
        $provider = new MockProvider(1, true);
        $game = $this->game();
        $first = null;
        $second = null;
        $provider->requestAction($game, static function (RequestActionResultVo $result) use (&$first): void {
            $first = $result;
        });
        $provider->requestAction($game, static function (RequestActionResultVo $result) use (&$second): void {
            $second = $result;
        });
        Event::wait();

        self::assertInstanceOf(RequestActionResultVo::class, $first);
        self::assertInstanceOf(RequestActionResultVo::class, $second);
        self::assertFalse($second->success);
        self::assertFalse($first->success);
    }

    private function request(MockProvider $provider, GameVo $game): RequestActionResultVo
    {
        $result = null;
        $provider->requestAction($game, static function (RequestActionResultVo $answer) use (&$result): void {
            $result = $answer;
        });
        Event::wait();

        self::assertInstanceOf(RequestActionResultVo::class, $result);

        return $result;
    }

    private function game(int $heroStack = 1000, string $uuid = '22222222-2222-4222-8222-222222222222'): GameVo
    {
        return new GameVo(1, $uuid, NetworkEnum::WE, 'room#1', 100, 50, 10, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => $heroStack, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1, 1);
    }
}
