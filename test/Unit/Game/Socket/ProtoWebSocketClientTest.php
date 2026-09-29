<?php

declare(strict_types=1);

namespace Tests\Unit\Game\Socket;

use App\Constants\ErrorCode;
use App\Exception\ProviderException;
use App\Game\Socket\ProtoWebSocketClient;
use Hyperf\Redis\Redis;
use ReflectionMethod;
use Swoole\Coroutine;
use Tests\TestCase;

final class ProtoWebSocketClientTest extends TestCase
{
    public function test_plain_timeout_is_reported_as_upstream_timeout(): void
    {
        $error = $this->parseFailure('timeout');

        self::assertSame(ErrorCode::PROVIDER_UNAVAILABLE, $error->getCode());
        self::assertSame(['reason' => 'upstream_timeout'], $error->context());
    }

    public function test_unknown_non_json_text_is_preserved_safely_in_diagnostics(): void
    {
        $error = $this->parseFailure('upstream said upstream-token');

        self::assertSame(ErrorCode::PROVIDER_FAILED, $error->getCode());
        self::assertSame('invalid_json', $error->context()['reason']);
        self::assertSame('upstream said [redacted]', $error->context()['remote_error']);
        self::assertNotNull($error->getPrevious());
    }

    private function parseFailure(string $text): ProviderException
    {
        $failure = null;
        Coroutine\run(function () use ($text, &$failure): void {
            $redis = new class extends Redis
            {
                public function __construct() {}
            };
            $client = new ProtoWebSocketClient('client-a', [
                'url' => 'ws://proto.test/',
                'token' => 'upstream-token',
            ], $redis);

            try {
                (new ReflectionMethod($client, 'handleText'))->invoke($client, $text);
            } catch (ProviderException $error) {
                $failure = $error;
            }
        });

        self::assertInstanceOf(ProviderException::class, $failure);

        return $failure;
    }
}
