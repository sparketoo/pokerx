<?php

declare(strict_types=1);

namespace App\Poker\Providers;

use App\Exception\PokerException;
use App\Poker\WebSocketClient;
use App\Vo\Game\GameContextVo;
use App\Vo\Game\GetSolveVo;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Timer;
use Throwable;

use function App\Support\di;

abstract class SocketProvider extends BaseProvider
{
    private ?WebSocketClient $socket = null;

    private ?Closure $pending = null;

    private ?int $maintenance = null;

    private ?int $timeout = null;

    private bool $ready = false;

    private bool $stopped = false;

    private bool $finishing = false;

    private int $epoch = 0;

    private int $failures = 0;

    private float $retryAt = 0;

    private float $connectedAt = 0;

    private float $pong = 0;

    private float $ping = 0;

    private ?string $pendingGame = null;

    /**
     * @var list<array<string, mixed>>
     */
    private array $outbox = [];

    protected ?GameContextVo $context = null;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(protected readonly array $options, private readonly ?Closure $logger = null) {}

    /**
     * @return array<string, mixed>
     */
    abstract protected function authentication(): array;

    /**
     * @param  array<string, mixed>  $message
     */
    abstract protected function decodeSolve(array $message): ?GetSolveVo;

    /**
     * @return array<string, mixed>
     */
    abstract protected function history(GameContextVo $context, bool $over = false): array;

    final protected function ensureConnected(): void
    {
        if ($this->stopped) {
            throw new RuntimeException('Provider closed');
        }
        if ($this->maintenance === null) {
            $this->maintenance = Timer::tick(100, fn () => $this->maintain());
        }
        if ($this->socket || microtime(true) < $this->retryAt) {
            return;
        }
        $this->connectedAt = microtime(true);
        $epoch = ++$this->epoch;
        $onOpen = function () use ($epoch) {
            if ($epoch !== $this->epoch) {
                return;
            }
            ($this->socket ?? throw new LogicException('Socket is not connected'))->send(json_encode($this->authentication(),
                JSON_THROW_ON_ERROR));
            $this->record('PROVIDER_OUT', 'auth', ['token' => '[redacted]']);
        };
        $onMessage = function (string $data) use ($epoch) {
            if ($epoch !== $this->epoch) {
                return;
            }
            try {
                $message = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($message)) {
                    throw new RuntimeException('Invalid provider message');
                }
                if (! $this->ready) {
                    if (($message['result'] ?? false) !== true) {
                        $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));

                        return;
                    }
                    $this->ready = true;
                    $this->failures = 0;
                    $this->pong = microtime(true);
                    $this->record('PROVIDER_IN', 'auth', ['result' => true]);
                    $messages = $this->outbox;
                    $this->outbox = [];
                    if (! $messages && $this->context?->game->stage !== null) {
                        $messages[] = $this->history($this->context, $this->finishing);
                    }
                    foreach ($messages as $queued) {
                        $this->send($queued);
                    }
                    if ($this->finishing) {
                        $this->close();
                    }

                    return;
                }
                $this->record('PROVIDER_IN', $message['structType'] ?? 'error', $message);
                if (isset($message['gameId']) && $this->pendingGame !== null && $message['gameId'] !== $this->pendingGame) {
                    return;
                }
                $result = $this->decodeSolve($message);
                if ($result !== null && $this->pending !== null) {
                    $this->disconnect($result);
                }
            } catch (Throwable) {
                $this->disconnect(GetSolveVo::failure(PokerException::providerRejected()));
            }
        };
        $onPong = function () use ($epoch) {
            if ($epoch === $this->epoch) {
                $this->pong = microtime(true);
                $this->ping = 0;
                $this->record('PROVIDER_IN', 'pong', []);
            }
        };
        $onClose = function () use ($epoch) {
            if ($epoch === $this->epoch) {
                $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
            }
        };
        $socket = new WebSocketClient($this->options['url'] ?? '', $onOpen, $onMessage, $onPong, $onClose);
        $this->socket = $socket;
        $socket->connect();
    }

    private function maintain(): void
    {
        if ($this->stopped) {
            return;
        }
        try {
            if (! $this->socket) {
                $this->ensureConnected();

                return;
            }
            if (! $this->ready) {
                if (microtime(true) - $this->connectedAt > ($this->options['connect_timeout'] ?? 10)) {
                    $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
                }

                return;
            }
            if ($this->ping > 0 && microtime(true) - $this->ping > ($this->options['pong_timeout'] ?? 10)) {
                $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
            } elseif ($this->ping === 0.0 && microtime(true) - $this->pong >= ($this->options['heartbeat'] ?? 20)) {
                $this->ping = microtime(true);
                ($this->socket ?? throw new LogicException('Socket is not connected'))->send('', 9);
                $this->record('PROVIDER_OUT', 'ping', []);
            }
        } catch (Throwable) {
            $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    final protected function send(array $message): void
    {
        $this->ensureConnected();
        if (! $this->ready) {
            $this->outbox[] = $message;

            return;
        }
        $this->record('PROVIDER_OUT', $message['structType'] ?? 'message', $message);
        if (($this->socket ?? throw new LogicException('Socket is not connected'))->send(json_encode($message,
            JSON_THROW_ON_ERROR)) === false) {
            $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    final protected function sendFinal(array $message): void
    {
        $this->finishing = true;
        $this->send($message);
        if ($this->ready) {
            $this->close();
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    final protected function request(array $message, int $timeoutMs, Closure $completed): void
    {
        if ($this->pending !== null) {
            $completed(GetSolveVo::failure(PokerException::solveInProgress()));

            return;
        }
        $this->pending = $completed;
        $this->pendingGame = $message['gameId'] ?? null;
        $this->timeout = Timer::after(max(1, $timeoutMs),
            fn () => $this->disconnect(GetSolveVo::failure(PokerException::solveTimeout())));
        try {
            $this->send($message);
        } catch (Throwable) {
            $this->disconnect(GetSolveVo::failure(PokerException::providerUnavailable()));
        }
    }

    private function disconnect(?GetSolveVo $result = null): void
    {
        $this->epoch++;
        if ($this->finishing) {
            $this->stopped = true;
            if ($this->maintenance !== null) {
                Timer::clear($this->maintenance);
                $this->maintenance = null;
            }
        }
        $socket = $this->socket;
        $this->socket = null;
        $this->ready = false;
        $this->ping = 0;
        $this->outbox = [];
        $callback = $this->pending;
        $this->pending = null;
        $this->pendingGame = null;
        if ($this->timeout !== null) {
            Timer::clear($this->timeout);
            $this->timeout = null;
        }
        if ($this->finishing) {
            $socket?->close();
        } else {
            $socket?->abort();
        }
        $this->retryAt = microtime(true) + min($this->options['reconnect_max'] ?? 30,
            2 ** min($this->failures++, 5)) + random_int(0, 250) / 1000;
        if ($callback) {
            $callback($result ?? GetSolveVo::failure(PokerException::providerUnavailable()));
        }
    }

    final public function close(): void
    {
        $this->stopped = true;
        if ($this->maintenance !== null) {
            Timer::clear($this->maintenance);
            $this->maintenance = null;
        }
        $this->disconnect();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function record(string $direction, string $type, array $payload): void
    {
        if ($this->logger) {
            try {
                ($this->logger)($direction, $type, $payload, $this->context?->game->id);
            } catch (Throwable $error) {
                di(LoggerInterface::class)->warning('Provider log unavailable', ['exception' => get_class($error)]);
            }
        }
    }
}
