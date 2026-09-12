<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Model\GamePlayer;
use App\Vo\Vo;
use InvalidArgumentException;

use function Hyperf\Collection\collect;

abstract class EventPayloadVo extends Vo
{
    /** @param  array<string, mixed>  $data */
    public static function fromEvent(string $type, array $data): self
    {
        return match ($type) {
            'game_start' => new GameStartedVo($data['room_id'], $data['hand_number'], $data['big_blind'], $data['ante'],
                $data['num_players'], collect(self::arrayValue($data['players']))->map(fn (array $p) => new GamePlayer([
                    'seat' => $p['seat'], 'name' => $p['name'], 'is_hero' => $p['hero'], 'stack' => $p['stack'],
                    'seat_type' => $p['seat_type'], 'blind_amount' => $p['amount'],
                ])), collect(self::arrayValue($data['cards']))),
            'game_stage_started' => new StageStartedVo($data['hand_id'],
                StageEnum::fromNameOrFail(strtoupper($data['stage'])), collect(self::arrayValue($data['cards']))),
            'game_player_acted' => new PlayerActedVo($data['hand_id'], $data['name'],
                ActionEnum::fromNameOrFail(strtoupper($data['action'])), $data['amount']),
            'game_player_cards' => new KnownPlayerCardsVo($data['hand_id'], $data['name'],
                collect(self::arrayValue($data['cards']))),
            'game_get_solve' => new GetSolveRequestVo($data['hand_id'], $data['pot_for_alpha'], $data['delay'] ?? null),
            'game_over' => new GameOverVo($data['hand_id'],
                collect(self::arrayValue($data['shown']))->map(fn (array $p) => new PlayerCardsVo($p['name'],
                    collect(self::arrayValue($p['cards'])))),
                new WinnerVo($data['winner']['name'], $data['winner']['amount'])),
            default => throw new InvalidArgumentException('Unknown event type'),
        };
    }
}
