<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hyperf\AsyncQueue\Driver\RedisDriver;
use Hyperf\AsyncQueue\JobInterface;

final class FakeGameQueueDriver extends RedisDriver
{
    /** @var list<array{job: JobInterface, delay: int}> */
    public array $pushed = [];

    public function __construct() {}

    public function push(JobInterface $job, int $delay = 0): bool
    {
        $this->pushed[] = ['job' => $job, 'delay' => $delay];

        return true;
    }
}
