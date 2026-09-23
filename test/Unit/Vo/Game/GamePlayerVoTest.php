<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GamePlayerVoTest extends TestCase
{
    public function test_actions_add_only_active_bets_to_total_and_player_events_are_filtered(): void
    {
        $game = GameVoFixture::headsUp();
        $hero = $game->hero();
        $hero->action(ActionEnum::CALL, 50);
        $hero->action(ActionEnum::CHECK, 0);
        $game->event(GameEventTypeEnum::SHOW, ['uid' => 'hero', 'cards' => ['As', 'Kh']], 1);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'villain', 'action' => 'BET', 'amount' => 100], 2);

        self::assertSame(50, $hero->bet);
        self::assertSame(110, $hero->total());
        self::assertSame(['As', 'Kh'], $hero->cards);
        self::assertSame([GameEventTypeEnum::SHOW], $hero->events()->pluck('type')->all());
    }

    public function test_fold_stops_later_bets_and_winnings(): void
    {
        $hero = GameVoFixture::headsUp()->hero();
        $hero->action(ActionEnum::FOLD, 0);
        $hero->action(ActionEnum::BET, 200);
        $hero->winnings(500);

        self::assertTrue($hero->isFold);
        self::assertSame(0, $hero->bet);
        self::assertSame(0, $hero->winnings);
        self::assertSame(60, $hero->total());
    }
}
