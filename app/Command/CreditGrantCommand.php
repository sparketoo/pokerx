<?php

namespace App\Command;

use App\Model\User;
use App\Service\CreditService;
use Hyperf\Command\Command;
use Hyperf\Stringable\Str;
use LogicException;

use function App\Support\di;
use function Hyperf\Config\config;

final class CreditGrantCommand extends Command
{
    protected ?string $signature = 'credit:grant {account} {points} {--id=} {--description=}';

    protected string $description = 'Grant points idempotently to an account';

    public function handle(): int
    {
        $input = $this->input ?? throw new LogicException('Command input unavailable');
        $account = strtolower(trim((string) $input->getArgument('account')));
        $points = filter_var($input->getArgument('points'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $uuid = (string) $input->getOption('id');
        $description = $input->getOption('description');
        if ($account === '' || strlen($account) > 64 || $points === false || ! Str::isUuid($uuid)
            || ($description !== null && mb_strlen((string) $description) > 255)) {
            $this->error('account、正整数 points 与 UUID 格式的 --id 为必填；description 最多 255 字符。');

            return self::FAILURE;
        }
        /** @var User|null $user */
        $user = User::query()->where('account', $account)->first();
        if ($user === null) {
            $this->error('Account not found.');

            return self::FAILURE;
        }
        $units = $points * (int) config('poker.cost', 100);
        di(CreditService::class)->recharge($user, $units, $description ?: 'Manual credit grant', $uuid);
        $this->info("Granted {$points} points ({$units} units) to {$account}.");

        return self::SUCCESS;
    }
}
