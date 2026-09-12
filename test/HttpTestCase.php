<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 *
 * @document https://hyperf.wiki
 *
 * @contact  group@hyperf.io
 *
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Tests;

use Hyperf\Testing\Client;
use PHPUnit\Framework\TestCase;

use function Hyperf\Support\make;

/**
 * Class HttpTestCase.
 *
 * @method get($uri, $data = [], $headers = [])
 * @method post($uri, $data = [], $headers = [])
 * @method json($uri, $data = [], $headers = [])
 * @method file($uri, $data = [], $headers = [])
 * @method request($method, $path, $options = [])
 */
abstract class HttpTestCase extends TestCase
{
    /**
     * @var Client
     */
    protected $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = make(Client::class);
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->client->{$name}(...$arguments);
    }
}
