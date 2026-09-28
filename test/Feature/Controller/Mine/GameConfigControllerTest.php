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
            $this->call($controller, 'index', IndexRequest::class, ['network' => 'WPK']);
            self::fail('An unauthenticated user must not read game configuration');
        } catch (AuthException $error) {
            self::assertSame(2000, $error->getCode());
        }

        UserGameConfig::query()->create(['user_id' => 1, 'network' => NetworkEnum::WPK, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_8]);
        UserGameConfig::query()->create(['user_id' => 1, 'network' => NetworkEnum::WE, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_MAX]);
        UserGameConfig::query()->create(['user_id' => 2, 'network' => NetworkEnum::OK, 'key' => 'insurance_default', 'value' => InsuranceService::RATIO_1]);
        $this->signIn(1);

        $response = $this->call($controller, 'index', IndexRequest::class, ['network' => 'WPK']);

        self::assertSame(['code' => 0, 'message' => 'ok', 'data' => [
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
        self::assertSame(0, $saved['code']);
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

    public function test_sharing_switch_uses_one_for_enabled_and_null_for_disabled(): void
    {
        $this->signIn(1);
        $controller = $this->controller();

        $enabled = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK', 'items' => [['key' => 'share_hole_cards', 'value' => '1']],
        ], 'POST');
        self::assertSame([['key' => 'share_hole_cards', 'value' => '1']], $enabled['data']['items']);

        $disabled = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK', 'items' => [['key' => 'share_hole_cards', 'value' => null]],
        ], 'POST');
        self::assertSame([], $disabled['data']['items']);

        foreach (['0', 'true', ''] as $value) {
            try {
                $this->call($controller, 'save', SaveRequest::class, [
                    'network' => 'OK', 'items' => [['key' => 'share_hole_cards', 'value' => $value]],
                ], 'POST');
                self::fail('Invalid sharing switch value must be rejected');
            } catch (ValidationException) {
                self::assertSame(0, UserGameConfig::query()->where('user_id', 1)->where('network', 'OK')->count());
            }
        }
    }

    public function test_provider_mode_can_be_saved_read_and_cleared_per_network(): void
    {
        $this->signIn(1);
        $controller = $this->controller();

        foreach (['proto', 'idel', 'mock'] as $provider) {
            $saved = $this->call($controller, 'save', SaveRequest::class, [
                'network' => 'OK', 'items' => [['key' => 'provider', 'value' => $provider]],
            ], 'POST');
            self::assertSame([['key' => 'provider', 'value' => $provider]], $saved['data']['items']);
        }

        $read = $this->call($controller, 'index', IndexRequest::class, ['network' => 'OK']);
        self::assertSame([['key' => 'provider', 'value' => 'mock']], $read['data']['items']);
        self::assertSame([], $this->call($controller, 'index', IndexRequest::class, ['network' => 'WE'])['data']['items']);

        foreach (['', 'PROTO', 'unknown', '1'] as $provider) {
            try {
                $this->call($controller, 'save', SaveRequest::class, [
                    'network' => 'OK', 'items' => [['key' => 'provider', 'value' => $provider]],
                ], 'POST');
                self::fail('Invalid provider mode must be rejected');
            } catch (ValidationException) {
                self::assertSame('mock', UserGameConfig::query()->where('user_id', 1)->where('network', 'OK')->where('key', 'provider')->firstOrFail()->value);
            }
        }

        $cleared = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK', 'items' => [['key' => 'provider', 'value' => null]],
        ], 'POST');
        self::assertSame([], $cleared['data']['items']);
    }

    public function test_save_accepts_auto_bet_ranges_and_rejects_invalid_seconds(): void
    {
        $this->signIn(1);
        $controller = $this->controller();
        $items = [
            ['key' => 'auto_bet_check_fold', 'value' => '0-1'],
            ['key' => 'auto_bet_bet_raise', 'value' => '4-8'],
            ['key' => 'auto_bet_call_all_in', 'value' => '2-3'],
            ['key' => 'auto_bet_fold', 'value' => '1-2'],
            ['key' => 'auto_bet_check', 'value' => '2-3'],
            ['key' => 'auto_bet_call', 'value' => '3-4'],
            ['key' => 'auto_bet_bet', 'value' => '4-5'],
            ['key' => 'auto_bet_raise', 'value' => '5-6'],
            ['key' => 'auto_bet_all_in', 'value' => '6-7'],
            ['key' => 'auto_bet_insurance', 'value' => '6-10'],
        ];
        $saved = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK', 'items' => $items,
        ], 'POST');
        self::assertSame(0, $saved['code']);
        self::assertSame('ok', $saved['message']);
        self::assertSame(10, count($saved['data']['items']));

        $legacyUpdate = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK',
            'items' => [['key' => 'auto_bet_check_fold', 'value' => '7-9']],
        ], 'POST');
        $values = array_column($legacyUpdate['data']['items'], 'value', 'key');
        self::assertSame('7-9', $values['auto_bet_check_fold']);
        self::assertSame('1-2', $values['auto_bet_fold']);
        self::assertSame('2-3', $values['auto_bet_check']);

        $read = $this->call($controller, 'index', IndexRequest::class, ['network' => 'OK']);
        self::assertSame($legacyUpdate['data']['items'], $read['data']['items']);

        foreach ([
            ['key' => 'auto_bet_insurance', 'value' => '8-11'],
            ['key' => 'auto_bet_raise', 'value' => '5-3'],
            ['key' => 'auto_bet_all_in', 'value' => '0.5-2'],
        ] as $item) {
            try {
                $this->call($controller, 'save', SaveRequest::class, [
                    'network' => 'OK',
                    'items' => [$item],
                ], 'POST');
                self::fail('Invalid delay range must be rejected');
            } catch (ValidationException) {
                self::assertSame(10, UserGameConfig::query()->where('user_id', 1)->where('network', 'OK')->count());
            }
        }
    }

    public function test_save_accepts_all_legacy_and_individual_settings_in_one_batch(): void
    {
        $this->signIn(1);
        $controller = $this->controller();
        $autoKeys = [
            'auto_bet_check_fold',
            'auto_bet_bet_raise',
            'auto_bet_call_all_in',
            'auto_bet_fold',
            'auto_bet_check',
            'auto_bet_call',
            'auto_bet_bet',
            'auto_bet_raise',
            'auto_bet_all_in',
            'auto_bet_insurance',
        ];
        $items = array_map(static fn (string $key): array => ['key' => $key, 'value' => '2-3'], $autoKeys);
        $items[] = ['key' => 'insurance_default', 'value' => InsuranceService::RATIO_MIN];
        foreach (range(1, 8) as $outs) {
            $items[] = ['key' => 'insurance_outs_'.$outs, 'value' => InsuranceService::RATIO_MIN];
        }
        $items[] = ['key' => 'share_hole_cards', 'value' => '1'];

        $response = $this->call($controller, 'save', SaveRequest::class, [
            'network' => 'OK',
            'items' => $items,
        ], 'POST');

        self::assertSame(0, $response['code']);
        self::assertCount(20, $response['data']['items']);
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
