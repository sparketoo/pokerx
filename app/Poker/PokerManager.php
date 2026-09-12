<?php

declare(strict_types=1);

namespace App\Poker;

use App\Poker\Providers\MockProvider;
use App\Poker\Providers\ProtoProvider;
use App\Poker\Providers\ProviderFactory;
use App\Poker\Providers\ProviderInterface;
use Closure;
use InvalidArgumentException;

use function Hyperf\Config\config;

final class PokerManager
{
    /** @var array<string, ProviderFactory> */
    private array $drivers = [];

    /** @var array<string, Closure(): ProviderFactory> */
    private array $creators = [];

    public function getDefaultDriver(): string
    {
        return (string) config('poker.default', 'proto');
    }

    /** @param  Closure(): ProviderFactory  $factory */
    public function extend(string $name, Closure $factory): static
    {
        $this->creators[$name] = $factory;
        unset($this->drivers[$name]);

        return $this;
    }

    public function driver(?string $name = null): ProviderFactory
    {
        $name ??= $this->getDefaultDriver();

        if (isset($this->creators[$name])) {
            return $this->drivers[$name] ??= ($this->creators[$name])();
        }

        return $this->drivers[$name] ??= match ($name) {
            'proto' => new ProviderFactory(fn (?Closure $logger, array $options) => new ProtoProvider($options,
                $logger)),
            'mock' => new ProviderFactory(fn (
                ?Closure $logger,
                array $options
            ) => new MockProvider((int) ($options['delay_ms'] ?? 50), (bool) ($options['failure'] ?? false))),
            default => throw new InvalidArgumentException('Unknown poker provider: '.$name),
        };
    }

    public function forGame(?string $provider = null, ?Closure $logger = null): ProviderInterface
    {
        $name = $provider ?? $this->getDefaultDriver();

        return $this->driver(config('poker.'.$name.'.driver', $name))->create($logger, config('poker.'.$name, []));
    }
}
