<?php

declare(strict_types=1);

namespace App\Job;

use App\Constants\ErrorCode;
use App\Enum\GameStatusEnum;
use App\Exception\GameException;
use App\Service\GameService;
use Hyperf\AsyncQueue\Job;

use function App\Support\di;

final class GameStoreJob extends Job
{
    public function __construct(public readonly string $uuid)
    {
        $this->setMaxAttempts(4);
    }

    public function handle(): void
    {
        $service = di(GameService::class);
        try {
            $game = $service->find($this->uuid);
        } catch (GameException $error) {
            if ($error->getCode() === ErrorCode::GAME_NOT_FOUND) {
                return;
            }

            throw $error;
        }

        if ($game->status->isOpen()) {
            $game->status = GameStatusEnum::CLOSED;
        }
        $service->store($game);
    }
}
