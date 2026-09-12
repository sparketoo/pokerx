<?php

use App\Enums\ActionEnum;

return [
    ActionEnum::FOLD->name => 'Fold',
    ActionEnum::CHECK->name => 'Check',
    ActionEnum::CALL->name => 'Call',
    ActionEnum::BET->name => 'Bet',
    ActionEnum::RAISE->name => 'Raise',
    ActionEnum::ALL_IN->name => 'All In',
];
