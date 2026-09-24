<?php

declare(strict_types=1);

namespace Tests\Feature\Controller\Mine;

use App\Controller\Mine\GameConfigController;
use App\Enum\NetworkEnum;
use App\Exception\AuthException;
use App\Model\User;
use App\Model\UserGameConfig;
use App\Request\Mine\GameConfig\IndexRequest;
use App\Request\Mine\GameConfig\SaveRequest;
use App\Service\InsuranceService;
use App\Service\UserGameConfigService;
use Hyperf\Cache\Cache;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use Hyperf\Context\ResponseContext;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Database\Connection;
use Hyperf\Database\ConnectionResolver;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Container;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\Validation\Request\FormRequest;
use Hyperf\Validation\ValidationException;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

final class GameConfigControllerTest extends TestCase
{
    private Container $container;

    private ConnectionResolverInterface $previousResolver;

    private ?ServerRequestInterface $previousRequest;

    private ?ResponseInterface $previousResponse;

    private mixed $previousUser;

    protected function setUp(): void
    {
        parent::setUp();

        $container = ApplicationContext::getContainer();
        self::assertInstanceOf(Container::class, $container);
        $this->container = $container;
        $this->previousResolver = $container->get(ConnectionResolverInterface::class);
        $this->previousRequest = RequestContext::getOrNull();
        $this->previousResponse = ResponseContext::getOrNull();
        $this->previousUser = Context::get(User::class);

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE user_game_config (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, network TEXT NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL, UNIQUE(user_id, network, key))');
        $container->set(ConnectionResolverInterface::class, new ConnectionResolver(['default' => new Connection($pdo)]));
        Db::table('users')->insert([['id' => 1], ['id' => 2]]);
        Context::set(User::class, null);
    }

    protected function tearDown(): void
    {
        $this->container->set(ConnectionResolverInterface::class, $this->previousResolver);
        Context::set(User::class, $this->previousUser);
        Context::set(ServerRequestInterface::class, $this->previousRequest);
        Context::set(ResponseInterface::class, $this->previousResponse);
        Context::destroy('http.request.parsedData');

        parent::tearDown();
    }

    public function test_index_requires_a_user_and_returns_only_the_selected_network(): void
    {
        $controller = $this->controller();
        try {
            $this->call($controller, 'index', IndexRequest::class, ['network' => 'WPK_CLUB']);
            self::fail('An unauthenticated user must not read game configuration');
        } catch (AuthException $error) {
            self::assertSame('auth_required', $error->getErrorCode());
        }

        UserGameConfig::query()->create(['user_id' => 1, 'network' => NetworkEnum::WPK_CLUB, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_8]);
        UserGameConfig::query()->create(['user_id' => 1, 'network' => NetworkEnum::WE, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX]);
        UserGameConfig::query()->create(['user_id' => 2, 'network' => NetworkEnum::WPK_CLUB, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_1]);
        $this->signIn(1);

        $response = $this->call($controller, 'index', IndexRequest::class, ['network' => 'WPK_CLUB']);

        self::assertSame(['code' => 'success', 'message' => 'ok', 'data' => [
            'items' => [['key' => 'insurance_default', 'value' => InsuranceService::RATIO_8]],
        ]], $response);
    }

    public function test_save_updates_only_submitted_keys_and_null_removes_a_setting(): void
    {
        $this->signIn(1);
        $controller = $this->controller();

        $saved = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK',
            'items' => [
                ['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX],
                ['key' => 'insurance_outs_2', 'value' => InsuranceService::RATIO_8],
            ],
        ], 'POST');
        self::assertSame('success', $saved['code']);
        self::assertSame('ok', $saved['message']);
        self::assertSame([
            ['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX],
            ['key' => 'insurance_outs_2', 'value' => InsuranceService::RATIO_8],
        ], $saved['data']['items']);

        $updated = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK',
            'items' => [
                ['key' => 'insurance_outs_2', 'value' => null],
                ['key' => 'insurance_outs_1', 'value' => InsuranceService::RATIO_MIN],
            ],
        ], 'POST');
        self::assertSame([
            ['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX],
            ['key' => 'insurance_outs_1', 'value' => InsuranceService::RATIO_MIN],
        ], $updated['data']['items']);
        self::assertSame(2, UserGameConfig::query()->where('user_id', 1)->where('network', 'OK')->count());
    }

    public function test_save_accepts_every_insurance_ratio_constant(): void
    {
        $this->signIn(1);
        $controller = $this->controller();
        $values = [
            InsuranceService::RATIO_MIN,
            InsuranceService::RATIO_MAX,
            InsuranceService::RATIO_1,
            InsuranceService::RATIO_2,
            InsuranceService::RATIO_3,
            InsuranceService::RATIO_5,
            InsuranceService::RATIO_8,
        ];

        foreach ($values as $value) {
            $response = $this->call($controller, 'save', SaveRequest::class, [
                'network' => 'OK',
                'items' => [['key' => 'insurance_default', 'value' => $value]],
            ], 'POST');
            self::assertSame($value, $response['data']['items'][0]['value']);
        }
    }

    public function test_invalid_batch_does_not_change_existing_configuration(): void
    {
        $this->signIn(1);
        $controller = $this->controller();
        $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'WE',
            'items' => [['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX]],
        ], 'POST');

        foreach ([
            [],
            [['key' => 'insurance_default', 'value' => InsuranceService::RATIO_1], ['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MIN]],
            [['key' => 'insurance_outs_9', 'value' => InsuranceService::RATIO_1]],
            [['key' => 'insurance_outs_2', 'value' => 'NONE']],
            [['key' => 'insurance_outs_2', 'value' => '0.125']],
            [['key' => 'insurance_outs_2', 'value' => 'full']],
            [['key' => 'insurance_outs_2', 'value' => '0']],
            [['key' => 'insurance_outs_2', 'value' => '0.0']],
            [['key' => 'insurance_outs_2', 'value' => 0.5]],
            [['key' => 'insurance_outs_2', 'value' => '']],
            [['key' => 'insurance_outs_2', 'value' => InsuranceService::RATIO_1, 'extra' => true]],
            [['key' => 'insurance_default', 'value' => null], ['key' => 'insurance_outs_3', 'value' => 'bad']],
        ] as $items) {
            try {
                $this->call($controller, 'save', SaveRequest::class, ['network' => 'WE', 'items' => $items], 'POST');
                self::fail('The invalid batch must be rejected');
            } catch (ValidationException) {
                self::assertSame(InsuranceService::RATIO_MAX, UserGameConfig::query()->where('user_id', 1)->where('network', 'WE')->where('key', 'insurance_default')->firstOrFail()->value);
                self::assertSame(1, UserGameConfig::query()->where('user_id', 1)->where('network', 'WE')->count());
            }
        }
    }

    public function test_network_is_required_and_uses_uppercase_enum_names(): void
    {
        $this->signIn(1);
        $controller = $this->controller();

        foreach ([[], ['network' => 'ok'], ['network' => 'unknown']] as $input) {
            try {
                $this->call($controller, 'index', IndexRequest::class, $input);
                self::fail('An unsupported network must be rejected');
            } catch (ValidationException) {
                self::assertSame(0, UserGameConfig::query()->count());
            }
        }
    }

    private function signIn(int $userId): void
    {
        Context::set(User::class, User::query()->findOrFail($userId));
    }

    private function controller(): GameConfigController
    {
        $cache = new class extends Cache
        {
            public function __construct() {}

            public function get($key, $default = null): mixed
            {
                return null;
            }

            public function set($key, $value, $ttl = null): bool
            {
                return true;
            }

            public function delete($key): bool
            {
                return true;
            }
        };

        return new GameConfigController(new UserGameConfigService($cache));
    }

    /**
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(GameConfigController $controller, string $action, string $requestClass, array $input, string $method = 'GET'): array
    {
        $request = new Psr7Request($method, '/api/mine/game_config');
        RequestContext::set($method === 'GET' ? $request->withQueryParams($input) : $request->withParsedBody($input));
        ResponseContext::set(new Psr7Response);
        Context::destroy('http.request.parsedData');

        $formRequest = new $requestClass($this->container);
        Context::destroy(spl_object_hash($formRequest).':'.ValidatorInterface::class);
        $formRequest->validateResolved();
        $response = $controller->{$action}($formRequest);
        self::assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
