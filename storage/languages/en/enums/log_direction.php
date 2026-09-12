<?php

use App\Enums\LogDirectionEnum;

return [
    LogDirectionEnum::CLIENT_IN->name => 'Client In',
    LogDirectionEnum::CLIENT_OUT->name => 'Client Out',
    LogDirectionEnum::PROVIDER_OUT->name => 'Provider Out',
    LogDirectionEnum::PROVIDER_IN->name => 'Provider In',
    LogDirectionEnum::INTERNAL->name => 'Internal',
];
