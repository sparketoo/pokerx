<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Game\Providers\ProtoProvider;
use App\Model\User;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\RequestActionResultVo;
use Hyperf\Redis\Redis;
use Swoole\Coroutine;
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
            $connectionA = new GameServerConnectionVo(11, $user, 'client-a');
            $connectionB = new GameServerConnectionVo(12, $user, 'client-b');
            $connectionARestored = new GameServerConnectionVo(13, $user, 'client-a');

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
            $originalConnection = new GameServerConnectionVo(11, $user, 'client-a');
            $restoredConnection = new GameServerConnectionVo(12, $user, 'client-a');

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
