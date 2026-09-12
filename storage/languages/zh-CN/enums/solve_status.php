<?php

use App\Enums\SolveStatusEnum;

return [
    SolveStatusEnum::PENDING->name => '等待中',
    SolveStatusEnum::SUCCEEDED->name => '成功',
    SolveStatusEnum::FAILED->name => '失败',
    SolveStatusEnum::TIMED_OUT->name => '超时',
    SolveStatusEnum::STALE->name => '已失效',
];
