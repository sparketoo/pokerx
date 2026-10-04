<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Game\Providers\PokerAiProvider;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Di\Container;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

use function App\Support\di;

final class PokerAiProviderTest extends TestCase
{
    public function test_decision_requests_log_start_result_and_failure_without_credentials_or_cards(): void
    {
        $container = di(Container::class);
        $originalLogger = $container->get(LoggerInterface::class);
        $handler = new TestHandler;
        $logger = new Logger('pokerai-test');
        $logger->pushHandler($handler);
        $container->set(LoggerInterface::class, $logger);

        try {
            $provider = new PokerAiProvider(['api_key' => 'secret-key'], static fn (): array => [
                'strategy' => [['action' => 'raise', 'frequency' => 1, 'amount_bb' => 3]],
            ]);
            self::assertTrue($this->request($provider, $this->game())->success);
            self::assertFalse($this->request($provider, $this->game(10))->success);

            $records = $handler->getRecords();
            self::assertSame([
                'PokerAI action started',
                'PokerAI action completed',
                'PokerAI action started',
                'PokerAI action failed',
            ], array_map(static fn ($record): string => $record->message, $records));
            self::assertSame('PREFLOP', $records[0]->context['stage']);
            self::assertSame('RAISE', $records[1]->context['action']);
            self::assertSame(300, $records[1]->context['amount']);
            self::assertSame('unsupported_spot', $records[3]->context['reason']);
            self::assertIsNumeric($records[1]->context['duration_ms']);

            $logged = json_encode(array_map(static fn ($record): array => [$record->message, $record->context], $records), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('secret-key', $logged);
            self::assertStringNotContainsString('AhKh', $logged);
        } finally {
            $container->set(LoggerInterface::class, $originalLogger);
        }
    }

    public function test_preflop_open_converts_absolute_bb_sizing_to_incremental_chips(): void
    {
        $calls = [];
        $provider = new PokerAiProvider([], static function (string $path, array $body) use (&$calls): array {
            $calls[] = [$path, $body];

            return ['strategy' => [['action' => 'raise', 'frequency' => 1, 'amount_bb' => 3]]];
        });

        $result = $this->request($provider, $this->game());

        self::assertSame(ActionEnum::RAISE, $result->action);
        self::assertSame(300, $result->amount);
        self::assertSame('/v1/gto/preflop', $calls[0][0]);
        self::assertSame('AhKh', $calls[0][1]['hole_cards']);
        self::assertSame('UTG', $calls[0][1]['positions']['hero']);
        self::assertSame([
            ['position' => 'SB', 'action' => 'small blind', 'amount' => 0.5],
            ['position' => 'BB', 'action' => 'big blind', 'amount' => 1],
        ], $calls[0][1]['preflop_actions']);
    }

    public function test_flop_uses_hero_node_and_returns_a_bet(): void
    {
        $game = $this->flopGame();
        $calls = [];
        $provider = new PokerAiProvider([], static function (string $path, array $body) use (&$calls): array {
            $calls[] = [$path, $body];

            return match ($path) {
                '/v1/gto/flop/tree' => ['pot' => 7.5, 'effective_stack' => 97, 'nodes' => [['node' => 'root', 'is_hero' => true, 'token' => 'flop-token']]],
                '/v1/gto/flop/node' => ['is_hero' => true, 'strategy' => [['action' => 'bet', 'frequency' => 1, 'amount_bb' => 4]]],
                default => throw new \RuntimeException('Unexpected PokerAI path'),
            };
        });

        $result = $this->request($provider, $game);

        self::assertSame(ActionEnum::BET, $result->action);
        self::assertSame(400, $result->amount);
        self::assertSame(['hero' => 'UTG', 'raiser' => 'UTG', 'caller' => 'BTN'], $calls[0][1]['positions']);
        self::assertSame('SRP', $calls[0][1]['pot_type']);
        self::assertSame('flop-token', $calls[1][1]['node']);
    }

    public function test_turn_projects_flop_range_then_solves(): void
    {
        $game = $this->turnGame();
        $calls = [];
        $provider = new PokerAiProvider([], static function (string $path, array $body) use (&$calls): array {
            $calls[] = [$path, $body];

            return match ($path) {
                '/v1/gto/flop/tree' => ['pot' => 7.5, 'effective_stack' => 97],
                '/v1/gto/flop/projected-range' => ['range_oop_new' => 'AA:1', 'range_ip_new' => 'KK:1'],
                '/v1/gto/solver' => ['status' => 'queryable', 'solve' => 'turn-solve'],
                '/v1/gto/solver/tree' => ['spot_status' => 'queryable', 'nodes' => [['node' => 'root', 'is_hero' => true, 'token' => 'turn-token']]],
                '/v1/gto/solver/node' => ['is_hero' => true, 'strategy' => [['action' => 'check', 'frequency' => 1]]],
                default => throw new \RuntimeException('Unexpected PokerAI path'),
            };
        });

        $result = $this->request($provider, $game);

        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame('root/BET_4/CALL', $calls[1][1]['node_id']);
        self::assertSame('2c2h2s9d', $calls[2][1]['board']);
        self::assertSame(15.5, $calls[2][1]['pot']);
        self::assertSame(93.0, $calls[2][1]['effective_stack']);
        self::assertSame('OOP', $calls[2][1]['hero']);
        self::assertSame('turn-solve', $calls[3][1]['solve']);
    }

    public function test_river_uses_runout_of_the_turn_solve(): void
    {
        $game = $this->turnGame();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CHECK', 'amount' => 0], 110);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'button', 'action' => 'CHECK', 'amount' => 0], 120);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'RIVER', 'cards' => ['Qh']], 130);
        $calls = [];
        $provider = new PokerAiProvider([], static function (string $path, array $body) use (&$calls): array {
            $calls[] = [$path, $body];

            return match ($path) {
                '/v1/gto/flop/tree' => ['pot' => 7.5, 'effective_stack' => 97],
                '/v1/gto/flop/projected-range' => ['range_oop_new' => 'AA:1', 'range_ip_new' => 'KK:1'],
                '/v1/gto/solver' => ['status' => 'queryable', 'solve' => 'turn-solve'],
                '/v1/gto/solver/tree' => ['spot_status' => 'queryable', 'nodes' => [['node' => 'root/CHECK/CHECK/Qh', 'is_hero' => true, 'token' => 'river-token']]],
                '/v1/gto/solver/node' => ['is_hero' => true, 'strategy' => [['action' => 'check', 'frequency' => 1]]],
                default => throw new \RuntimeException('Unexpected PokerAI path'),
            };
        });

        $result = $this->request($provider, $game);

        self::assertSame(ActionEnum::CHECK, $result->action);
        self::assertSame('Qh', $calls[3][1]['river_card']);
        self::assertSame('river-token', $calls[4][1]['node']);
    }

    public function test_unsupported_ante_returns_failure_without_calling_upstream(): void
    {
        $provider = new PokerAiProvider([], static function (): array {
            self::fail('Unsupported spots must not consume quota');
        });
        $game = $this->game(10);

        $result = $this->request($provider, $game);

        self::assertFalse($result->success);
        self::assertSame('unsupported_spot', $result->exception?->context()['reason']);
    }

    public function test_a_solver_raise_below_the_table_minimum_is_rejected(): void
    {
        $game = new GameVo(1, 'a1111111-1111-4111-8111-111111111112', NetworkEnum::WE, 'room#2', 100, 50, 0, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 10000, 'hero' => true],
            ['uid' => 'big', 'seat' => 2, 'stack' => 10000, 'hero' => false],
            ['uid' => 'early', 'seat' => 3, 'stack' => 10000, 'hero' => false],
            ['uid' => 'middle', 'seat' => 4, 'stack' => 10000, 'hero' => false],
            ['uid' => 'cutoff', 'seat' => 5, 'stack' => 10000, 'hero' => false],
            ['uid' => 'button', 'seat' => 6, 'stack' => 10000, 'hero' => false],
        ], 6, 'client-a');
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => 'hero', 'type' => 'SB', 'amount' => 50], 10);
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => 'big', 'type' => 'BB', 'amount' => 100], 20);
        $game->event(GameEventTypeEnum::DEALT, ['uid' => 'hero', 'cards' => ['Ah', 'Kh']], 30);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'early', 'action' => 'RAISE', 'amount' => 900], 40);
        foreach (['middle', 'cutoff', 'button'] as $index => $uid) {
            $game->event(GameEventTypeEnum::ACTION, ['uid' => $uid, 'action' => 'FOLD', 'amount' => 0], 50 + $index * 10);
        }
        $provider = new PokerAiProvider([], static fn (): array => [
            'strategy' => [['action' => 'raise', 'frequency' => 1, 'amount_bb' => 10]],
        ]);

        $result = $this->request($provider, $game);

        self::assertFalse($result->success);
        self::assertSame('invalid_raise_sizing', $result->exception?->context()['detail']);
    }

    public function test_a_three_way_flop_cannot_be_sent_as_a_heads_up_presolved_spot(): void
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'RAISE', 'amount' => 300], 40);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'middle', 'action' => 'FOLD', 'amount' => 0], 50);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'cutoff', 'action' => 'CALL', 'amount' => 300], 60);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'button', 'action' => 'CALL', 'amount' => 300], 70);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'small', 'action' => 'FOLD', 'amount' => 0], 80);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'big', 'action' => 'FOLD', 'amount' => 0], 90);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['2c', '2h', '2s']], 100);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'cutoff', 'action' => 'FOLD', 'amount' => 0], 101);
        $provider = new PokerAiProvider([], static function (): array {
            self::fail('Multiway flop must not consume quota');
        });

        $result = $this->request($provider, $game);

        self::assertFalse($result->success);
        self::assertSame('unsupported_postflop_players', $result->exception?->context()['detail']);
    }

    public function test_flop_tree_with_different_pot_is_rejected_before_fetching_a_node(): void
    {
        $calls = [];
        $provider = new PokerAiProvider([], static function (string $path) use (&$calls): array {
            $calls[] = $path;

            return ['pot' => 8, 'effective_stack' => 97, 'nodes' => [['node' => 'root', 'is_hero' => true, 'token' => 'token']]];
        });

        $result = $this->request($provider, $this->flopGame());

        self::assertFalse($result->success);
        self::assertSame('presolved_spot_mismatch', $result->exception?->context()['detail']);
        self::assertSame(['/v1/gto/flop/tree'], $calls);
    }

    private function request(PokerAiProvider $provider, GameVo $game): RequestActionResultVo
    {
        $result = null;
        $provider->requestAction($game, static function (RequestActionResultVo $answer) use (&$result): void {
            $result = $answer;
        });
        self::assertInstanceOf(RequestActionResultVo::class, $result);

        return $result;
    }

    private function game(int $ante = 0): GameVo
    {
        $game = new GameVo(1, 'a1111111-1111-4111-8111-111111111111', NetworkEnum::WE, 'room#1', 100, 50, $ante, [
            ['uid' => 'small', 'seat' => 1, 'stack' => 10000, 'hero' => false],
            ['uid' => 'big', 'seat' => 2, 'stack' => 10000, 'hero' => false],
            ['uid' => 'hero', 'seat' => 3, 'stack' => 10000, 'hero' => true],
            ['uid' => 'middle', 'seat' => 4, 'stack' => 10000, 'hero' => false],
            ['uid' => 'cutoff', 'seat' => 5, 'stack' => 10000, 'hero' => false],
            ['uid' => 'button', 'seat' => 6, 'stack' => 10000, 'hero' => false],
        ], 6, 'client-a');
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => 'small', 'type' => 'SB', 'amount' => 50], 10);
        $game->event(GameEventTypeEnum::BLIND_POSTED, ['uid' => 'big', 'type' => 'BB', 'amount' => 100], 20);
        $game->event(GameEventTypeEnum::DEALT, ['uid' => 'hero', 'cards' => ['Ah', 'Kh']], 30);

        return $game;
    }

    private function flopGame(): GameVo
    {
        $game = $this->game();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'RAISE', 'amount' => 300], 40);
        foreach (['middle', 'cutoff'] as $index => $uid) {
            $game->event(GameEventTypeEnum::ACTION, ['uid' => $uid, 'action' => 'FOLD', 'amount' => 0], 50 + $index * 10);
        }
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'button', 'action' => 'CALL', 'amount' => 300], 70);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'small', 'action' => 'FOLD', 'amount' => 0], 80);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'big', 'action' => 'FOLD', 'amount' => 0], 90);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['2c', '2h', '2s']], 100);

        return $game;
    }

    private function turnGame(): GameVo
    {
        $game = $this->flopGame();
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'BET', 'amount' => 400], 101);
        $game->event(GameEventTypeEnum::ACTION, ['uid' => 'button', 'action' => 'CALL', 'amount' => 400], 102);
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'TURN', 'cards' => ['9d']], 103);

        return $game;
    }
}
