<?php

namespace App\Command;

use App\Model\CreditRecord;
use App\Model\User;
use App\Service\GameService;
use Hyperf\Command\Command;
use Hyperf\DbConnection\Db as DB;
use Hyperf\Stringable\Str;
use LogicException;
use RuntimeException;
use Throwable;

use function App\Support\di;

final class CreditGrantCommand extends Command
{
    protected ?string $signature = 'credit:grant {account} {points} {--id=} {--description=}';

    protected string $description = 'Grant points idempotently to an account';

    public function handle(): int
    {

        return self::SUCCESS;
    }
}
