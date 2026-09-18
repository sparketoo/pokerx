<?php

use App\Command\CreditGrantCommand;
use App\Command\UserCreateCommand;
use Hyperf\Database\Commands\CommandCollector;

return [
    CreditGrantCommand::class,
    UserCreateCommand::class,
    ...CommandCollector::getAllCommands(),
];
