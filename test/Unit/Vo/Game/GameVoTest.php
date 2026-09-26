<?php

declare(strict_types=1);

namespace Tests\Unit\Vo\Game;

use App\Enum\GameEventTypeEnum;
use App\Enum\GameStatusEnum;
use App\Enum\NetworkEnum;
use App\Enum\StageEnum;
use App\Exception\BusinessException;
use App\Exception\GameException;
use App\Vo\Game\GameVo;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameVoTest extends TestCase
{
    public function test_client_id_survives_game_serialization_for_reconnection(): void
    {
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000099', NetworkEnum::WE, 'room-1#99', 100, 50, 0, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1, 'client-a');

        self::assertSame('client-a', unserialize(serialize($game))->clientId);
        self::assertArrayNotHasKey('tokenId', get_object_vars($game));
    }

    public function test_existing_mixed_case_player_uids_match_events_without_losing_original_casing(): void
    {
        $game = new GameVo(1, '1234567890abcdef', NetworkEnum::WE, 'room#1', 100, 50, 0, [
            ['uid' => 'HeRo1', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'ViLlAiN2', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1, 'client-a');

        $action = $game->event(GameEventTypeEnum::ACTION, [
            'uid' => 'villain2', 'action' => 'BET', 'amount' => 50,
        ], 1);
        self::assertSame('ViLlAiN2', $action->player?->uid);
        self::assertSame('ViLlAiN2', $action->payload['uid']);

        $over = $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero1', 'amount' => 200]],
            'shown' => [['uid' => 'HERO1', 'cards' => ['As', 'Kh']]],
        ], 2);
        self::assertSame('HeRo1', $over->payload['winners'][0]['uid']);
        self::assertSame('HeRo1', $over->payload['shown'][0]['uid']);
        self::assertSame(200, $game->hero()->winnings);
    }

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
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000001', NetworkEnum::WE, 'room-1#1', 100, 50, 0, [
            ['uid' => 'hero', 'seat' => 2, 'stack' => 1000, 'hero' => true],
            ['uid' => 'villain-1', 'seat' => 5, 'stack' => 1000, 'hero' => false],
            ['uid' => 'villain-2', 'seat' => 8, 'stack' => 1000, 'hero' => false],
        ], 8, 'client-a');

        self::assertSame(2, $game->smallBlindSeatNumber());
        self::assertSame(5, $game->bigBlindSeatNumber());
        self::assertSame(['villain-1', 'villain-2'], $game->anotherPlayers()->pluck('uid')->all());
    }

    public function test_empty_button_seat_uses_next_players_for_blinds(): void
    {
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000018', NetworkEnum::OK, '6507433#18', 6, 3, 6, [
            ['uid' => 'player1', 'seat' => 1, 'stack' => 100, 'hero' => false],
            ['uid' => 'player2', 'seat' => 2, 'stack' => 100, 'hero' => false],
            ['uid' => 'player4', 'seat' => 4, 'stack' => 100, 'hero' => false],
            ['uid' => 'player5', 'seat' => 5, 'stack' => 100, 'hero' => false],
            ['uid' => 'player7', 'seat' => 7, 'stack' => 100, 'hero' => false],
            ['uid' => 'hero', 'seat' => 8, 'stack' => 100, 'hero' => true],
        ], 3, 'client-a');

        self::assertSame(4, $game->smallBlindSeatNumber());
        self::assertSame(5, $game->bigBlindSeatNumber());
        self::assertSame('player5', $game->bigBlindPlayer()->uid);
        self::assertSame(45, $game->pot());
    }

    public function test_heads_up_empty_button_seat_assigns_both_blinds_to_players(): void
    {
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000019', NetworkEnum::OK, 'room#19', 6, 3, 0, [
            ['uid' => 'hero', 'seat' => 4, 'stack' => 100, 'hero' => true],
            ['uid' => 'villain', 'seat' => 8, 'stack' => 100, 'hero' => false],
        ], 3, 'client-a');

        self::assertSame(4, $game->smallBlindSeatNumber());
        self::assertSame(8, $game->bigBlindSeatNumber());
        self::assertSame(3, $game->hero()->blind);
        self::assertSame(6, $game->bigBlindPlayer()->blind);
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

        $this->expectException(BusinessException::class);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CHECK', 'amount' => 0], 6);
    }

    public function test_post_blind_counts_for_any_player_and_rejects_a_duplicate(): void
    {
        $players = [];
        for ($seat = 1; $seat <= 8; $seat++) {
            $players[] = ['uid' => 'player'.$seat, 'seat' => $seat, 'stack' => 190, 'hero' => $seat === 3];
        }
        $game = new GameVo(1, '11111111-1111-4111-8111-000000000002', NetworkEnum::OK, 'room-129', 2, 1, 2, $players,
            4, 'client-a');
        self::assertSame(19, $game->pot());

        $heroPost = $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'PLAYER3', 'amount' => 2], 1);
        self::assertSame(21, $game->pot());
        $otherPost = $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'player8', 'amount' => 2], 2);

        self::assertSame('player3', $heroPost->payload['uid']);
        self::assertSame('player8', $otherPost->player?->uid);
        self::assertSame(2, $game->hero()->postBlind);
        self::assertSame(4, $game->hero()->total());
        self::assertSame(23, $game->pot());

        try {
            $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'player8', 'amount' => 2], 3);
            self::fail('A duplicate POST_BLIND must not increase the pot');
        } catch (GameException $error) {
            self::assertSame(3002, $error->getCode());
        }
        self::assertSame(23, $game->pot());
        self::assertCount(2, $game->events);
    }

    public function test_post_blind_must_precede_play_and_fit_the_remaining_stack(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], 1);

        try {
            $game->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'hero', 'amount' => 10], 2);
            self::fail('Late POST_BLIND must be rejected');
        } catch (GameException $error) {
            self::assertSame(3002, $error->getCode());
        }

        $short = new GameVo(1, '11111111-1111-4111-8111-000000000003', NetworkEnum::WE, 'short-post', 2, 1, 2, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 3, 'hero' => true],
            ['uid' => 'villain', 'seat' => 2, 'stack' => 100, 'hero' => false],
        ], 1, 'client-a');
        try {
            $short->event(GameEventTypeEnum::POST_BLIND, ['uid' => 'hero', 'amount' => 1], 1);
            self::fail('POST_BLIND cannot exceed remaining stack');
        } catch (GameException $error) {
            self::assertSame(3002, $error->getCode());
        }
    }

    public function test_straddle_blind_can_follow_preflop_stage_and_counts_as_a_live_bet(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'PREFLOP', 'cards' => []], 1);
        $event = $game->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'HERO', 'amount' => 200], 2);

        self::assertSame('hero', $event->payload['uid']);
        self::assertSame(200, $game->hero()->straddleBlind);
        self::assertSame(260, $game->hero()->total());
        self::assertSame(370, $game->pot());
    }

    public function test_over_returns_reduce_net_contributions_without_changing_winnings(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'BET', 'amount' => 200], 1);
        $game->event(GameEventTypeEnum::OVER, [
            'winners' => [['uid' => 'hero', 'amount' => 210]],
            'returns' => [['uid' => 'hero', 'amount' => 100]],
        ], 2);

        self::assertSame(100, $game->hero()->returned);
        self::assertSame(160, $game->hero()->total());
        self::assertSame(270, $game->pot());
        self::assertSame(210, $game->hero()->winnings);
    }

    public function test_straddle_rejects_late_or_unfunded_amount_without_recording_an_event(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kh', 'Qd']], 1);
        try {
            $game->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'hero', 'amount' => 200], 2);
            self::fail('A straddle after the flop must be rejected');
        } catch (GameException $error) {
            self::assertSame(3002, $error->getCode());
        }
        self::assertCount(1, $game->events);

        $short = GameVoFixture::headsUp();
        try {
            $short->event(GameEventTypeEnum::STRADDLE_BLIND, ['uid' => 'hero', 'amount' => 941], 1);
            self::fail('A straddle cannot exceed the remaining stack');
        } catch (GameException $error) {
            self::assertSame(3002, $error->getCode());
        }
        self::assertCount(0, $short->events);
    }

    public function test_over_rejects_duplicate_or_excessive_returns_without_ending_the_hand(): void
    {
        foreach ([
            [['uid' => 'hero', 'amount' => 61]],
            [['uid' => 'hero', 'amount' => 1], ['uid' => 'HERO', 'amount' => 1]],
        ] as $returns) {
            $game = GameVoFixture::headsUp();
            try {
                $game->event(GameEventTypeEnum::OVER, [
                    'winners' => [['uid' => 'hero', 'amount' => 100]],
                    'returns' => $returns,
                ], 1);
                self::fail('Invalid returns must be rejected');
            } catch (GameException $error) {
                self::assertSame(3002, $error->getCode());
            }
            self::assertSame(GameStatusEnum::OPEN, $game->status);
            self::assertCount(0, $game->events);
        }
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
            new GameVo(1, '11111111-1111-4111-8111-000000000001', NetworkEnum::WE, 'room-1#1', 100, 50, 0, [
                ['uid' => 'a', 'seat' => 1, 'stack' => 1000, 'hero' => false],
                ['uid' => 'b', 'seat' => 2, 'stack' => 1000, 'hero' => false],
            ], 1, 'client-a');
            self::fail('A game without hero must be rejected');
        } catch (GameException $error) {
            self::assertSame(1000, $error->getCode());
        }

        try {
            GameVoFixture::headsUp()->playerOrFail('stranger');
            self::fail('Unknown player must be rejected');
        } catch (GameException $error) {
            self::assertSame(1000, $error->getCode());
        }
    }
}
