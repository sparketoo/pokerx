<?php

declare(strict_types=1);

namespace App\Factory;

use Hyperf\Logger\LoggerFactory as Factory;
use Psr\Log\LoggerInterface;

final readonly class LoggerFactory
{
    public function __construct(private Factory $factory) {}

    public function __invoke(): LoggerInterface
    {
        return $this->factory->get('poker');
    }
}
