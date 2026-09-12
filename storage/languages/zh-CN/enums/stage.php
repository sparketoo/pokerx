<?php

use App\Enums\StageEnum;

return [
    StageEnum::PREFLOP->name => '翻牌前',
    StageEnum::FLOP->name => '翻牌',
    StageEnum::TURN->name => '转牌',
    StageEnum::RIVER->name => '河牌',
    StageEnum::SHOWDOWN->name => '摊牌',
];
