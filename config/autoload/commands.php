<?php

use App\Command\UserCreateCommand;
use Hyperf\Database\Commands\CommandCollector;

return [
    UserCreateCommand::class,
    ...CommandCollector::getAllCommands(),
];
