<?php

use App\Enums\ActionEnum;

return [
    ActionEnum::FOLD->name => '弃牌',
    ActionEnum::CHECK->name => '过牌',
    ActionEnum::CALL->name => '跟注',
    ActionEnum::BET->name => '下注',
    ActionEnum::RAISE->name => '加注',
    ActionEnum::ALL_IN->name => '全下',
];
