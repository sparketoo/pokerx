<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Contract\Http\RawResponseInterface;
use Hyperf\Engine\Http\RawResponse;
use RuntimeException;

/** Real HTTP transport for a loopback Proto peer without requiring a Swoole coroutine. */
final class ProtoHttpLoopbackClient implements ClientInterface
{
    /** @var array<string, mixed> */
    private array $settings = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly bool $ssl,
    ) {}

    /** @param array<string, mixed> $settings */
    public function set(array $settings): bool
    {
        $this->settings = $settings;

        return true;
    }

    /** @param array<string, list<string>> $headers */
    public function request(
        string $method = 'GET',
        string $path = '/',
        array $headers = [],
        string $contents = '',
        string $version = '1.1',
    ): RawResponseInterface {
        if ($method === '') {
            throw new RuntimeException('Loopback HTTP request method must not be empty.');
        }
        $handle = curl_init(($this->ssl ? 'https' : 'http').'://'.$this->host.':'.$this->port.$path);
        if ($handle === false) {
            throw new RuntimeException('Could not create loopback HTTP request.');
        }
        $flatHeaders = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $flatHeaders[] = $name.': '.$value;
            }
        }
        if (! curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $flatHeaders,
            CURLOPT_POSTFIELDS => $contents,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->settings['timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => (int) ($this->settings['connect_timeout'] ?? 5),
        ])) {
            curl_close($handle);
            throw new RuntimeException('Could not configure the loopback HTTP request.');
        }
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (! is_string($body)) {
            throw new RuntimeException('Loopback HTTP request failed: '.$error);
        }

        return new RawResponse($status, [], $body, $version);
    }
}
