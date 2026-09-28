<?php

declare(strict_types=1);

namespace App\Listener;

use App\Game\GameServer;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\OnPipeMessage;
use JsonException;
use Psr\Container\ContainerInterface;

final class SharedShowPipeMessageListener implements ListenerInterface
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function listen(): array
    {
        return [OnPipeMessage::class];
    }

    public function process(object $event): void
    {
        if (! $event instanceof OnPipeMessage || ! is_string($event->data)) {
            return;
        }
        try {
            $message = json_decode($event->data, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }
        if (is_array($message) && ($message['kind'] ?? null) === 'shared_show') {
            $this->container->get(GameServer::class)->deliverSharedShow($message);
        }
    }
}
