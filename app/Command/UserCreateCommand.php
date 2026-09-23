<?php

namespace App\Command;

use App\Model\User;
use Hyperf\Command\Command;

final class UserCreateCommand extends Command
{
    protected ?string $signature = 'user:create {account} {--nickname=}';

    protected string $description = '创建账号';

    public function handle(): int
    {
        $account = $this->argument('account');
        if (! is_string($account) || empty($account)) {
            $this->error('请输入账号');

            return self::FAILURE;
        }
        if (strlen($account) <= 4 || strlen($account) > 20) {
            $this->error('账号长度必须是4-20个字符');

            return self::FAILURE;
        }
        $password = $this->secret('请输入密码 (最小6位)');
        if (! $password || strlen($password) < 6 || strlen($password) > 20) {
            $this->error('密码长度必须是6-20个字符');

            return self::FAILURE;
        }
        $nickname = $this->option('nickname');
        if (is_string($nickname) && ! empty($nickname) && strlen($nickname) > 20) {
            $this->error('昵称长度不能超过20个字符');

            return self::FAILURE;
        }
        User::query()->create([
            'account' => $account,
            'nickname' => $nickname ?? $account,
            'password' => $password,
        ]);
        $this->info('账号创建成功');

        return self::SUCCESS;
    }
}
