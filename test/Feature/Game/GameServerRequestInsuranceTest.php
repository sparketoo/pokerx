<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Game\GameProviderManager;
use App\Game\GameServer;
use App\Model\User;
use App\Model\UserToken;
use App\Service\GameService;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use App\Service\UserTokenService;
use App\Vo\Game\GameServerConnectionVo;
use Hyperf\Cache\Cache;
use Hyperf\Context\ApplicationContext;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Swoole\WebSocket\Frame;
use Tests\Fixtures\FakeProtoHttpRedis;
use Tests\Fixtures\GameVoFixture;
use Tests\TestCase;

final class GameServerRequestInsuranceTest extends TestCase
{
    public function test_returns_fractional_premium_in_a_correlated_ack(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_outs_2' => InsuranceService::RATIO_2],
            $this->quote(['pot' => 299, 'odds' => 16.25]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame('insurance-1', $reply['reply_to']);
        self::assertSame(['amount' => 9], $reply['payload']);
    }

    public function test_uses_quoted_breakeven_amount(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_1],
            $this->quote(['breakeven' => 7]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 7], $reply['payload']);
    }

    public function test_accepts_numeric_string_odds_from_a_quote(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_2],
            $this->quote(['odds' => '16.25']),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 9], $reply['payload']);
    }

    public function test_uses_the_minimum_without_an_insurance_configuration(): void
    {
        $reply = $this->requestInsurance(['unrelated' => 'value'], $this->quote(['min' => 3]));

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type'], json_encode($reply, JSON_THROW_ON_ERROR));
        self::assertSame(['amount' => 3], $reply['payload']);
    }

    public function test_min_returns_zero_as_a_purchase_amount(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MIN],
            $this->quote(['min' => 0]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type']);
        self::assertSame(['amount' => 0], $reply['payload']);
    }

    public function test_invalid_quote_bounds_return_null_as_no_valid_decision(): void
    {
        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MIN],
            $this->quote(['min' => 3, 'max' => 2]),
        );

        self::assertSame('REQUEST_INSURANCE.ACK', $reply['type']);
        self::assertSame(['amount' => null], $reply['payload']);
    }

    public function test_rejects_an_invalid_quote_with_field_details(): void
    {
        $quote = $this->quote();
        unset($quote['breakeven']);

        $reply = $this->requestInsurance(
            ['insurance_default' => InsuranceService::RATIO_MAX],
            $quote,
        );

        self::assertSame('error', $reply['type']);
        self::assertSame('insurance-1', $reply['reply_to']);
        self::assertSame('event_invalid', $reply['payload']['code']);
        self::assertArrayHasKey('breakeven', $reply['payload']['details']);
    }

    /** @param array<string, int|float|string> $overrides
     * @return array<string, int|float|string>
     */
    private function quote(array $overrides = []): array
    {
        return array_replace([
            'game_uuid' => '11111111-1111-4111-8111-000000000001',
            'stage' => 'FLOP',
            'outs' => 2,
            'pot' => 299,
            'odds' => 16.25,
            'min' => 0,
            'max' => 18,
            'breakeven' => 9,
        ], $overrides);
    }

    /** @param array<string, string> $config
     * @param  array<string, int|float|string>  $quote
     * @return array<string, mixed>
     */
    private function requestInsurance(array $config, array $quote): array
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
        $cache = new class($config) extends Cache
        {
            /** @param array<string, string> $config */
            public function __construct(private readonly array $config) {}

            public function get($key, $default = null): mixed
            {
                return $this->config;
            }
        };
        $game = GameVoFixture::headsUp();
        $redis = new FakeProtoHttpRedis;
        $redis->setex('game:'.$game->uuid, 3600, serialize($game));
        $providers = new GameProviderManager;
        $container = ApplicationContext::getContainer();
        $server = new GameServer(
            $sender,
            $providers,
            new UserTokenService,
            new GameService($providers, $redis),
            new InsuranceService(new UserGameConfigService($cache)),
            $container->get(ValidatorFactoryInterface::class),
            $container->get(LoggerInterface::class),
        );
        $user = new User(['id' => $game->userId, 'language' => 'zh-CN']);
        (new ReflectionProperty(GameServer::class, 'connections'))->setValue($server, [
            1 => new GameServerConnectionVo(1, $user, new UserToken),
        ]);

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = json_encode([
            'id' => 'insurance-1',
            'type' => GameServer::TYPE_REQUEST_INSURANCE,
            'timestamp' => (int) floor(microtime(true) * 1000),
            'payload' => $quote,
        ], JSON_THROW_ON_ERROR);
        $server->onMessage(null, $frame);

        self::assertCount(1, $sender->messages);

        return $sender->messages[0];
    }
}
