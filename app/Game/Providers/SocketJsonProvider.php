<?php

namespace App\Game\Providers;

abstract class SocketJsonProvider extends SocketProvider
{
    protected function onData(string $data, int $opcode): void
    {
        $json = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $this->onMessage($json, $opcode);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    abstract protected function onMessage(array $message, int $opcode): void;

    /**
     * @param  array<string, mixed>  $message
     */
    protected function send(array $message, int $opcode = 1): bool
    {
        return $this->write(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $opcode);
    }
}
