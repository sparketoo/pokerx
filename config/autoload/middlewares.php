<?php

declare(strict_types=1);

use App\Middleware\Authenticate;
use App\Middleware\Locale;
use Hyperf\Validation\Middleware\ValidationMiddleware;

return ['http' => [Authenticate::class, Locale::class, ValidationMiddleware::class]];
