<?php

declare(strict_types=1);

namespace App\Process;

use App\Gateway\GameRuntime;
use App\Service\GameService;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Process\AbstractProcess;
use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;
use Throwable;

use function App\Support\di;
use function Hyperf\Config\config;

class PersistenceProcess extends AbstractProcess
{
    public string $name = 'poker-persistence';

    public function handle(): void
    {
        $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
        while (! $coordinator->isClosing()) {
            try {
                foreach (di(Redis::class)->smembers('users') as $id) {
                    $user = (int) $id;
                    try {
                        $state = json_decode(di(Redis::class)->get('{'.$user.'}:state') ?: 'null', true);
                        foreach ($state['games'] ?? [] as $uuid => $game) {
                            if ($game['context']['status'] === 'open' && $game['updated_at'] < time() - config('poker.incomplete_after')) {
                                di(GameRuntime::class)->incomplete($user, $uuid);
                            }
                        }
                        if (di(Redis::class)->exists('{'.$user.'}:pending_games') || di(Redis::class)->exists('{'.$user.'}:logs')) {
                            di(GameService::class)->flush($user);
                        }
                    } catch (Throwable $error) {
                        di(LoggerInterface::class)->warning('User persistence retry',
                            ['user_id' => $user, 'exception' => $error::class]);
                    }
                }
            } catch (Throwable $error) {
                di(LoggerInterface::class)->warning('Recovery retry', ['exception' => $error::class]);
            }
            $coordinator->yield(1);
        }
    }
}
