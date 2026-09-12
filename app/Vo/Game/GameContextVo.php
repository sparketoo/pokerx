<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\GameStatusEnum;
use App\Enum\SeatTypeEnum;
use App\Enum\StageEnum;
use App\Vo\Vo;
use Hyperf\Collection\Collection;
use LogicException;

use function Hyperf\Collection\collect;
use function Hyperf\Config\config;

final class GameContextVo extends Vo
{
    /**
     * @param  Collection<int, PlayerVo>  $players
     * @param  Collection<int, GameEventVo>  $events
     * @param  Collection<string, SolveVo>  $solves
     * @param  Collection<int, PlayerCardsVo>  $shown
     */
    public function __construct(
        public readonly GameVo $game,
        public readonly Collection $players,
        public readonly Collection $events,
        public readonly Collection $solves,
        public readonly ?PendingSolveVo $pending,
        public readonly Collection $shown,
        public readonly ?WinnerVo $winner
    ) {}

    public function hero(): PlayerVo
    {
        return $this->players->first(fn (PlayerVo $player
        ) => $player->isHero) ?? throw new LogicException('Missing hero');
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $game = new GameVo($data['game_id'], $data['user_id'], $data['room_id'], $data['hand_number'],
            $data['provider'], $data['created_at'], $data['big_blind'], $data['ante'],
            isset($data['stage']) ? StageEnum::fromNameOrFail(strtoupper($data['stage'])) : null,
            GameStatusEnum::fromNameOrFail(strtoupper($data['status'])), collect(self::arrayValue($data['board'])),
            $data['last_seq'], $data['revision'], $data['invested'] ?? null, $data['awarded'] ?? null,
            $data['profit'] ?? null);
        $players = collect(self::arrayValue($data['players']))->map(fn (array $p) => new PlayerVo($p['seat'],
            $p['name'], $p['hero'], $p['stack'], SeatTypeEnum::fromNameOrFail($p['seat_type']), $p['amount'],
            min($data['ante'], $p['stack']), collect(self::arrayValue($data['known_player_cards'][$p['name']] ?? [])),
            PlayerStateVo::fromArray($data['player_states'][$p['name']])));
        $pending = $data['pending'] ?? null;

        return new self($game, $players,
            collect(self::arrayValue($data['events']))->map(fn (array $e) => GameEventVo::fromArray($e)),
            collect(self::arrayValue($data['solves'] ?? []))->map(fn (array $s) => SolveVo::fromArray($s)),
            $pending ? new PendingSolveVo($pending['request_id'], $pending['revision'], $pending['deadline'],
                $pending['reserved_cost'] ?? config('poker.cost')) : null,
            collect(self::arrayValue($data['shown'] ?? []))->map(fn (array $p) => new PlayerCardsVo($p['name'],
                collect(self::arrayValue($p['cards'])))),
            isset($data['winner']) ? new WinnerVo($data['winner']['name'], $data['winner']['amount']) : null);
    }

    /** Redis snapshot codec; preserves existing persisted game snapshots.
     *
     * @return array<string, mixed>
     */
    public function toState(): array
    {
        $data = $this->game->jsonSerialize();
        $data['game_id'] = $data['id'];
        unset($data['id']);

        return $data + [
            'hero_name' => $this->hero()->name,
            'players' => $this->players->map(fn (PlayerVo $p) => $p->initialState())->all(),
            'player_states' => $this->players->mapWithKeys(fn (PlayerVo $p
            ) => [$p->name => $p->state->jsonSerialize()])->all(),
            'known_player_cards' => $this->players->filter(fn (PlayerVo $p
            ) => $p->cards->isNotEmpty())->mapWithKeys(fn (PlayerVo $p) => [$p->name => $p->cards->all()])->all(),
            'events' => $this->events->map(fn (GameEventVo $e) => $e->jsonSerialize())->all(),
            'solves' => $this->solves->map(fn (SolveVo $s) => $s->jsonSerialize())->all(),
            'pending' => $this->pending?->jsonSerialize(),
            'shown' => $this->shown->map(fn (PlayerCardsVo $p) => $p->jsonSerialize())->all(),
            'winner' => $this->winner?->jsonSerialize(),
        ];
    }

    /** @param  array<string, mixed>  $changes */
    public function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toState(), $changes));
    }
}
