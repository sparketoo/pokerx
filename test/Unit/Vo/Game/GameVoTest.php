<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\FoundationException;
use App\Exception\GameException;
use App\Vo\Game\GameVo;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameVoTest extends TestCase
{
    public function test_heads_up_button_posts_small_blind_and_next_seat_posts_big_blind(): void
    {
        $game = GameVoFixture::headsUp();

        self::assertSame(1, $game->smallBlindSeatNumber());
        self::assertSame(2, $game->bigBlindSeatNumber());
        self::assertSame(60, $game->hero()->total());
        self::assertSame(110, $game->bigBlindPlayer()->total());
        self::assertSame(170, $game->pot());
    }

    public function test_three_player_blinds_follow_button_with_seat_wraparound(): void
    {
        $game = new GameVo(1, 'aaaabbbbcccc0001', NetworkEnum::WE, 'room-1', 1, 100, 50, 0, [
            ['uid' => 'hero', 'seat' => 2, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain-1', 'seat' => 5, 'stack' => 1000, 'hero' => false],
            ['uid' => 'villain-2', 'seat' => 8, 'stack' => 1000, 'hero' => false],
        ], 8);

        self::assertSame(2, $game->smallBlindSeatNumber());
        self::assertSame(5, $game->bigBlindSeatNumber());
        self::assertSame(['villain-1', 'villain-2'], $game->anotherPlayers()->pluck('uid')->all());
    }

    public function test_events_update_stage_cards_bets_and_final_winnings(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::DEALT, ['cards' => ['As', 'Kh']], 1);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CALL', 'amount' => 50], 2);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['2s', '3s', '4s']], 3);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'TURN', 'cards' => ['5s']], 4);
        $game->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 220]]], 5);

        self::assertSame(['As', 'Kh'], $game->hero()->cards);
        self::assertSame(110, $game->hero()->total());
        self::assertSame(['2s', '3s', '4s', '5s'], $game->cards);
        self::assertSame(StageEnum::TURN, $game->stage);
        self::assertSame(GameStatusEnum::OVER, $game->status);
        self::assertSame(220, $game->hero()->winnings);
        self::assertCount(5, $game->events);

        $this->expectException(FoundationException::class);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CHECK', 'amount' => 0], 6);
    }

    public function test_abort_closes_the_game(): void
    {
        $game = GameVoFixture::headsUp();

        $game->event(GameEventTypeEnum::ABORT, [], 1);

        self::assertSame(GameStatusEnum::ABORT, $game->status);
    }

    public function test_missing_hero_and_unknown_player_are_rejected(): void
    {
        try {
            new GameVo(1, 'aaaabbbbcccc0001', NetworkEnum::WE, 'room-1', 1, 100, 50, 0, [
                ['uid' => 'a', 'seat' => 1, 'stack' => 1000, 'hero' => false],
                ['uid' => 'b', 'seat' => 2, 'stack' => 1000, 'hero' => false],
            ], 1);
            self::fail('A game without hero must be rejected');
        } catch (GameException $error) {
            self::assertSame('hero_not_found', $error->getErrorCode());
        }

        try {
            GameVoFixture::headsUp()->playerOrFail('stranger');
            self::fail('Unknown player must be rejected');
        } catch (GameException $error) {
            self::assertSame('player_not_found', $error->getErrorCode());
        }
    }
}
