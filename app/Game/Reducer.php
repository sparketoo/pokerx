<?php

declare(strict_types=1);

namespace App\Game;

use App\Enum\ActionEnum;
use App\Enum\SeatTypeEnum;
use App\Enum\StageEnum;
use App\Exception\GatewayException;
use App\Exception\PokerException;
use App\Vo\Game\GameContextVo;
use Hyperf\Stringable\Str;

use function App\Support\now;
use function Hyperf\Config\config;

final class Reducer
{
    public const TYPES = [
        'game_start', 'game_stage_started', 'game_player_acted', 'game_player_cards', 'game_get_solve', 'game_over',
    ];

    public const MAX = 9007199254740991;

    /**
     * @param  array<string, mixed>  $event
     */
    public function validateEnvelope(array $event): void
    {
        $this->require(isset($event['id']) && is_string($event['id']) && Str::isUuid($event['id']));
        $this->require(in_array($event['type'] ?? null, self::TYPES, true));
        $this->require(isset($event['seq']) && is_int($event['seq']) && $event['seq'] > 0);
        $this->require(isset($event['payload']) && is_array($event['payload']));
        $this->require(! array_diff(array_keys($event), ['id', 'type', 'seq', 'payload']));
    }

    /**
     * @param  array<string, mixed>  $e
     */
    public function start(int $user, string $uuid, array $e): GameContextVo
    {
        $p = $e['payload'];
        $this->keys($p, ['room_id', 'hand_number', 'big_blind', 'ante', 'num_players', 'players', 'cards']);
        foreach (['room_id', 'hand_number'] as $key) {
            $this->require(is_string($p[$key] ?? null) && strlen($p[$key]) > 0 && strlen($p[$key]) <= 64);
        }
        $this->number($p['big_blind'] ?? null, 1);
        $this->number($p['ante'] ?? null);
        $players = $p['players'] ?? [];
        $this->require(is_array($players) && array_is_list($players) && count($players) >= 2 && count($players) <= 9);
        $this->require(($p['num_players'] ?? null) === count($players));
        $names = [];
        $seats = [];
        $positions = [];
        $hero = null;
        $states = [];
        foreach ($players as &$player) {
            $this->require(is_array($player));
            $this->keys($player, ['seat', 'name', 'hero', 'stack', 'seat_type', 'amount']);
            $this->number($player['seat'] ?? null, 1, 65535);
            $this->number($player['stack'] ?? null);
            $this->require(is_string($player['name'] ?? null) && mb_strlen($player['name']) >= 1 && mb_strlen($player['name']) <= 64);
            $this->require(is_bool($player['hero'] ?? null));
            $this->require(! in_array($player['seat'], $seats, true) && ! in_array($player['name'], $names, true));
            $position = SeatTypeEnum::fromName($player['seat_type'] ?? null);
            $this->require($position !== null && ! in_array($position, $positions, true));
            $positions[] = $position;
            $names[] = $player['name'];
            $seats[] = $player['seat'];
            if ($player['hero']) {
                $this->require($hero === null);
                $hero = $player['name'];
            }
            $blind = in_array($position, [SeatTypeEnum::SB, SeatTypeEnum::BB], true);
            if ($blind) {
                $this->number($player['amount'] ?? null);
            } else {
                $this->require(array_key_exists('amount', $player) && $player['amount'] === null);
            }
            $ante = min($p['ante'], $player['stack']);
            $paid = $player['amount'] ?? 0;
            $this->require($paid <= $player['stack'] - $ante);
            $states[$player['name']] = [
                'remaining_stack' => $player['stack'] - $paid - $ante, 'round_bet' => $paid,
                'invested' => $paid + $ante, 'folded' => false, 'all_in' => $player['stack'] === $paid + $ante,
            ];
        }
        unset($player);
        $this->require($hero !== null && in_array(SeatTypeEnum::SB, $positions, true) && in_array(SeatTypeEnum::BB,
            $positions, true));
        $this->require(count($players) === 2 || in_array(SeatTypeEnum::BTN, $positions, true));
        $this->cards($p['cards'] ?? null, 2);
        $this->sum(array_column($states, 'invested'));

        return GameContextVo::fromArray([
            'game_id' => $uuid, 'user_id' => $user, 'room_id' => $p['room_id'], 'hand_number' => $p['hand_number'],
            'provider' => config('poker.default'), 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
            'big_blind' => $p['big_blind'], 'ante' => $p['ante'], 'players' => $players, 'hero_name' => $hero,
            'stage' => null, 'board' => [], 'known_player_cards' => [$hero => $p['cards']], 'player_states' => $states,
            'events' => [], 'last_seq' => 0, 'revision' => 0, 'pending' => null, 'status' => 'open',
        ]);
    }

    /**
     * @param  array<string, mixed>  $e
     */
    public function apply(GameContextVo $c, array $e): GameContextVo
    {
        if (! $c->game->status->isOpen()) {
            throw PokerException::handClosed();
        }
        if ($e['seq'] !== $c->game->lastSeq + 1) {
            throw GatewayException::eventSequenceGap(['expected_seq' => $c->game->lastSeq + 1]);
        }
        $p = $e['payload'];
        $data = $c->toState();
        $states = $data['player_states'];
        if ($e['type'] !== 'game_start') {
            $this->require(($p['hand_id'] ?? null) === $c->game->id);
        }
        switch ($e['type']) {
            case 'game_start':
                $this->require($e['seq'] === 1);
                break;
            case 'game_stage_started':
                $this->keys($p, ['hand_id', 'stage', 'cards']);
                $stage = is_string($p['stage'] ?? null) ? StageEnum::fromName(strtoupper($p['stage'])) : null;
                $this->require($stage !== null && $stage->wire() === $p['stage']);
                $order = StageEnum::cases();
                $index = array_search($stage, $order, true);
                $this->require($index === ($c->game->stage === null ? 0 : array_search($c->game->stage, $order,
                    true) + 1));
                $count = [0, 3, 1, 1, 0][$index];
                $this->cards($p['cards'] ?? null, $count);
                $this->distinctCards(array_merge($data['board'], $p['cards'], ...array_values($data['known_player_cards'])));
                $data['stage'] = $stage->wire();
                $data['board'] = array_merge($data['board'], $p['cards']);
                if ($stage !== StageEnum::PREFLOP) {
                    foreach ($states as &$state) {
                        $state['round_bet'] = 0;
                    }
                }
                unset($state);
                break;
            case 'game_player_acted':
                $this->keys($p, ['hand_id', 'name', 'action', 'amount']);
                $this->require($c->game->stage !== null && $c->game->stage !== StageEnum::SHOWDOWN);
                $name = $p['name'] ?? '';
                $this->require(is_string($name) && isset($states[$name]));
                $action = is_string($p['action'] ?? null) ? ActionEnum::fromName(strtoupper($p['action'])) : null;
                if ($action === null || $action->wire() !== $p['action']) {
                    throw GatewayException::eventInvalid();
                }
                $this->number($p['amount'] ?? null);
                $state = $states[$name];
                $this->require(! $state['folded'] && ! $state['all_in']);
                $b = $state['round_bet'];
                $bets = array_column($states, 'round_bet');
                $this->require($bets !== []);
                $m = max($bets);
                $remaining = $state['remaining_stack'];
                $amount = $p['amount'];
                $paid = match ($action) {
                    ActionEnum::FOLD, ActionEnum::CHECK => 0,
                    ActionEnum::CALL, ActionEnum::BET => $amount,
                    ActionEnum::RAISE => $m - $b + $amount,
                    ActionEnum::ALL_IN => $remaining
                };
                $this->require($paid <= $remaining && $paid >= 0);
                $this->require(match ($action) {
                    ActionEnum::FOLD => $amount === 0,
                    ActionEnum::CHECK => $amount === 0 && $m === $b,
                    ActionEnum::CALL => $amount === $m - $b && $amount > 0,
                    ActionEnum::BET => $m === 0 && $amount > 0,
                    ActionEnum::RAISE => $m > 0 && $amount > 0,
                    ActionEnum::ALL_IN => $amount === $remaining
                });
                $state['round_bet'] += $paid;
                $state['invested'] += $paid;
                $state['remaining_stack'] -= $paid;
                $state['folded'] = $action === ActionEnum::FOLD;
                $state['all_in'] = $state['remaining_stack'] === 0;
                $states[$name] = $state;
                break;
            case 'game_player_cards':
                $this->keys($p, ['hand_id', 'name', 'cards']);
                $name = $p['name'] ?? '';
                $this->require(is_string($name) && isset($states[$name]) && $name !== $c->hero()->name);
                $this->cards($p['cards'] ?? null, 2);
                if (isset($data['known_player_cards'][$name])) {
                    $this->require($data['known_player_cards'][$name] === $p['cards']);
                }
                $known = $data['known_player_cards'];
                $known[$name] = $p['cards'];
                $this->distinctCards(array_merge($data['board'], ...array_values($known)));
                $data['known_player_cards'] = $known;
                break;
            case 'game_get_solve':
                $this->keys($p, ['hand_id', 'pot_for_alpha', 'delay']);
                $this->number($p['pot_for_alpha'] ?? null);
                $delay = $p['delay'] ?? 9000;
                $this->require(is_int($delay) && $delay >= 5000 && $delay <= 13000 && $delay % 1000 === 0);
                $this->require($c->game->stage !== null && $c->game->stage !== StageEnum::SHOWDOWN && ! $states[$c->hero()->name]['folded'] && ! $states[$c->hero()->name]['all_in']);
                $this->require($p['pot_for_alpha'] === $this->sum(array_column($states, 'invested')));
                break;
            case 'game_over':
                $this->keys($p, ['hand_id', 'shown', 'winner']);
                $shown = $p['shown'] ?? null;
                $winner = $p['winner'] ?? null;
                $this->require(is_array($shown) && array_is_list($shown) && is_array($winner));
                $this->keys($winner, ['name', 'amount']);
                $this->require(is_string($winner['name'] ?? null) && isset($states[$winner['name']]) && ! $states[$winner['name']]['folded']);
                $this->number($winner['amount'] ?? null);
                $this->require($winner['amount'] <= $this->sum(array_column($states, 'invested')));
                $known = $data['known_player_cards'];
                $seen = [];
                foreach ($shown as $s) {
                    $this->require(is_array($s));
                    $this->keys($s, ['name', 'cards']);
                    $name = $s['name'] ?? '';
                    $this->require(is_string($name) && isset($states[$name]) && ! in_array($name, $seen, true));
                    $seen[] = $name;
                    $this->cards($s['cards'] ?? null, 2);
                    if (isset($known[$name])) {
                        $this->require($known[$name] === $s['cards']);
                    }
                    $known[$name] = $s['cards'];
                }
                $this->distinctCards(array_merge($data['board'], ...array_values($known)));
                $data['status'] = 'closed';
                $data['shown'] = $shown;
                $data['winner'] = $winner;
                $data['invested'] = $states[$c->hero()->name]['invested'];
                $data['awarded'] = $winner['name'] === $c->hero()->name ? $winner['amount'] : 0;
                $data['profit'] = $data['awarded'] - $data['invested'];
                break;
        }
        $this->sum(array_column($states, 'invested'));
        $data['player_states'] = $states;
        $data['last_seq'] = $e['seq'];
        if ($e['type'] !== 'game_get_solve') {
            $data['revision']++;
        }
        $data['events'][] = $e + ['created_at' => now('UTC')->format('Y-m-d H:i:s.u')];

        return GameContextVo::fromArray($data);
    }

    /** @phpstan-assert true $valid */
    private function require(bool $valid): void
    {
        if (! $valid) {
            throw GatewayException::eventInvalid();
        }
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $payload
     */
    private function keys(array $payload, array $allowed): void
    {
        $this->require(! array_diff(array_keys($payload), $allowed));
    }

    private function number(mixed $v, int $min = 0, int $max = self::MAX): void
    {
        $this->require(is_int($v) && $v >= $min && $v <= $max);
    }

    private function cards(mixed $cards, int $count): void
    {
        $this->require(is_array($cards) && array_is_list($cards) && count($cards) === $count);
        foreach ($cards as $card) {
            $this->require(is_string($card) && preg_match('/^[2-9TJQKA][shdc]$/D', $card) === 1);
        }
        $this->distinctCards($cards);
    }

    /**
     * @param  array<array-key, string>  $cards
     */
    private function distinctCards(array $cards): void
    {
        $this->require(count($cards) === count(array_unique($cards)));
    }

    /**
     * @param  list<int>  $values
     */
    private function sum(array $values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            $this->require($value <= self::MAX - $sum);
            $sum += $value;
        }

        return $sum;
    }
}
