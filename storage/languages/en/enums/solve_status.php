<?php

use App\Enums\SolveStatusEnum;

return [
    SolveStatusEnum::PENDING->name => 'Pending',
    SolveStatusEnum::SUCCEEDED->name => 'Succeeded',
    SolveStatusEnum::FAILED->name => 'Failed',
    SolveStatusEnum::TIMED_OUT->name => 'Timed Out',
    SolveStatusEnum::STALE->name => 'Stale',
];
