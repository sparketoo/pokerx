<?php

declare(strict_types=1);

namespace App\Game;

use App\Game\Providers\IdelProvider;
use App\Game\Providers\MockProvider;
use App\Game\Providers\ProtoHttpProvider;
use App\Game\Providers\ProtoProvider;
use App\Game\Providers\ProviderInterface;
use Closure;
use Hyperf\Redis\Redis;
use Hyperf\Stringable\Str;
use RuntimeException;

use function App\Support\di;
use function Hyperf\Config\config;

final class GameProviderManager
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

    /** @param  array<string, mixed>  $config */
    public function createProtoHttpProvider(array $config = []): ProtoHttpProvider
    {

        return new ProtoHttpProvider($config);
    }

    /** @param  array<string, mixed>  $config */
    public function createProtoProvider(array $config = []): ProtoProvider
    {
        return new ProtoProvider($config, di(Redis::class));
    }

    /** @param  array<string, mixed>  $config */
    public function createIdelProvider(array $config = []): IdelProvider
    {
        return new IdelProvider;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function createMockProvider(array $config = []): MockProvider
    {
        return new MockProvider((int) ($config['delay_ms'] ?? 50), (bool) ($config['failure'] ?? false));
    }
}
