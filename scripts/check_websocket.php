<?php

// Isolated transport check: no application container, database or Provider required.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Game\Providers\SocketProvider;
use Swoole\Coroutine;

Swoole\Coroutine\run(function (): void {
    $client = new class(['url' => getenv('SOCKET_TEST_URL'), 'reconnect_interval' => 0.1, 'ping_interval' => 0.05]) extends SocketProvider
    {
        public int $opens = 0;

        public int $closes = 0;

        public int $pings = 0;

        public array $messages = [];

        public array $errors = [];

        private int $attempts = 0;

        protected function getUrl(): string
        {
            return parent::getUrl().'dynamic';
        }

        protected function getHeaders(): array
        {
            return ['X-Attempt' => (string) ++$this->attempts];
        }

        protected function onOpen(): void
        {
            $this->opens++;
        }

        protected function onData(string $data, int $opcode): void
        {
            $this->messages[] = [$opcode === 2 ? bin2hex($data) : $data, $opcode];
        }

        protected function onClose(): void
        {
            $this->closes++;
        }

        protected function onPing(): void
        {
            $this->pings++;
        }

        protected function onError(Throwable $error): void
        {
            $this->errors[] = $error->getMessage();
        }
    };
    if ($client->write('offline')) {
        throw new RuntimeException('Disconnected send succeeded');
    }
    $client->ping();
    if ($client->pings !== 0) {
        throw new RuntimeException('Offline ping invoked hook');
    }
    $client->connect();
    $client->connect(); // Must not create a duplicate connection.
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline && ! ($client->opens >= 2 && $client->pings >= 2 && count($client->messages) >= 2)) {
        Coroutine::sleep(0.01);
    }
    $client->close();
    $closedOpens = $client->opens;
    $closedPings = $client->pings;
    $client->ping();
    Coroutine::sleep(0.4);
    if ($client->isConnected() || $client->opens !== $closedOpens || $client->pings !== $closedPings) {
        throw new RuntimeException('Close did not stop reconnect');
    }
    echo json_encode(get_object_vars($client), JSON_THROW_ON_ERROR).PHP_EOL;
});
