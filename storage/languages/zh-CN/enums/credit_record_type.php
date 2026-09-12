<?php

use App\Enums\CreditRecordTypeEnum;

return [
    CreditRecordTypeEnum::GRANT->name => '发放',
    CreditRecordTypeEnum::CONSUME->name => '消费',
];
