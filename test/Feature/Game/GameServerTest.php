<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Game\GameServer;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Di\Container;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class GameServerTest extends TestCase
{
    public function test_on_open_disconnects_when_token_validation_throws(): void
    {
        /** @var Container $container */
        $container = ApplicationContext::getContainer();
        $previousResolver = $container->get(ConnectionResolverInterface::class);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willThrowException(new RuntimeException('Connection pool exhausted.'));
        $server = new class
        {
            /** @var list<int> */
            public array $disconnected = [];

            public function disconnect(int $fd): bool
            {
                $this->disconnected[] = $fd;

                return true;
            }
        };
        $request = (object) ['fd' => 42, 'get' => ['token' => '1|test-secret']];
        $error = null;

        try {
            $container->set(ConnectionResolverInterface::class, $resolver);
            try {
                $container->get(GameServer::class)->onOpen($server, $request);
            } catch (Throwable $caught) {
                $error = $caught;
            }
        } finally {
            $container->set(ConnectionResolverInterface::class, $previousResolver);
        }

        self::assertNull($error, 'Authentication exceptions must be handled by onOpen.');
        self::assertSame([42], $server->disconnected);
    }
}
