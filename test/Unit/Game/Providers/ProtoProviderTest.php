<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\GameEventTypeEnum;
use App\Game\Providers\ProtoProvider;
use App\Game\Socket\ProtoWebSocketClient;
use App\Vo\Game\RequestActionResultVo;
use ReflectionProperty;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Tests\Fixtures\FakeProtoSocketRedis;
use Tests\Fixtures\FakeWebSocketTransport;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class ProtoProviderTest extends TestCase
{
    public function test_late_close_from_old_fd_cannot_close_replacement_client(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $options = ['url' => 'ws://proto.test/', 'token' => 'proto-secret'];
            $first = new FakeWebSocketTransport;
            $first->receive('{"result":true,"sessionId":"session-1"}');
            $second = new FakeWebSocketTransport;
            $second->receive('{"result":true,"sessionId":"session-1"}');
            $provider = new ProtoProvider($options, $redis);
            $firstClient = new ProtoWebSocketClient(1, '42', $options, $redis, static fn (): Client => $first);
            $firstClient->connect();
            $this->attach($provider, 10, 1, '42', $firstClient);
            $provider->disconnect(10, 1, '42');
            $secondClient = new ProtoWebSocketClient(1, '42', $options, $redis, static fn (): Client => $second);
            $secondClient->connect();
            $this->attach($provider, 11, 1, '42', $secondClient);
            $provider->disconnect(10, 1, '42');

            self::assertFalse($second->closed);
            $provider->disconnect(11, 1, '42');
            self::assertTrue($second->closed);
        });
    }

    public function test_shared_provider_routes_two_connections_to_independent_proto_clients(): void
    {
        Coroutine\run(function (): void {
            $redis = new FakeProtoSocketRedis;
            $options = ['url' => 'ws://proto.test/', 'token' => 'proto-secret', 'player_id' => 'pokerx'];
            $socket42 = new FakeWebSocketTransport;
            $socket42->receive('{"result":true,"sessionId":"session-42"}');
            $socket43 = new FakeWebSocketTransport;
            $socket43->receive('{"result":true,"sessionId":"session-43"}');
            $provider = new ProtoProvider($options, $redis);
            $client42 = new ProtoWebSocketClient(1, '42', $options, $redis, static fn (): Client => $socket42);
            $client43 = new ProtoWebSocketClient(1, '43', $options, $redis, static fn (): Client => $socket43);
            $client42->connect();
            $client43->connect();
            $this->attach($provider, 10, 1, '42', $client42);
            $this->attach($provider, 11, 1, '43', $client43);
            $first = GameVoFixture::headsUp('11111111-1111-4111-8111-000000000042', 10, '42');
            $second = GameVoFixture::headsUp('11111111-1111-4111-8111-000000000043', 10, '43');
            $answers = [];
            Coroutine::create(static function () use ($provider, $first, &$answers): void {
                $provider->requestAction($first, static function (RequestActionResultVo $result) use (&$answers): void {
                    $answers['42'] = $result;
                });
            });
            Coroutine::create(static function () use ($provider, $second, &$answers): void {
                $provider->requestAction($second, static function (RequestActionResultVo $result) use (&$answers): void {
                    $answers['43'] = $result;
                });
            });
            Coroutine::sleep(0.02);
            $socket43->receive('{"structType":"playerAction","gameId":"'.$second->uuid.'","action":"raise","amount":200}');
            $socket42->receive('{"structType":"playerAction","gameId":"'.$first->uuid.'","action":"check","amount":0}');
            Coroutine::sleep(0.02);

            self::assertSame(ActionEnum::CHECK, $answers['42']->action);
            self::assertSame(ActionEnum::RAISE, $answers['43']->action);
            self::assertSame('gameEvents', json_decode($socket42->sent[1]['data'], true)['structType']);
            self::assertSame('getAnswer', json_decode($socket43->sent[2]['data'], true)['structType']);

            $over = $first->event(GameEventTypeEnum::OVER, ['winners' => [['uid' => 'hero', 'amount' => 200]]], 1);
            $provider->over($over);
            self::assertSame('fullGameLog', json_decode($socket42->sent[3]['data'], true)['structType']);
            $provider->disconnect(10, 1, '42');
            $provider->disconnect(11, 1, '43');
        });
    }

    private function attach(ProtoProvider $provider, int $fd, int $userId, string $clientId, ProtoWebSocketClient $client): void
    {
        $property = new ReflectionProperty(ProtoProvider::class, 'clients');
        $clients = $property->getValue($provider);
        $clients[$userId.':'.$clientId] = ['fd' => $fd, 'client' => $client];
        $property->setValue($provider, $clients);
    }
}
