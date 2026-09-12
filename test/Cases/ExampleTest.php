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

namespace Tests\Cases;

use Hyperf\Testing\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class ExampleTest extends TestCase
{
    public function test_example(): void
    {
        \Tests\run(function (): void {
            $this->get('/health')->assertOk()->assertJson(['status' => 'ok']);
        });
    }
}
