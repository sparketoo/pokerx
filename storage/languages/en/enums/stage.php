<?php

use App\Enums\StageEnum;

return [
    StageEnum::PREFLOP->name => 'Preflop',
    StageEnum::FLOP->name => 'Flop',
    StageEnum::TURN->name => 'Turn',
    StageEnum::RIVER->name => 'River',
    StageEnum::SHOWDOWN->name => 'Showdown',
];
