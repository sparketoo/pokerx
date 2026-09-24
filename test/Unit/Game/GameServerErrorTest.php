<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Exception\GameException;
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
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeGameServerSender;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameServerErrorTest extends TestCase
{
    public function test_invalid_connection_can_receive_auth_error_without_recursion(): void
    {
        [$server, $sender] = $this->newServer(false);

        \Swoole\Coroutine\run(function () use ($server): void {
            $this->send($server, 2, ['id' => 'missing-auth', 'type' => 'PING', 'timestamp' => $this->now()]);
        });

        self::assertSame('auth_required', $sender->messages[0]['payload']['code']);
        self::assertSame('missing-auth', $sender->messages[0]['reply_to']);
        self::assertSame([2], $sender->disconnected);
        \Swoole\Coroutine\run(static function () use ($server): void {
            $server->onClose(null, 2, 0);
        });
    }

    public function test_malformed_json_is_reported_as_invalid_event(): void
    {
        [$server, $sender] = $this->newServer();

        $this->sendRaw($server, 1, '{invalid');

        self::assertSame('event_invalid', $sender->messages[0]['payload']['code']);
        self::assertNull($sender->messages[0]['reply_to']);
    }

    public function test_invalid_envelope_types_are_reported_as_invalid_event(): void
    {
        [$server, $sender] = $this->newServer();

        $this->send($server, 1, ['id' => 7, 'type' => 'PING', 'timestamp' => $this->now()]);
        $this->send($server, 1, ['id' => 'bad-payload', 'type' => 'START', 'timestamp' => $this->now(), 'payload' => 'invalid']);

        self::assertSame('event_invalid', $sender->messages[0]['payload']['code']);
        self::assertNull($sender->messages[0]['reply_to']);
        self::assertSame('event_invalid', $sender->messages[1]['payload']['code']);
        self::assertSame('bad-payload', $sender->messages[1]['reply_to']);
    }

    public function test_unexpected_exception_has_safe_client_message(): void
    {
        [$server, $sender, $providers, $redis] = $this->newServer();
        $game = GameVoFixture::headsUp();
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers->extend('proto', static fn (): never => throw new RuntimeException('private provider details'));

        $this->send($server, 1, [
            'id' => 'action-1',
            'type' => 'REQUEST_ACTION',
            'timestamp' => $this->now(),
            'payload' => ['game_uuid' => $game->uuid],
        ]);

        self::assertSame('server_error', $sender->messages[0]['payload']['code']);
        self::assertSame('服务暂不可用', $sender->messages[0]['payload']['message']);
        self::assertSame('action-1', $sender->messages[0]['reply_to']);
    }

    public function test_game_exceptions_have_specific_translations(): void
    {
        self::assertSame('未找到牌局。', GameException::gameUuidNotFound('missing')->getLocaleMessage('zh-CN'));
        self::assertSame('Game not found.', GameException::gameUuidNotFound('missing')->getLocaleMessage('en'));
        self::assertSame('决策服务处理失败。', GameException::providerFailed('secret')->getLocaleMessage('zh-CN'));
    }

    /** @return array{GameServer, FakeGameServerSender, GameProviderManager, FakeProtoHttpRedis} */
    private function newServer(bool $connected = true): array
    {
        $sender = new FakeGameServerSender;
        $providers = new GameProviderManager;
        $redis = new FakeProtoHttpRedis;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, $redis),
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        if ($connected) {
            $connections = [1 => new GameServerConnectionVo(1, new User(['language' => 'zh-CN']), new UserToken)];
            (new \ReflectionProperty(GameServer::class, 'connections'))->setValue($server, $connections);
        }

        return [$server, $sender, $providers, $redis];
    }

    /** @param array<string, mixed> $message */
    private function send(GameServer $server, int $fd, array $message): void
    {
        $this->sendRaw($server, $fd, json_encode($message, JSON_THROW_ON_ERROR));
    }

    private function sendRaw(GameServer $server, int $fd, string $data): void
    {
        $frame = new Frame;
        $frame->fd = $fd;
        $frame->data = $data;
        $server->onMessage(null, $frame);
    }

    private function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
