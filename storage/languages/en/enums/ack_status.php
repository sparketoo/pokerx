<?php

use App\Enums\AckStatusEnum;

return [
    AckStatusEnum::ACCEPTED->name => 'Accepted',
    AckStatusEnum::DUPLICATE->name => 'Duplicate',
];
