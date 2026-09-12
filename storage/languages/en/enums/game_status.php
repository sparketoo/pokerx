<?php

use App\Enums\GameStatusEnum;

return [
    GameStatusEnum::OPEN->name => 'Open',
    GameStatusEnum::CLOSED->name => 'Closed',
    GameStatusEnum::SETTLED->name => 'Settled',
    GameStatusEnum::INCOMPLETE->name => 'Incomplete',
];
