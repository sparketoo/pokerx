<?php

namespace App\Command;

use App\Model\User;
use Hyperf\Command\Command;
use LogicException;

final class UserCreateCommand extends Command
{
    protected ?string $signature = 'user:create {account} {--nickname=}';

    protected string $description = 'Create an account with an interactive hidden password';

    public function handle(): int
    {
        $password = $this->secret('Password (at least 10 characters)');
        $account = strtolower(trim(($this->input ?? throw new LogicException('Command input unavailable'))->getArgument('account')));
        if (! $password || strlen($password) < 10 || strlen($account) > 64 || $account === '') {
            return self::FAILURE;
        }
        User::query()->create([
            'account' => $account,
            'nickname' => ($this->input ?? throw new LogicException('Command input unavailable'))->getOption('nickname') ?? $account,
            'password' => $password,
        ]);
        $this->info('Account created');

        return self::SUCCESS;
    }
}
