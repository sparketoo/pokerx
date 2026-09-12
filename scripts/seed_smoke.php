<?php

use App\Model\User;

require __DIR__.'/bootstrap.php';
if (! in_array(\Hyperf\Config\config('app_env'), ['dev', 'local', 'testing'], true)) {
    throw new RuntimeException('Local smoke only');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
\Hyperf\Coroutine\run(function () use ($input) {
    $u = User::query()->create(['account' => $input['account'], 'nickname' => 'Smoke', 'password' => $input['password'], 'is_vip' => $input['is_vip'] ?? false]);
    echo json_encode(['user_id' => $u->id])."\n";
});
