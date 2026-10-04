<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Enum\NetworkEnum;
use App\Game\Providers\ProtoProvider;
use App\Model\User;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Redis\Redis;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Http\Client;
use Tests\Fixtures\FakeWebSocketTransport;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class ProtoProviderTest extends TestCase
{
    public function test_each_client_has_an_independent_socket_and_reconnect_uses_its_session(): void
    {
        Coroutine\run(function (): void {
            $redis = $this->redis();
            $first = new FakeWebSocketTransport;
            $first->receive('{"result":true,"sessionId":"session-a"}');
            $second = new FakeWebSocketTransport;
            $second->receive('{"result":true,"sessionId":"session-b"}');
            $reconnected = new FakeWebSocketTransport;
            $reconnected->receive('{"result":true,"sessionId":"session-a"}');
            $transports = [$first, $second, $reconnected];
            $provider = new ProtoProvider(
                ['url' => 'ws://proto.test/', 'token' => 'upstream-token'],
                $redis,
                static function () use (&$transports): Client {
                    return array_shift($transports) ?? throw new \RuntimeException('No transport available');
                },
            );
            $user = new User;
            $user->id = 1;
            $connectionA = new GameServerConnectionVo(11, $user, 'client-a', 'proto');
            $connectionB = new GameServerConnectionVo(12, $user, 'client-b', 'proto');
            $connectionARestored = new GameServerConnectionVo(13, $user, 'client-a', 'proto');

            $provider->connect($connectionA);
            $provider->connect($connectionB);
            self::assertNotSame($first->path, $second->path);
            self::assertFalse($first->closed);
            self::assertFalse($second->closed);

            $provider->disconnect($connectionA);
            self::assertTrue($first->closed);
            self::assertFalse($second->closed);
            $provider->connect($connectionARestored);
            self::assertSame('session-a', json_decode($reconnected->sent[0]['data'], true, 512, JSON_THROW_ON_ERROR)['sessionId']);
            $provider->disconnect($connectionA);
            self::assertFalse($reconnected->closed);

            $provider->disconnect($connectionARestored);
            $provider->disconnect($connectionB);
        });
    }

    public function test_get_answer_then_reconnect_resumes_the_same_game_session(): void
    {
        Coroutine\run(function (): void {
            $redis = $this->redis();
            $game = GameVoFixture::headsUp(clientId: 'client-a');
            $first = new FakeWebSocketTransport;
            $reconnected = new FakeWebSocketTransport;
            $first->onPush = static function (mixed $data, int $opcode) use ($first, $game): void {
                if ($opcode !== SWOOLE_WEBSOCKET_OPCODE_TEXT || ! is_string($data)) {
                    return;
                }
                $message = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (isset($message['token'])) {
                    $first->receive('{"result":true,"sessionId":"session-after-answer"}');
                } elseif (($message['structType'] ?? null) === 'getAnswer') {
                    $first->receive(json_encode([
                        'structType' => 'playerAction', 'gameId' => $game->uuid,
                        'action' => 'call', 'amount' => 50,
                    ], JSON_THROW_ON_ERROR));
                }
            };
            $reconnected->onPush = static function (mixed $data, int $opcode) use ($reconnected, $game): void {
                if ($opcode !== SWOOLE_WEBSOCKET_OPCODE_TEXT || ! is_string($data)) {
                    return;
                }
                $message = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (isset($message['token'])) {
                    $reconnected->receive(json_encode(
                        ($message['sessionId'] ?? null) === 'session-after-answer'
                            ? ['result' => true, 'sessionId' => 'session-after-answer']
                            : ['result' => false, 'info' => 'sessionId required'],
                        JSON_THROW_ON_ERROR,
                    ));
                } elseif (($message['structType'] ?? null) === 'getAnswer') {
                    $reconnected->receive(json_encode([
                        'structType' => 'playerAction', 'gameId' => $game->uuid,
                        'action' => 'check', 'amount' => 0,
                    ], JSON_THROW_ON_ERROR));
                }
            };
            $transports = [$first, $reconnected];
            $provider = new ProtoProvider(
                ['url' => 'ws://proto.test/', 'token' => 'upstream-token'],
                $redis,
                static function () use (&$transports): Client {
                    return array_shift($transports) ?? throw new \RuntimeException('No transport available');
                },
            );
            $user = new User;
            $user->id = 1;
            $originalConnection = new GameServerConnectionVo(11, $user, 'client-a', 'proto');
            $restoredConnection = new GameServerConnectionVo(12, $user, 'client-a', 'proto');

            $provider->connect($originalConnection);
            $firstResult = null;
            $provider->requestAction($game, static function (RequestActionResultVo $result) use (&$firstResult): void {
                $firstResult = $result;
            });
            self::assertSame(ActionEnum::CALL, $firstResult?->action);
            self::assertSame($game->uuid, json_decode($first->sent[1]['data'], true, 512, JSON_THROW_ON_ERROR)['game']['gameId']);
            $game->event(GameEventTypeEnum::ACTION, ['uid' => 'hero', 'action' => 'CALL', 'amount' => 50], 1);
            $restoredGame = unserialize(serialize($game));

            $provider->disconnect($originalConnection);
            $provider->connect($restoredConnection);
            $secondResult = null;
            $provider->requestAction($restoredGame, static function (RequestActionResultVo $result) use (&$secondResult): void {
                $secondResult = $result;
            });

            self::assertSame($first->path, $reconnected->path);
            self::assertSame('session-after-answer', json_decode($reconnected->sent[0]['data'], true, 512, JSON_THROW_ON_ERROR)['sessionId']);
            self::assertSame(ActionEnum::CHECK, $secondResult?->action);
            $replayedEvents = json_decode($reconnected->sent[1]['data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($game->uuid, $replayedEvents['game']['gameId']);
            self::assertContains(['eventType' => 'playerActed', 'name' => 'hero', 'action' => 'call', 'amount' => 50], $replayedEvents['events']);
            $answerRequest = json_decode($reconnected->sent[2]['data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($game->uuid, $answerRequest['gameId']);
            self::assertSame(15000, $answerRequest['delay']);
            $provider->disconnect($restoredConnection);
        });
    }

    public function test_upstream_timeout_reconnects_with_the_existing_session(): void
    {
        Coroutine\run(function (): void {
            $redis = $this->redis();
            $game = GameVoFixture::headsUp(clientId: 'client-a');
            $first = new FakeWebSocketTransport;
            $first->receive('{"result":true,"sessionId":"existing-session"}');
            $second = new FakeWebSocketTransport;
            $second->receive('{"result":true,"sessionId":"existing-session"}');
            $reconnected = new Channel(1);
            $second->onPush = static function (mixed $data, int $opcode) use ($second, $game, $reconnected): void {
                if ($opcode !== SWOOLE_WEBSOCKET_OPCODE_TEXT || ! is_string($data)) {
                    return;
                }
                $message = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (isset($message['token'])) {
                    $reconnected->push(true);
                } elseif (($message['structType'] ?? null) === 'getAnswer') {
                    $second->receive(json_encode([
                        'structType' => 'playerAction', 'gameId' => $game->uuid,
                        'action' => 'check', 'amount' => 0,
                    ], JSON_THROW_ON_ERROR));
                }
            };
            $transports = [$first, $second];
            $provider = new ProtoProvider(
                ['url' => 'ws://proto.test/', 'token' => 'upstream-token'],
                $redis,
                static function () use (&$transports): Client {
                    return array_shift($transports) ?? throw new \RuntimeException('No transport available');
                },
            );
            $user = new User;
            $user->id = 1;
            $connection = new GameServerConnectionVo(11, $user, 'client-a', 'proto');

            $provider->connect($connection);
            $first->receive('timeout');
            self::assertTrue($reconnected->pop(2.0));

            self::assertTrue($first->closed);
            self::assertNotEmpty($second->sent);
            $authentication = json_decode($second->sent[0]['data'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('existing-session', $authentication['sessionId']);
            $result = null;
            $provider->requestAction($game, static function (RequestActionResultVo $answer) use (&$result): void {
                $result = $answer;
            });
            self::assertSame(ActionEnum::CHECK, $result?->action);

            $provider->disconnect($connection);
        });
    }

    public function test_wpk_squid_modes_reach_local_proto_websocket_peer(): void
    {
        $url = getenv('WPK_PROTO_WS_REPLAY_URL');
        $capture = getenv('WPK_PROTO_WS_REPLAY_CAPTURE');
        if (! is_string($url) || $url === '' || ! is_string($capture) || $capture === '') {
            self::markTestSkipped('Start the local Proto WebSocket peer and set WPK_PROTO_WS_REPLAY_URL and WPK_PROTO_WS_REPLAY_CAPTURE.');
        }
        Coroutine\run(function () use ($url): void {
            $provider = new ProtoProvider([
                'url' => $url, 'token' => 'wpk-replay-token', 'player_id' => 'wpk-replay',
                'network' => 'WE', 'delay' => 0,
            ], $this->redis());
            $user = new User;
            $user->id = 1;
            $connection = new GameServerConnectionVo(11, $user, 'wpk-replay', 'proto');
            $provider->connect($connection);
            $players = [
                ['uid' => 'p1', 'seat' => 1, 'stack' => 20000, 'hero' => true],
                ['uid' => 'p2', 'seat' => 2, 'stack' => 20000, 'hero' => false],
                ['uid' => 'p3', 'seat' => 3, 'stack' => 20000, 'hero' => false],
                ['uid' => 'p4', 'seat' => 4, 'stack' => 20000, 'hero' => false],
            ];
            $cases = [
                ['STAND_UP', ['p1' => 1, 'p2' => 1, 'p3' => 0, 'p4' => 0], 'p3',
                    [['uid' => 'p1', 'type' => 'payout', 'amount' => 300],
                        ['uid' => 'p2', 'type' => 'payout', 'amount' => 300],
                        ['uid' => 'p3', 'type' => 'payout', 'amount' => 300],
                        ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900]]],
                ['HUNT', ['p1' => 2, 'p2' => 0, 'p3' => 0, 'p4' => 0], 'p2',
                    [['uid' => 'p1', 'type' => 'payout', 'amount' => 1200],
                        ['uid' => 'p2', 'type' => 'payout', 'amount' => 600],
                        ['uid' => 'p3', 'type' => 'penaly', 'amount' => 900],
                        ['uid' => 'p4', 'type' => 'penaly', 'amount' => 900]]],
                ['HUNT', ['p1' => 2, 'p2' => 0, 'p3' => 0, 'p4' => 0], 'p1',
                    [['uid' => 'p1', 'type' => 'payout', 'amount' => 5400],
                        ['uid' => 'p2', 'type' => 'penaly', 'amount' => 1800],
                        ['uid' => 'p3', 'type' => 'penaly', 'amount' => 1800],
                        ['uid' => 'p4', 'type' => 'penaly', 'amount' => 1800]]],
            ];
            foreach ($cases as $index => [$mode, $counts, $winner, $settlement]) {
                $game = new GameVo(1, sprintf('11111111-1111-4111-8111-%012d', $index + 1),
                    NetworkEnum::WPK_CLUB, 'wpk-ws-'.($index + 1).'#1', 200, 100, 0,
                    $players, 1, 'wpk-replay', 'SQUID', $mode, 300, 3, 1, 2);
                foreach ($counts as $uid => $count) {
                    $game->event(GameEventTypeEnum::PLAYER_HAS_SQUID, ['uid' => $uid, 'count' => $count], 1000 + $index);
                }
                if ($index < 2) {
                    $answer = null;
                    $provider->requestAction($game, static function (RequestActionResultVo $result) use (&$answer): void {
                        $answer = $result;
                    });
                    self::assertTrue($answer?->success);
                }
                $game->event(GameEventTypeEnum::GOT_SQUID, ['uid' => $winner, 'count' => 1], 2000 + $index);
                $over = $game->event(GameEventTypeEnum::OVER, [
                    'winners' => [['uid' => $winner, 'amount' => 300]],
                    'squid' => $settlement,
                ], 3000 + $index);
                $provider->over($over);
            }
            Coroutine::sleep(0.05);
            $provider->disconnect($connection);
        });

        $lines = file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Could not read the Proto WebSocket replay capture.');
        }
        $records = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        $logs = array_values(array_filter($records, static fn (array $record): bool => ($record['structType'] ?? null) === 'fullGameLog'));
        self::assertCount(3, $logs);
        self::assertSame(['STAND_UP', 'HUNT', 'HUNT'], array_map(static fn (array $log): string => $log['game']['squidMode'], $logs));
        self::assertSame([[300, 300, 300], [1200, 600], [5400]], array_map(static fn (array $log): array => array_column(array_values(array_filter($log['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPayment')), 'amount'), $logs));
        self::assertSame([[900], [900, 900], [1800, 1800, 1800]], array_map(static fn (array $log): array => array_column(array_values(array_filter($log['events'],
            static fn (array $event): bool => $event['eventType'] === 'squidPenalty')), 'amount'), $logs));
    }

    private function redis(): Redis
    {
        return new class extends Redis
        {
            /** @var array<string, string> */
            public array $values = [];

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->values[$arguments[0]] ?? false;
                }
                if ($name === 'set') {
                    if (isset($this->values[$arguments[0]])) {
                        return false;
                    }
                    $this->values[$arguments[0]] = $arguments[1];

                    return true;
                }
                if ($name === 'eval') {
                    $values = $arguments[1];
                    if (count($values) === 2) {
                        if (($this->values[$values[0]] ?? null) === $values[1]) {
                            unset($this->values[$values[0]]);

                            return 1;
                        }

                        return 0;
                    }
                    if (($this->values[$values[0]] ?? null) !== $values[2]) {
                        return 0;
                    }
                    if (is_string($values[4])) {
                        $this->values[$values[1]] = $values[4];
                    }

                    return 1;
                }

                throw new \RuntimeException('Unexpected Redis command: '.$name);
            }
        };
    }
}
