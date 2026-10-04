<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Contract\Http\RawResponseInterface;
use Hyperf\Engine\Http\RawResponse;
use RuntimeException;

final class FakePokerAiClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,headers:array<string, list<string>>,contents:string}> */
    public array $requests = [];

    /** @var list<array<string, mixed>> */
    public array $settings = [];

    /** @param list<array{0:int, 1:array<string, mixed>|string}> $replies */
    public function __construct(private array $replies) {}

    /** @param array<string, mixed> $settings */
    public function set(array $settings): bool
    {
        $this->settings[] = $settings;

        return true;
    }

    /** @param array<string, list<string>> $headers */
    public function request(string $method = 'GET', string $path = '/', array $headers = [],
        string $contents = '', string $version = '1.1'): RawResponseInterface
    {
        $this->requests[] = compact('method', 'path', 'headers', 'contents');
        $reply = array_shift($this->replies);
        if ($reply === null) {
            throw new RuntimeException('Unexpected PokerAI request');
        }
        [$status, $body] = $reply;

        return new RawResponse($status, [], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR), $version);
    }
}
