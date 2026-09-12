<?php

declare(strict_types=1);

use App\Middleware\Authenticate;
use Hyperf\Validation\Middleware\ValidationMiddleware;

return ['http' => [Authenticate::class, ValidationMiddleware::class]];
