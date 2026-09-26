<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Game\Providers\IdelProvider;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Tests\TestCase;

final class IdelProviderTest extends TestCase
{
    public function test_first_turn_checks_when_no_call_is_needed(): void
    {
        $result = $this->request($this->game(heroSeat: 2));

        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame(0, $result->amount);
    }

    public function test_first_turn_calls_the_outstanding_bet(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'RAISE', 'amount' => 100], 1);

        $result = $this->request($game);

        self::assertSame(ActionEnum::CALL, $result->action);
        self::assertSame(150, $result->amount);
    }

    public function test_first_turn_on_a_later_street_uses_only_that_streets_bets(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kd', 'Qc']], 1);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'BET', 'amount' => 70], 2);

        $result = $this->request($game);

        self::assertSame(ActionEnum::CALL, $result->action);
        self::assertSame(70, $result->amount);
    }

    public function test_short_stack_uses_all_in_for_the_first_call(): void
    {
        $game = $this->game(heroStack: 120);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'RAISE', 'amount' => 100], 1);

        $result = $this->request($game);

        self::assertSame(ActionEnum::ALL_IN, $result->action);
        self::assertSame(60, $result->amount);
    }

    public function test_folds_after_hero_acted_even_on_a_later_street(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CALL', 'amount' => 50], 1);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kd', 'Qc']], 2);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'BET', 'amount' => 70], 3);

        $result = $this->request($game);

        self::assertSame(ActionEnum::FOLD, $result->action);
        self::assertSame(0, $result->amount);
    }

    public function test_folds_after_hero_checked_in_the_same_street(): void
    {
        $game = $this->game(heroSeat: 2);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CHECK', 'amount' => 0], 1);

        $result = $this->request($game);

        self::assertSame(ActionEnum::FOLD, $result->action);
        self::assertSame(0, $result->amount);
    }

    private function request(GameVo $game): RequestActionResultVo
    {
        $result = null;
        (new IdelProvider)->requestAction($game, static function (RequestActionResultVo $answer) use (&$result): void {
            $result = $answer;
        });

        self::assertInstanceOf(RequestActionResultVo::class, $result);
        self::assertTrue($result->success);

        return $result;
    }

    private function game(int $heroSeat = 1, int $heroStack = 1000): GameVo
    {
        $game = new GameVo(1, '33333333-3333-4333-8333-333333333333', NetworkEnum::WE, 'room#1', 100, 50, 10, [
            ['uid' => 'hero', 'seat' => $heroSeat, 'stack' => $heroStack, 'hero' => true],
            ['uid' => 'villain', 'seat' => $heroSeat === 1 ? 2 : 1, 'stack' => 1000, 'hero' => false],
        ], 1, 'client-a');
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => $heroSeat === 1 ? 'hero' : 'villain', 'type' => 'SB', 'amount' => 50], 1);
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => $heroSeat === 1 ? 'villain' : 'hero', 'type' => 'BB', 'amount' => 100], 2);

        return $game;
    }
}
