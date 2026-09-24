<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Model\User;
use App\Model\UserToken;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use Hyperf\Context\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\TestCase;

final class GameServerPingTest extends TestCase
{
    public function test_message_timestamps_do_not_move_backwards_within_a_connection(): void
    {
        $sender = new class extends Sender
        {
            /** @var list<array<string, mixed>> */
            public array $messages = [];

            public function __construct() {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'push') {
                    $this->messages[] = json_decode($arguments[1], true, 512, JSON_THROW_ON_ERROR);

                    return true;
                }

                return false;
            }
        };
        $providers = new GameProviderManager;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, new FakeProtoHttpRedis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, new User(['language' => 'zh-CN']), new UserToken),
            2 => new GameServerConnectionVo(2, new User(['language' => 'zh-CN']), new UserToken),
        ]);

        $send = static function (int $fd, string $id, string $type, int|string $timestamp, ?array $payload = null) use ($server, $sender): array {
            $message = compact('id', 'type', 'timestamp');
            if ($payload !== null) {
                $message['payload'] = $payload;
            }
            $frame = new Frame;
            $frame->fd = $fd;
            $frame->data = json_encode($message, JSON_THROW_ON_ERROR);
            $before = count($sender->messages);
            $server->onMessage(null, $frame);
            self::assertCount($before + 1, $sender->messages);

            return $sender->messages[$before];
        };

        $timestamp = (int) floor(microtime(true) * 1000);
        $nonInteger = $send(1, 'ping-string', 'PING', (string) ($timestamp + 100));
        self::assertSame('error', $nonInteger['type']);
        self::assertSame('event_invalid', $nonInteger['payload']['code']);

        $first = $send(1, 'ping-1', 'PING', $timestamp);
        self::assertSame('PING.ACK', $first['type']);
        self::assertSame('ping-1', $first['reply_to']);
        self::assertSame([], $first['payload']);

        $invalidStart = $send(1, 'start-1', 'START', $timestamp + 1, []);
        self::assertSame('error', $invalidStart['type']);
        self::assertSame('event_invalid', $invalidStart['payload']['code']);

        $older = $send(1, 'ping-old', 'PING', $timestamp);
        self::assertSame('error', $older['type']);
        self::assertSame('event_invalid', $older['payload']['code']);
        self::assertSame('ping-old', $older['reply_to']);

        $olderAgain = $send(1, 'ping-old-again', 'PING', $timestamp);
        self::assertSame('error', $olderAgain['type']);

        $equal = $send(1, 'ping-equal', 'PING', $timestamp + 1);
        self::assertSame('PING.ACK', $equal['type']);
        self::assertSame('ping-equal', $equal['reply_to']);

        $otherConnection = $send(2, 'ping-other', 'PING', $timestamp);
        self::assertSame('PING.ACK', $otherConnection['type']);
    }
}
