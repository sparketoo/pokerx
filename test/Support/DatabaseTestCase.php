<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\User;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use Hyperf\Context\ResponseContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpServer\Request;
use Hyperf\Validation\Request\FormRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    private ?ServerRequestInterface $previousRequest;

    private ?ResponseInterface $previousResponse;

    private mixed $previousUser;

    protected function setUp(): void
    {
        parent::setUp();

        $container = ApplicationContext::getContainer();
        $config = $container->get(ConfigInterface::class);
        self::assertSame('pokerx_hyperf_test', $config->get('databases.default.database'));
        $this->previousRequest = RequestContext::getOrNull();
        $this->previousResponse = ResponseContext::getOrNull();
        $this->previousUser = Context::get(User::class);

        Db::statement("SET time_zone = '+00:00'");
        Db::statement("CREATE TEMPORARY TABLE users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            account VARCHAR(64) NOT NULL UNIQUE,
            nickname VARCHAR(80) NOT NULL,
            password VARCHAR(255) NOT NULL,
            status ENUM('NORMAL','FROZEN','DISABLED') NOT NULL DEFAULT 'NORMAL',
            language VARCHAR(10) NOT NULL DEFAULT 'zh-CN',
            two_factor_secret TEXT NULL,
            created_at TIMESTAMP(6) NULL,
            updated_at TIMESTAMP(6) NULL
        )");
        Db::statement('CREATE TEMPORARY TABLE user_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            name TEXT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            abilities TEXT NULL,
            last_used_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL
        )');
        Db::statement("CREATE TEMPORARY TABLE games (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            user_id BIGINT UNSIGNED NOT NULL,
            network ENUM('OK','WE','WPK','WPK_CLUB') NOT NULL,
            game_key VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
            provider VARCHAR(32) NOT NULL,
            players TINYINT UNSIGNED NOT NULL,
            status ENUM('OPEN','ABORT','CLOSED','OVER') NOT NULL,
            big_blind BIGINT UNSIGNED NOT NULL,
            small_blind BIGINT UNSIGNED NOT NULL,
            ante BIGINT UNSIGNED NOT NULL,
            pot BIGINT UNSIGNED NOT NULL,
            total BIGINT UNSIGNED NOT NULL,
            winnings BIGINT UNSIGNED NOT NULL,
            profit BIGINT NOT NULL,
            created_at TIMESTAMP(6) NULL,
            updated_at TIMESTAMP(6) NULL,
            UNIQUE KEY games_user_network_game_key_unique (user_id, network, game_key)
        )");
        Db::statement('CREATE TEMPORARY TABLE game_players (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            game_id BIGINT UNSIGNED NOT NULL,
            seat TINYINT UNSIGNED NOT NULL,
            uid VARCHAR(64) NOT NULL,
            name VARCHAR(64) NOT NULL,
            is_hero BOOLEAN NOT NULL,
            stack BIGINT UNSIGNED NOT NULL,
            ante BIGINT UNSIGNED NOT NULL,
            blind BIGINT UNSIGNED NOT NULL DEFAULT 0,
            returned BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bet BIGINT UNSIGNED NOT NULL,
            total BIGINT UNSIGNED NOT NULL,
            cards VARCHAR(255) NULL,
            created_at TIMESTAMP(6) NULL,
            updated_at TIMESTAMP(6) NULL
        )');
        Db::statement("CREATE TEMPORARY TABLE game_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            game_id BIGINT UNSIGNED NOT NULL,
            type ENUM('START','BLIND_POSTED','STAGE','DEALT','ACTION','SHOW','ABORT','OVER') NOT NULL,
            timestamp BIGINT UNSIGNED NOT NULL,
            payload JSON NOT NULL,
            created_at TIMESTAMP(6) NULL,
            updated_at TIMESTAMP(6) NULL
        )");
    }

    protected function tearDown(): void
    {
        foreach (['game_events', 'game_players', 'games', 'user_tokens', 'users'] as $table) {
            Db::statement('DROP TEMPORARY TABLE IF EXISTS '.$table);
        }
        Context::set(User::class, $this->previousUser);
        Context::set(ServerRequestInterface::class, $this->previousRequest);
        Context::set(ResponseInterface::class, $this->previousResponse);
        Context::destroy('http.request.parsedData');
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    protected function request(string $requestClass, array $input = [], string $method = 'GET', array $headers = []): object
    {
        $psrRequest = new Psr7Request($method, '/', $headers);
        $psrRequest = $method === 'GET'
            ? $psrRequest->withQueryParams($input)
            : $psrRequest->withParsedBody($input);
        RequestContext::set($psrRequest);
        ResponseContext::set(new Psr7Response);
        Context::destroy('http.request.parsedData');

        $request = new $requestClass(ApplicationContext::getContainer());
        Context::destroy(spl_object_hash($request).':'.ValidatorInterface::class);
        if ($request instanceof FormRequest) {
            $request->validateResolved();
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    protected function call(object $controller, string $action, string $requestClass = Request::class, array $input = [], string $method = 'GET', array $headers = [], mixed ...$services): array
    {
        $request = $this->request($requestClass, $input, $method, $headers);
        $response = $controller->{$action}($request, ...$services);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        Assert::assertSame(0, $body['code']);

        return $body['data'] ?? [];
    }

    protected function signIn(User $user): void
    {
        Context::set(User::class, $user);
    }

    protected function user(string $account = 'alice@example.test', string $password = 'original-password', string $status = 'NORMAL'): User
    {
        $id = Db::table('users')->insertGetId([
            'account' => $account,
            'nickname' => 'Alice',
            'password' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => $status,
            'language' => 'zh-CN',
            'created_at' => '2026-09-23 00:00:00',
            'updated_at' => '2026-09-23 00:00:00',
        ]);

        return User::query()->findOrFail($id);
    }

    protected function token(User $user, string $secret = 'test-secret'): string
    {
        $id = Db::table('user_tokens')->insertGetId([
            'user_id' => $user->id,
            'name' => 'test',
            'token' => hash('sha256', $secret),
            'abilities' => '["*"]',
            'expires_at' => '2027-09-23 00:00:00',
        ]);

        return $id.'|'.$secret;
    }

    protected function game(User $user, string $uuid, string $status, int $total, int $winnings, string $createdAt): int
    {
        return Db::table('games')->insertGetId([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'network' => 'WE',
            'game_key' => str_replace('-', '', $uuid),
            'provider' => 'mock',
            'players' => 2,
            'status' => $status,
            'big_blind' => 100,
            'small_blind' => 50,
            'ante' => 0,
            'pot' => 1000,
            'total' => $total,
            'winnings' => $winnings,
            'profit' => $winnings - $total,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @param array<string, mixed> $payload */
    protected function event(User $user, int $gameId, string $type, array $payload, string $createdAt): int
    {
        return Db::table('game_events')->insertGetId([
            'user_id' => $user->id,
            'game_id' => $gameId,
            'type' => $type,
            'timestamp' => 1,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
