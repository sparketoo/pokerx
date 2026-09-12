<?php

use App\Enums\AckStatusEnum;

return [
    AckStatusEnum::ACCEPTED->name => '已接收',
    AckStatusEnum::DUPLICATE->name => '重复接收',
];
