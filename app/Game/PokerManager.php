<?php

declare(strict_types=1);

namespace App\Game;

use App\Game\Providers\MockProvider;
use App\Game\Providers\ProtoProvider;
use App\Game\Providers\ProviderInterface;
use Closure;
use Hyperf\Stringable\Str;
use RuntimeException;

use function Hyperf\Config\config;

final class PokerManager
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    /** @var array<string, Closure(array<string, mixed>): ProviderInterface> */
    private array $creators = [];

    public function getDefaultProvider(): string
    {
        return (string) config('poker.default', 'proto');
    }

    /** @param  Closure(): ProviderInterface  $factory */
    public function extend(string $name, Closure $factory): static
    {
        $this->creators[$name] = $factory;
        unset($this->providers[$name]);

        return $this;
    }

    public function provider(?string $name = null): ProviderInterface
    {
        $name = $name ?: $this->getDefaultProvider();

        if (! empty($this->providers[$name])) {
            return $this->providers[$name];
        }
        $config = config("poker.{$name}", []);
        if (isset($this->creators[$name])) {
            return $this->providers[$name] = $this->creators[$name]($config);
        }

        $method = 'create'.Str::studly($name).'Provider';
        if (! method_exists($this, $method)) {
            throw new RuntimeException("Provider [{$name}] is not defined.");
        }

        return $this->providers[$name] = $this->{$method}($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createProtoProvider(array $config = []): ProtoProvider
    {
        $provider = new ProtoProvider($config);
        $provider->connect();

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createMockProvider(array $config = []): MockProvider
    {
        return new MockProvider($config['delay_ms'] ?? 50, (bool) $config['failure']);
    }
}
