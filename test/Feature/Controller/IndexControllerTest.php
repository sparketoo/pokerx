<?php

declare(strict_types=1);

namespace Tests\Feature\Controller;

use App\Controller\IndexController;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use Hyperf\HttpMessage\Server\Request as Psr7Request;
use Psr\Http\Message\ServerRequestInterface;
use Tests\TestCase;

final class IndexControllerTest extends TestCase
{
    public function test_index_uses_the_request_method_and_user_parameter(): void
    {
        $previous = RequestContext::getOrNull();
        RequestContext::set((new Psr7Request('GET', '/'))->withQueryParams(['user' => 'Alice']));
        Context::destroy('http.request.parsedData');

        try {
            $data = ApplicationContext::getContainer()->get(IndexController::class)->index();
            self::assertSame(['method' => 'GET', 'message' => 'Hello Alice.'], $data);
        } finally {
            Context::set(ServerRequestInterface::class, $previous);
            Context::destroy('http.request.parsedData');
        }
    }
}
