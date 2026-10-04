<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Providers;

use App\Exception\ProviderException;
use App\Game\Providers\PokerAiHttpClient;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Tests\Fixtures\FakePokerAiClient;
use Tests\TestCase;

final class PokerAiHttpClientTest extends TestCase
{
    public function test_post_sends_bearer_auth_and_json_over_verified_https(): void
    {
        $client = $this->client([[200, ['strategy' => [['action' => 'fold', 'frequency' => 1]]]]]);
        $http = new PokerAiHttpClient(['url' => 'https://pokerai.bet', 'api_key' => 'test-key'],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client);

        $response = $http->post('/v1/gto/preflop', ['hole_cards' => 'AhKh']);

        self::assertSame('fold', $response['strategy'][0]['action']);
        self::assertSame('POST', $client->requests[0]['method']);
        self::assertSame('/v1/gto/preflop', $client->requests[0]['path']);
        self::assertSame(['Bearer test-key'], $client->requests[0]['headers']['Authorization']);
        self::assertSame(['application/json'], $client->requests[0]['headers']['Content-Type']);
        self::assertSame(['hole_cards' => 'AhKh'], json_decode($client->requests[0]['contents'], true));
        self::assertSame('pokerai.bet', $client->settings[0]['ssl_host_name']);
        self::assertTrue($client->settings[0]['ssl_verify_peer']);
    }

    public function test_quota_exhaustion_is_not_retried_for_either_documented_status(): void
    {
        foreach ([402, 429] as $status) {
            $client = $this->client([[$status, ['error' => 'quota_exceeded']]]);
            $http = new PokerAiHttpClient(['api_key' => 'test-key'],
                static fn (string $host, int $port, bool $ssl): ClientInterface => $client);
            try {
                $http->post('/v1/gto/preflop', []);
                self::fail('Quota exhaustion must fail');
            } catch (ProviderException $error) {
                self::assertSame('quota_exceeded', $error->context()['reason']);
                self::assertCount(1, $client->requests);
            }
        }
    }

    public function test_busy_solver_is_retried_once_with_a_bounded_delay(): void
    {
        $client = $this->client([
            [429, ['status' => 'busy']],
            [200, ['solve' => 'handle', 'status' => 'computing']],
        ]);
        $http = new PokerAiHttpClient(['api_key' => 'test-key', 'retry_delay' => 0.001],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client);

        $result = $http->post('/v1/gto/solver', ['board' => '2c2h2s']);

        self::assertSame('handle', $result['solve']);
        self::assertCount(2, $client->requests);
    }

    public function test_gateway_html_error_is_retried_before_decoding_json(): void
    {
        $client = $this->client([
            [502, '<html>Bad Gateway</html>'],
            [200, ['strategy' => [['action' => 'fold', 'frequency' => 1]]]],
        ]);
        $http = new PokerAiHttpClient(['api_key' => 'test-key', 'retry_delay' => 0.001],
            static fn (string $host, int $port, bool $ssl): ClientInterface => $client);

        $result = $http->post('/v1/gto/preflop', []);

        self::assertSame('fold', $result['strategy'][0]['action']);
        self::assertCount(2, $client->requests);
    }

    /**
     * @param  list<array{int, array<string, mixed>|string}>  $replies
     */
    private function client(array $replies): FakePokerAiClient
    {
        return new FakePokerAiClient($replies);
    }
}
