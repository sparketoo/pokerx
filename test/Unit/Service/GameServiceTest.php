<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Enum\GameEventTypeEnum;
use App\Exception\GameException;
use App\Game\GameProviderManager;
use App\Service\GameService;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameServiceTest extends TestCase
{
    public function test_concurrent_game_events_do_not_overwrite_insurance_purchase(): void
    {
        $game = GameVoFixture::headsUp();
        $game->event(GameEventTypeEnum::STAGE, ['stage' => 'FLOP', 'cards' => ['As', 'Kh', 'Qd']], 1);
        $redis = new class(serialize($game)) extends Redis
        {
            public int $conflicts = 0;

            public function __construct(public string $game) {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->game;
                }
                if ($name === 'eval') {
                    if ($this->conflicts++ === 0) {
                        $other = unserialize($this->game);
                        $other->event(GameEventTypeEnum::SHOW, ['uid' => 'villain', 'cards' => ['2c', '3d']], 2);
                        $this->game = serialize($other);

                        return 0;
                    }
                    if ($arguments[1][1] !== $this->game) {
                        return 0;
                    }
                    $this->game = $arguments[1][2];

                    return 1;
                }
                throw new \RuntimeException('Unexpected Redis command: '.$name);
            }
        };
        $container = ApplicationContext::getContainer();
        $service = new GameService(new GameProviderManager, $redis, $container->get(DriverFactory::class), $container->get(LoggerInterface::class));

        $service->purchaseInsurance($game->uuid, [
            'uid' => 'hero', 'stage' => 'FLOP', 'pot_id' => 1, 'odds' => '2.50', 'amount' => 40,
        ], 3, 1, 'client-a');

        $saved = unserialize($redis->game);
        self::assertSame(40, $saved->insuranceAmount());
        self::assertCount(3, $saved->events);
    }

    public function test_another_client_cannot_change_the_game(): void
    {
        $game = GameVoFixture::headsUp();
        $redis = new class(serialize($game)) extends Redis
        {
            public function __construct(public string $game) {}

            /** @param array<int, mixed> $arguments */
            public function __call(string $name, array $arguments): mixed
            {
                if ($name === 'get') {
                    return $this->game;
                }

                throw new \RuntimeException('Unexpected Redis command: '.$name);
            }
        };
        $container = ApplicationContext::getContainer();
        $service = new GameService(
            new GameProviderManager,
            $redis,
            $container->get(DriverFactory::class),
            $container->get(LoggerInterface::class),
        );

        self::assertSame($game->uuid, $service->findForClient($game->uuid, 1, 'client-a')->uuid);

        try {
            $service->event($game->uuid, GameEventTypeEnum::ABORT, [], 1, 1, 'client-b');
            self::fail('Another client must not change the game.');
        } catch (GameException) {
            self::assertSame(0, unserialize($redis->game)->events->count());
        }
    }
}
