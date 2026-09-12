<?php

use App\Enums\UserStatusEnum;

return [
    UserStatusEnum::NORMAL->name => 'Normal',
    UserStatusEnum::FROZEN->name => 'Frozen',
    UserStatusEnum::DISABLED->name => 'Disabled',
];
