<?php

declare(strict_types=1);

use App\Controller\Mine\StatsController;
use App\Enum\NetworkEnum;
use App\Exception\GameException;
use App\Exception\GatewayException;
use App\Game\PokerManager;
use App\Game\PokerServer;
use App\Game\Providers\BaseProvider;
use App\Game\Providers\ProtoProvider;
use App\Model\Game;
use App\Model\User;
use App\Model\UserToken;
use App\Service\CreditService;
use App\Service\GameService;
use App\Service\UserTokenService;
use App\Vo\Game\PokerServerConnectionVo;
use App\Vo\Game\PokerServerMessageVo;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Di\Container;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\NullLogger;
use Swoole\WebSocket\Frame;
use Tests\Support\InMemoryGameDatabase;

final class GameAbortFixture
{
    public ConnectionResolverInterface $previousResolver;

    public User $user;

    public GameService $service;

    public Game $game;

    /** @var list<array{seat: int, name: string, hero: bool, stack: int, seat_type: string}> */
    public array $players;

    /** @var list<array{type: string, payload: array<string, mixed>}> */
    public array $history;

    /** @var Closure(string, array<string, mixed>=, ?string=): Game */
    public Closure $append;
}
$fixture = new GameAbortFixture;

beforeEach(function () use ($fixture): void {
    $fixture->previousResolver = InMemoryGameDatabase::install();
    $fixture->user = new User(['id' => 1, 'is_vip' => true]);
    $fixture->service = new GameService(new CreditService, new PokerManager);
    $fixture->players = [
        ['seat' => 1, 'name' => 'Small', 'hero' => false, 'stack' => 1000, 'seat_type' => 'SB'],
        ['seat' => 2, 'name' => 'Big', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB'],
        ['seat' => 3, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'BTN'],
    ];
    $fixture->history = [
        ['type' => 'force_bet', 'payload' => ['small_blind' => 50, 'big_blind' => 100, 'ante' => 5, 'extra_bets' => [['name' => 'Hero', 'type' => 'straddle', 'amount' => 200]]]],
        ['type' => 'hand_card', 'payload' => ['cards' => ['As', 'Qd']]],
        ['type' => 'player_acted', 'payload' => ['name' => 'Hero', 'action' => 'call', 'amount' => 75]],
        ['type' => 'player_acted', 'payload' => ['name' => 'Hero', 'action' => 'fold', 'amount' => 0]],
    ];
    $fixture->game = $fixture->service->create($fixture->user, 'room', 1, $fixture->players, (string) Str::uuid(), [], NetworkEnum::OK);
    $fixture->append = fn (string $type, array $payload = [], ?string $id = null) => $fixture->service->append($fixture->user, $fixture->game->uuid, $id ?? (string) Str::uuid(), $type, ['hand_uuid' => $fixture->game->uuid] + $payload);
});

afterEach(function () use ($fixture): void {
    $container = ApplicationContext::getContainer();
    assert($container instanceof Container);
    $container->set(ConnectionResolverInterface::class, $fixture->previousResolver);
});

it('persists exact folded loss including forced bets and replays only the same abort event', function () use ($fixture): void {
    foreach ($fixture->history as $event) {
        ($fixture->append)($event['type'], $event['payload']);
    }
    $id = (string) Str::uuid();
    $game = ($fixture->append)('game_abort', [], $id);
    expect($game->status->wire())->toBe('abort')->and($game->winnings)->toBe(0)->and($game->profit)->toBe(-280)->and($game->bet_amount)->toBe(280);
    expect(($fixture->append)('game_abort', [], $id)->events)->toHaveCount(6);
    $controller = (new ReflectionClass(StatsController::class))->newInstanceWithoutConstructor();
    $stats = (new ReflectionMethod($controller, 'statistics'))->invoke($controller, 1, []);
    expect($stats['lifetime'])->toMatchArray(['hands' => 1, 'wins' => 0, 'invested' => 280.0, 'profit' => -280.0]);
    foreach (['game_abort', 'request_action', 'player_acted', 'hand_over'] as $type) {
        expect(fn () => ($fixture->append)($type))->toThrow(GatewayException::class);
    }
    expect(fn () => (new ProtoProvider)->gameEvents($game, true))->toThrow(GatewayException::class);
    expect(fn () => (new ProtoProvider(['connect_timeout' => 0]))->over($game))->toThrow(GatewayException::class);
});

it('rejects abort before an accepted hero fold or after normal settlement', function () use ($fixture): void {
    foreach (array_slice($fixture->history, 0, 3) as $event) {
        ($fixture->append)($event['type'], $event['payload']);
    }
    expect(fn () => ($fixture->append)('game_abort'))->toThrow(GatewayException::class);
    ($fixture->append)('player_acted', ['name' => 'Small', 'action' => 'fold', 'amount' => 0]);
    expect(fn () => ($fixture->append)('game_abort'))->toThrow(GatewayException::class);
    ($fixture->append)('hand_over', ['winners' => [['name' => 'Hero', 'amount' => 400]]]);
    expect(fn () => ($fixture->append)('game_abort'))->toThrow(GatewayException::class);
});

it('includes zero investment abort in hands but never wins', function () use ($fixture): void {
    ($fixture->append)('force_bet', ['small_blind' => 50, 'big_blind' => 100]);
    ($fixture->append)('hand_card', ['cards' => ['As', 'Qd']]);
    ($fixture->append)('player_acted', ['name' => 'Hero', 'action' => 'fold', 'amount' => 0]);
    $game = ($fixture->append)('game_abort');
    expect($game->profit)->toBe(0);
    $controller = (new ReflectionClass(StatsController::class))->newInstanceWithoutConstructor();
    $stats = (new ReflectionMethod($controller, 'statistics'))->invoke($controller, 1, []);
    expect($stats['lifetime'])->toMatchArray(['hands' => 1, 'wins' => 0, 'invested' => 0.0, 'profit' => 0.0]);
});

it('reconstructs aborted history and cannot reopen it through refresh', function () use ($fixture): void {
    $events = [...$fixture->history, ['type' => 'game_abort', 'payload' => []]];
    $game = $fixture->service->upsertHandRefresh($fixture->user, 'room', 1, $fixture->players, (string) Str::uuid(), [], $events, NetworkEnum::OK);
    expect($game->status->wire())->toBe('abort')->and($game->profit)->toBe(-280);
    expect(fn () => $fixture->service->upsertHandRefresh($fixture->user, 'room', 1, $fixture->players, (string) Str::uuid(), [], $fixture->history, NetworkEnum::OK))->toThrow(GatewayException::class);
});

it('accepts WPK hands with the per-hand native game ID as room number', function () use ($fixture): void {
    $network = NetworkEnum::fromNameOrFail('WPK');
    $first = $fixture->service->create($fixture->user, '9007199254740993123', 1, $fixture->players, (string) Str::uuid(), [], $network);
    $second = $fixture->service->create($fixture->user, '9007199254740993124', 1, $fixture->players, (string) Str::uuid(), [], $network);
    expect($first->room_number)->toBe('9007199254740993123')->and($second->uuid)->not->toBe($first->uuid);
});

it('dispatches abort ACK after persistence without ordinary settlement and rejects extra fields', function () use ($fixture): void {
    foreach ($fixture->history as $event) {
        ($fixture->append)($event['type'], $event['payload']);
    }
    $manager = new PokerManager;
    $provider = new class extends BaseProvider
    {
        public int $settlements = 0;

        public ?string $abortedStatus = null;

        public function requestAction(Game $game, Closure $callback): void {}

        public function over(Game $game, ?Closure $onError = null): void
        {
            $this->settlements++;
        }

        public function abort(Game $game): void
        {
            $this->abortedStatus = $game->fresh()?->status->wire();
        }
    };
    $manager->extend($manager->getDefaultProvider(), fn () => $provider);
    $sender = new class extends Sender
    {
        /** @var list<array<string, mixed>> */
        public array $messages = [];

        public function __construct() {}

        /** @param array<int, mixed> $arguments */
        public function __call(string $name, array $arguments): mixed
        {
            $this->messages[] = json_decode($arguments[1], true);

            return true;
        }
    };
    $server = new PokerServer($sender, $manager, new UserTokenService, $fixture->service, \App\Support\di(ValidatorFactoryInterface::class), new NullLogger);
    (new ReflectionProperty($server, 'connections'))->setValue($server, [1 => new PokerServerConnectionVo(1, $fixture->user, new UserToken)]);
    $id = (string) Str::uuid();
    $frame = new Frame;
    $frame->fd = 1;
    $frame->data = json_encode(['id' => $id, 'type' => 'game_abort', 'timestamp' => (int) (microtime(true) * 1000), 'payload' => ['hand_uuid' => $fixture->game->uuid]], JSON_THROW_ON_ERROR);
    $server->onMessage(null, $frame);
    expect($sender->messages[0])->toMatchArray(['type' => 'game_abort.ack', 'reply_to' => $id, 'payload' => ['hand_uuid' => $fixture->game->uuid]])
        ->and($provider->abortedStatus)->toBe('abort')->and($provider->settlements)->toBe(0);
    $message = new PokerServerMessageVo(1, $fixture->user, new UserToken, (string) Str::uuid(), 'game_abort', ['hand_uuid' => $fixture->game->uuid, 'reason' => 'fold'], 0);
    expect(fn () => $server->handleGameAbort($message))->toThrow(GatewayException::class);
});

it('rejects truncated refresh without fold and keeps empty winners invalid', function () use ($fixture): void {
    $events = [...array_slice($fixture->history, 0, 3), ['type' => 'game_abort', 'payload' => []]];
    expect(fn () => $fixture->service->upsertHandRefresh($fixture->user, 'room', 1, $fixture->players, (string) Str::uuid(), [], $events, NetworkEnum::OK))->toThrow(GatewayException::class);
    foreach ($fixture->history as $event) {
        ($fixture->append)($event['type'], $event['payload']);
    }
    expect(fn () => ($fixture->append)('hand_over', ['winners' => []]))->toThrow(GameException::class);
});

it('retires a pending Proto solve on abort without a complete game log', function () use ($fixture): void {
    \Tests\run(function () use ($fixture): void {
        $provider = new ProtoProvider;
        $result = null;
        (new ReflectionMethod($provider, 'setRequestActionCallback'))->invoke($provider, $fixture->game->uuid, function ($value) use (&$result): void {
            $result = $value;
        });
        $provider->abort($fixture->game);
        expect($result->success)->toBeFalse()->and($result->error_code)->toBe('solve_stale');
        expect((new ReflectionMethod($provider, 'hasRequestActionCallback'))->invoke($provider, $fixture->game->uuid))->toBeFalse();
    });
});

it('rejects a malformed nonzero fold in refresh instead of treating it as authoritative abort', function () use ($fixture): void {
    $events = array_slice($fixture->history, 0, 3);
    $events[] = ['type' => 'player_acted', 'payload' => ['name' => 'Hero', 'action' => 'fold', 'amount' => 10]];
    $events[] = ['type' => 'game_abort', 'payload' => []];
    expect(fn () => $fixture->service->upsertHandRefresh($fixture->user, 'room', 1, $fixture->players, (string) Str::uuid(), [], $events, NetworkEnum::OK))->toThrow(GatewayException::class);
});
