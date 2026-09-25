<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;

final class IsolatedDatabaseConnectionResolver implements ConnectionResolverInterface
{
    public function __construct(
        private readonly ConnectionResolverInterface $resolver,
        private string $defaultConnection,
    ) {}

    public function connection(?string $name = null): ConnectionInterface
    {
        return $this->resolver->connection($name === null || $name === 'default' ? $this->defaultConnection : $name);
    }

    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }
}
