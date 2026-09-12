<?php

declare(strict_types=1);

namespace App\Listener;

use App\Gateway\GameGateway;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnWorkerExit;

#[Listener]
final readonly class GatewayShutdownListener implements ListenerInterface
{
    public function __construct(private GameGateway $gateway) {}

    public function listen(): array
    {
        return [OnWorkerExit::class];
    }

    public function process(object $event): void
    {
        Coroutine::create(fn () => $this->gateway->shutdown());
    }
}
