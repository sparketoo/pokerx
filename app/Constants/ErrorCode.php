<?php

declare(strict_types=1);

namespace App\Constants;

final class ErrorCode
{
    public const int SUCCESS = 0;

    public const int BUSINESS_ERROR = 1000;

    public const int INVALID_INPUT = 1001;

    public const int NOT_FOUND = 1002;

    public const int RATE_LIMITED = 1003;

    public const int AUTH_REQUIRED = 2000;

    public const int AUTH_EXPIRED = 2001;

    public const int AUTH_FAILED = 2002;

    public const int TWO_FACTOR_REQUIRED = 2100;

    public const int TWO_FACTOR_INVALID = 2101;

    public const int SETUP_EXPIRED = 2102;

    public const int GAME_ALREADY_EXISTS = 3000;

    public const int GAME_NOT_FOUND = 3001;

    public const int EVENT_INVALID = 3002;

    public const int REQUEST_ACTION_IN_PROGRESS = 3003;

    public const int PROVIDER_UNAVAILABLE = 4000;

    public const int PROVIDER_FAILED = 4001;

    public const int SERVER_ERROR = 5000;
}
