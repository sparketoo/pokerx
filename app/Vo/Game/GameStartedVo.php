<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Model\GamePlayer;
use Hyperf\Collection\Collection;

final class GameStartedVo extends EventPayloadVo
{
    /** @param  Collection<int, GamePlayer>  $players
     * @param  Collection<int, string>  $cards
     */
    public function __construct(
        public readonly string $roomId,
        public readonly string $handNumber,
        public readonly int $bigBlind,
        public readonly int $ante,
        public readonly int $numPlayers,
        public readonly Collection $players,
        public readonly Collection $cards
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'room_id' => $this->roomId, 'hand_number' => $this->handNumber,
            'big_blind' => $this->bigBlind, 'ante' => $this->ante, 'num_players' => $this->numPlayers,
            'cards' => $this->cards->all(),
            'players' => $this->players->map(fn (GamePlayer $player) => [
                'seat' => $player->seat, 'name' => $player->name, 'hero' => $player->is_hero,
                'stack' => $player->stack, 'seat_type' => $player->seat_type->name,
                'amount' => $player->blind_amount,
            ])->all(),
        ];
    }
}
