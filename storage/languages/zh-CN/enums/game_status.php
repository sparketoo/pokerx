<?php

use App\Enums\GameStatusEnum;

return [
    GameStatusEnum::OPEN->name => '进行中',
    GameStatusEnum::CLOSED->name => '已结束',
    GameStatusEnum::SETTLED->name => '已结算',
    GameStatusEnum::INCOMPLETE->name => '未完整结束',
];
