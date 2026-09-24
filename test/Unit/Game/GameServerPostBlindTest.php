<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Enum\NetworkEnum;
use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Game\Providers\MockProvider;
use App\Model\User;
use App\Model\UserToken;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use App\Vo\Game\GameVo;
use Hyperf\Context\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Log\LoggerInterface;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeGameServerSender;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\TestCase;

final class GameServerPostBlindTest extends TestCase
{
    public function test_post_blind_ack_validates_amount_and_records_another_player(): void
    {
        $sender = new FakeGameServerSender;
        $redis = new FakeProtoHttpRedis;
        $game = new GameVo(1, '12345678-90ab-4cde-8f01-23456789abce', NetworkEnum::OK, 'room#129', 2, 1, 2, [
            ['uid' => 'hero', 'seat' => 1, 'stack' => 190, 'hero' => true],
            ['uid' => 'small', 'seat' => 2, 'stack' => 190, 'hero' => false],
            ['uid' => 'big', 'seat' => 3, 'stack' => 190, 'hero' => false],
            ['uid' => 'other', 'seat' => 4, 'stack' => 190, 'hero' => false],
        ], 1);
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers = new GameProviderManager;
        $providers->extend($providers->getDefaultProvider(), static fn (): MockProvider => new MockProvider(1));
        $service = new GameService($providers, $redis);
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            $service,
            $container->get(InsuranceService::class),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        (new \ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, new User(['id' => 1, 'language' => 'zh-CN']), new UserToken),
        ]);

        $send = static function (string $id, array $payload, string $type = 'POST_BLIND') use ($server, $sender): array {
            $frame = new Frame;
            $frame->fd = 1;
            $frame->data = json_encode([
                'id' => $id,
                'type' => $type,
                'timestamp' => (int) floor(microtime(true) * 1000),
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR);
            $server->onMessage(null, $frame);

            return $sender->messages[count($sender->messages) - 1];
        };

        $payload = ['game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => 2];
        self::assertSame('event_invalid', $send('invalid-amount', [...$payload, 'amount' => '2'])['payload']['code']);
        self::assertSame('player_not_found', $send('unknown', [...$payload, 'uid' => 'absent'])['payload']['code']);
        $accepted = $send('valid', $payload);
        self::assertSame('POST_BLIND.ACK', $accepted['type']);
        self::assertSame('valid', $accepted['reply_to']);
        self::assertSame([], $accepted['payload']);
        self::assertSame(2, $service->find($game->uuid)->playerOrFail('other')->postBlind);
        self::assertSame('event_invalid', $send('duplicate', $payload)['payload']['code']);
        self::assertCount(1, $service->find($game->uuid)->events);
        self::assertSame('STAGE.ACK', $send('preflop', [
            'game_uuid' => $game->uuid, 'stage' => 'PREFLOP', 'cards' => [],
        ], 'STAGE')['type']);
        self::assertSame('event_invalid', $send('bad-straddle', [
            'game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => '4',
        ], 'STRADDLE_BLIND')['payload']['code']);
        $straddle = $send('valid-straddle', [
            'game_uuid' => $game->uuid, 'uid' => 'OTHER', 'amount' => 4,
        ], 'STRADDLE_BLIND');
        self::assertSame('STRADDLE_BLIND.ACK', $straddle['type']);
        self::assertSame('valid-straddle', $straddle['reply_to']);
        self::assertSame(4, $service->find($game->uuid)->playerOrFail('other')->straddleBlind);
    }
}
