<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Vo\Game\GameVo;
use Tests\TestCase;

final class GameVoTest extends TestCase
{
    public function test_existing_mixed_case_player_uids_match_events_without_losing_original_casing(): void
    {
        $game = new GameVo(1, '1234567890abcdef', NetworkEnum::WE, 'room#1', 100, 50, 0, [
            ['uid' => 'HeRo1', 'seat' => 1, 'stack' => 1000, 'hero' => true],
            ['uid' => 'ViLlAiN2', 'seat' => 2, 'stack' => 1000, 'hero' => false],
        ], 1);

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
}
