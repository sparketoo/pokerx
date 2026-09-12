<?php

use App\Enums\LogDirectionEnum;

return [
    LogDirectionEnum::CLIENT_IN->name => 'CLIENT_IN',
    LogDirectionEnum::CLIENT_OUT->name => 'CLIENT_OUT',
    LogDirectionEnum::PROVIDER_OUT->name => 'PROVIDER_OUT',
    LogDirectionEnum::PROVIDER_IN->name => 'PROVIDER_IN',
    LogDirectionEnum::INTERNAL->name => 'INTERNAL',
];
