<?php

declare(strict_types=1);

use App\Constants\GameEvent;
use App\Enum\ActionEnum;
use App\Enum\GameStatusEnum;
use App\Game\PokerManager;
use App\Game\PokerServer;
use App\Game\Providers\ProviderInterface;
use App\Model\Game;
use App\Service\CreditService;
use App\Service\GameService;
use App\Service\UserTokenService;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\NullLogger;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Tests\Support\TestData;

use function App\Support\di;
use function Tests\run;

final class SenderSpy extends Sender
{
    /** @var list<array{fd: int, message: array<string, mixed>}> */
    public array $messages = [];

    public function __construct() {}

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'push') {
            if (! is_int($arguments[0] ?? null) || ! is_string($arguments[1] ?? null)) {
                throw new RuntimeException('Invalid WebSocket response.');
            }
            $this->messages[] = [
                'fd' => $arguments[0],
                'message' => json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR),
            ];

            return true;
        }

        return null;
    }
}

function websocketOpenRequest(int $fd, string $token): Request
{
    $request = new Request;
    $request->fd = $fd;
    $request->get = ['token' => $token];

    return $request;
}

function websocketFrame(int $fd, string $data): Frame
{
    $frame = new Frame;
    $frame->fd = $fd;
    $frame->data = $data;

    return $frame;
}

afterEach(function (): void {
    Mockery::close();
});

it('dispatches game_start, persists it and acknowledges the client', function (): void {
    run(function (): void {
        $calls = [];
        $provider = new class($calls) implements ProviderInterface
        {
            /** @param array<int, string> $calls */
            public function __construct(public array &$calls) {}

            public function start(Game $game): void
            {
                $this->calls[] = 'start';
            }

            public function stage(Game $game): void
            {
                $this->calls[] = 'stage';
            }

            public function playerActed(Game $game): void
            {
                $this->calls[] = 'acted';
            }

            public function knownPlayerCards(Game $game): void
            {
                $this->calls[] = 'cards';
            }

            public function requestAction(Game $game, Closure $callback): void
            {
                $this->calls[] = 'request';
            }

            public function over(Game $game): void
            {
                $this->calls[] = 'over';
            }
        };
        $manager = new PokerManager;
        $manager->extend('spy', fn (): ProviderInterface => $provider);
        $sender = new SenderSpy;
        $server = new PokerServer(
            $sender,
            $manager,
            new UserTokenService,
            new GameService(new CreditService),
            di(ValidatorFactoryInterface::class),
            new NullLogger,
        );
        $user = TestData::user();
        $token = TestData::token($user);
        $swoole = Mockery::mock();
        $swoole->shouldReceive('disconnect')->never();
        $server->onOpen($swoole, websocketOpenRequest(91, $token));
        $id = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(91, json_encode([
            'id' => $id, 'type' => GameEvent::GAME_START, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => [
                'room_number' => 'ws-room', 'hand_number' => 1, 'provider' => 'spy', 'big_blind' => 100,
                'small_blind' => 50, 'players' => [
                    ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB', 'amount' => null],
                    ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB', 'amount' => null],
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        expect($calls)->toBe(['start'])
            ->and($sender->messages)->toHaveCount(1)
            ->and($sender->messages[0]['fd'])->toBe(91)
            ->and($sender->messages[0]['message']['type'])->toBe('game_start.ack')
            ->and($sender->messages[0]['message']['reply_to'])->toBe($id)
            ->and(Game::query()->where('room_number', 'ws-room')->where('user_id', $user->id)->count())->toBe(1);

        /** @var Game $game */
        $game = Game::query()->where('room_number', 'ws-room')->where('user_id', $user->id)->firstOrFail();
        $invalidEvents = [
            [GameEvent::GAME_STAGE, ['game_uuid' => $game->uuid, 'stage' => 'invalid', 'cards' => []]],
            [GameEvent::GAME_PLAY_ACTED, ['game_uuid' => $game->uuid, 'name' => 'Hero', 'action' => 'raise']],
            [GameEvent::GAME_KNOWN_PLAY_CARDS, ['game_uuid' => $game->uuid, 'name' => 'Hero', 'cards' => ['As']]],
            [GameEvent::GAME_REQUEST_ACTION, ['game_uuid' => 'not-a-uuid']],
            [GameEvent::GAME_OVER, ['game_uuid' => $game->uuid, 'winner' => ['name' => 'Hero']]],
        ];
        foreach ($invalidEvents as [$type, $payload]) {
            $server->onMessage($swoole, websocketFrame(91, json_encode([
                'id' => (string) Str::uuid(), 'type' => $type, 'timestamp' => (int) floor(microtime(true) * 1000),
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR)));
        }

        expect($calls)->toBe(['start'])
            ->and($sender->messages)->toHaveCount(6)
            ->and($game->events()->count())->toBe(1);
        foreach (array_slice($sender->messages, 1) as $response) {
            expect($response['message']['type'])->toBe('error')
                ->and($response['message']['payload']['code'])->toBe('event_invalid');
        }
    });
});

it('persists follow-up events and sends the provider action in wire format', function (): void {
    run(function (): void {
        $provider = new class implements ProviderInterface
        {
            /** @var list<string> */
            public array $calls = [];

            public function start(Game $game): void
            {
                $this->calls[] = 'start';
            }

            public function stage(Game $game): void
            {
                $this->calls[] = 'stage';
            }

            public function playerActed(Game $game): void
            {
                $this->calls[] = 'acted';
            }

            public function knownPlayerCards(Game $game): void
            {
                $this->calls[] = 'cards';
            }

            public function requestAction(Game $game, Closure $callback): void
            {
                $this->calls[] = 'request';
                $callback(RequestActionResultVo::success(ActionEnum::ALL_IN, 900));
            }

            public function over(Game $game): void
            {
                $this->calls[] = 'over';
            }
        };
        $manager = new PokerManager;
        $manager->extend('spy', fn (): ProviderInterface => $provider);
        $sender = new SenderSpy;
        $server = new PokerServer($sender, $manager, new UserTokenService, new GameService(new CreditService), di(ValidatorFactoryInterface::class), new NullLogger);
        $user = TestData::user();
        $token = TestData::token($user);
        $swoole = Mockery::mock();
        $swoole->shouldReceive('disconnect')->never();
        $server->onOpen($swoole, websocketOpenRequest(92, $token));

        $startId = (string) Str::uuid();
        $start = ['id' => $startId, 'type' => GameEvent::GAME_START, 'timestamp' => (int) floor(microtime(true) * 1000), 'payload' => [
            'room_number' => 'ws-action-room', 'hand_number' => 1, 'provider' => 'spy', 'big_blind' => 100, 'small_blind' => 50,
            'players' => [
                ['seat' => 1, 'name' => 'Hero', 'hero' => true, 'stack' => 1000, 'seat_type' => 'SB', 'amount' => null],
                ['seat' => 2, 'name' => 'Villain', 'hero' => false, 'stack' => 1000, 'seat_type' => 'BB', 'amount' => null],
            ],
        ]];
        $server->onMessage($swoole, websocketFrame(92, json_encode($start, JSON_THROW_ON_ERROR)));
        $uuid = $sender->messages[0]['message']['payload']['game_uuid'];
        $actedId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $actedId, 'type' => GameEvent::GAME_PLAY_ACTED, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['game_uuid' => $uuid, 'name' => 'Hero', 'action' => 'raise', 'amount' => 100],
        ], JSON_THROW_ON_ERROR)));
        $requestId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $requestId, 'type' => GameEvent::GAME_REQUEST_ACTION, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => ['game_uuid' => $uuid],
        ], JSON_THROW_ON_ERROR)));
        $overId = (string) Str::uuid();
        $server->onMessage($swoole, websocketFrame(92, json_encode([
            'id' => $overId, 'type' => GameEvent::GAME_OVER, 'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => [
                'game_uuid' => $uuid,
                'winner' => ['name' => 'Hero', 'amount' => 300],
                'shown' => [['name' => 'Hero', 'cards' => ['As', 'Qd']]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $game = Game::query()->where('uuid', $uuid)->firstOrFail();

        expect($provider->calls)->toBe(['start', 'acted', 'request', 'over'])
            ->and($sender->messages[1]['message']['type'])->toBe('game_play_acted.ack')
            ->and($sender->messages[1]['message']['reply_to'])->toBe($actedId)
            ->and($sender->messages[2]['message']['type'])->toBe('game_play_action')
            ->and($sender->messages[2]['message']['payload'])->toMatchArray(['game_uuid' => $uuid, 'action' => 'all-in', 'amount' => 900])
            ->and($sender->messages[3]['message']['type'])->toBe('game_request_action.ack')
            ->and($sender->messages[4]['message']['type'])->toBe('game_over.ack')
            ->and($sender->messages[4]['message']['reply_to'])->toBe($overId)
            ->and($game->pot)->toBe(250.0)
            ->and($game->bet_amount)->toBe(150.0)
            ->and($game->status)->toBe(GameStatusEnum::CLOSED)
            ->and($game->winnings)->toBe(300.0)
            ->and($game->profit)->toBe(150.0)
            ->and($game->events()->count())->toBe(4);
    });
});
