<?php

use App\Enums\UserStatusEnum;

return [
    UserStatusEnum::NORMAL->name => '正常',
    UserStatusEnum::FROZEN->name => '冻结',
    UserStatusEnum::DISABLED->name => '禁用',
];
