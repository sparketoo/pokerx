<?php

declare(strict_types=1);

namespace App\Game\Providers;

use App\Enum\ActionEnum;
use App\Enum\StageEnum;
use App\Exception\GameException;
use App\Vo\Game\CardVo;
use App\Vo\Game\GameEventVo;
use App\Vo\Game\GameVo;
use App\Vo\Game\RequestActionResultVo;
use Closure;
use Hyperf\Engine\Contract\Http\ClientInterface;
use Hyperf\Engine\Http\Client;
use Hyperf\Redis\Redis;
use JsonException;
use Throwable;

use function App\Support\di;

final class ProtoProvider extends BaseProvider
{
    private const int SESSION_TTL = 280;

    /** @var array<string, true> */
    private array $pendingActions = [];

    /**
     * @param  array<string, mixed>  $options
     * @param  null|Closure(string, int, bool): ClientInterface  $clientFactory
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?Closure $clientFactory = null,
        private readonly ?Redis $redis = null,
    ) {}

    public function requestAction(GameVo $game, Closure $callback): void
    {
        if (isset($this->pendingActions[$game->uuid])) {
            throw GameException::requestActionInProgress();
        }
        $this->pendingActions[$game->uuid] = true;
        try {
            $response = $this->withSession($game, function (string $sessionId) use ($game): array {
                $this->expectAcknowledgement($this->command($this->gameEvents($game), $sessionId), 'gameEvents');

                return $this->command([
                    'structType' => 'getAnswer',
                    'gameId' => $game->uuid,
                    'potForAlpha' => $game->pot(),
                    'delay' => (int) ($this->options['delay'] ?? 9000),
                ], $sessionId);
            });
            $result = $this->actionResult($response, $game->uuid);
        } catch (Throwable $error) {
            $result = RequestActionResultVo::failure($error instanceof GameException
                ? $error : GameException::providerFailed($error->getMessage()));
        } finally {
            unset($this->pendingActions[$game->uuid]);
        }

        $callback($result);
    }

    public function over(GameEventVo $event): void
    {
        try {
            $this->withSession($event->game, function (string $sessionId) use ($event): void {
                $this->expectAcknowledgement($this->command($this->gameEvents($event->game, true), $sessionId),
                    'fullGameLog');
            });
            $this->logger()->info('Proto HTTP fullGameLog acknowledged', [
                'game_id' => $event->game->uuid,
            ]);
        } catch (Throwable $error) {
            $this->logger()->warning('Proto HTTP fullGameLog failed', [
                'game_id' => $event->game->uuid,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $operation
     * @return T
     */
    private function withSession(GameVo $game, Closure $operation): mixed
    {
        $key = 'proto-http:session:user:'.$game->userId;
        $redis = $this->redis ?? di(Redis::class);

        $sessionId = $redis->get($key);
        if (! is_string($sessionId) || $sessionId === '') {
            $token = (string) ($this->options['token'] ?? '');
            $response = $this->command(['token' => $token, 'descr' => 'PokerX']);
            if (($response['result'] ?? null) !== true || ! is_string($response['sessionId'] ?? null)
                || $response['sessionId'] === '') {
                throw GameException::providerFailed((string) ($response['info'] ?? 'Proto HTTP authentication failed'));
            }

            $sessionId = $response['sessionId'];
        }
        $redis->setex($key, self::SESSION_TTL, $sessionId);

        try {
            return $operation($sessionId);
        } catch (GameException $error) {
            if (($error->context()['proto_http_session_invalid'] ?? false) === true) {
                $redis->del($key);
            }

            throw $error;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function command(array $payload, ?string $sessionId = null): array
    {
        $parts = parse_url((string) ($this->options['url'] ?? '')) ?: [];
        $ssl = ($parts['scheme'] ?? null) === 'https';
        $host = (string) ($parts['host'] ?? '');
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = rtrim($parts['path'] ?? '', '/');
        if (! str_ends_with($path, '/api/command')) {
            $path .= '/api/command';
        }
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $headers = ['Content-Type' => ['application/json']];
        $pid = (string) ($this->options['player_id'] ?? '');
        if ($pid !== '') {
            $headers['X-Player-Id'] = [$pid];
            $query['pid'] = $pid;
        }
        if ($sessionId !== null) {
            $headers['X-Session-Id'] = [$sessionId];
        }

        if ($query !== []) {
            $path .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $factory = $this->clientFactory ?? static fn (
            string $host,
            int $port,
            bool $ssl
        ): ClientInterface => new Client($host, $port, $ssl);
        $client = $factory($host, $port, $ssl);
        $settings = [
            'timeout' => (float) ($this->options['request_timeout'] ?? 10),
            'connect_timeout' => (float) ($this->options['connect_timeout'] ?? 5),
        ];
        if ($ssl) {
            $settings['ssl_verify_peer'] = true;
            $settings['ssl_host_name'] = $host;
        }
        try {
            if (! $client->set($settings)) {
                throw GameException::providerFailed('Proto HTTP client configuration failed');
            }
            $response = $client->request('POST', $path, $headers,
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } finally {
            if ($client instanceof Client) {
                $client->close();
            }
        }
        try {
            $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw GameException::providerFailed('Proto HTTP returned invalid JSON: '.$error->getMessage());
        }
        if (! is_array($body)) {
            throw GameException::providerFailed('Proto HTTP returned invalid response');
        }
        if (($body['error'] ?? null) === 'invalid sessionId') {
            throw GameException::providerFailed('Proto HTTP session expired')
                ->withContext(['proto_http_session_invalid' => true]);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || isset($body['error'])) {
            $reason = $body['error'] ?? 'HTTP '.$response->getStatusCode();
            throw GameException::providerFailed(is_string($reason) ? $reason : 'Proto HTTP request failed');
        }

        return $body;
    }

    /** @param  array<string, mixed>  $response */
    private function expectAcknowledgement(array $response, string $command): void
    {
        if (($response['result'] ?? null) !== true) {
            throw GameException::providerFailed('Proto HTTP '.$command.' was not acknowledged');
        }
    }

    /** @param  array<string, mixed>  $response */
    private function actionResult(array $response, string $gameId): RequestActionResultVo
    {
        if (($response['structType'] ?? null) !== 'playerAction' || ($response['gameId'] ?? null) !== $gameId) {
            throw GameException::providerFailed('Proto HTTP returned an unexpected action response');
        }
        $action = self::actionToEnum($response['action'] ?? '');

        return RequestActionResultVo::success($action, $response['amount'] ?? 0);
    }

    /** @return array<string, mixed> */
    public function gameEvents(GameVo $game, bool $over = false): array
    {
        if ($over && $game->status->isAbort()) {
            throw GameException::eventInvalid();
        }
        $events = [];
        $hero = $game->hero();
        foreach ($game->players as $player) {
            $events[] = [
                'eventType' => 'playerSeated',
                'seat' => $player->seatNumber,
                'name' => $player->uid,
                'stack' => $player->stack,
            ];
        }
        foreach ($game->players as $player) {
            if ($player->ante > 0) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $player->uid,
                    'blindType' => 'ANTE',
                    'amount' => $player->ante,
                ];
            }
        }
        foreach ($game->players as $player) {
            if ($player->blind > 0) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $player->uid,
                    'blindType' => $player->isSb() ? 'SB' : 'BB',
                    'amount' => $player->blind,
                ];
            }
        }
        foreach ($game->events->sortBy('timestamp') as $event) {
            if ($event->type->isPostBlind()) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $event->payload['uid'],
                    'blindType' => 'POST',
                    'amount' => $event->payload['amount'],
                ];

                continue;
            }
            if ($event->type->isStraddleBlind()) {
                $events[] = [
                    'eventType' => 'blindPosted',
                    'name' => $event->payload['uid'],
                    'blindType' => 'STRADDLE',
                    'amount' => $event->payload['amount'],
                ];

                continue;
            }
            if ($event->type->isStage()) {
                $stage = StageEnum::fromNameOrFail(strtoupper($event->payload['stage']));
                $cards = $event->payload['cards'] ?? [];
                $events[] = [
                    'eventType' => 'stageStarted',
                    'stage' => strtolower($stage->name),
                    'cards' => CardVo::cardsToShort($cards),
                ];

                continue;
            }
            if ($event->type->isDealt()) {
                $events[] = [
                    'eventType' => 'handDealt',
                    'name' => $hero->uid,
                    'cards' => CardVo::cardsToShort($event->payload['cards']),
                ];

                continue;
            }
            if ($event->type->isAction()) {
                $events[] = [
                    'eventType' => 'playerActed',
                    'name' => $event->payload['uid'],
                    'action' => self::enumToAction(ActionEnum::fromNameOrFail($event->payload['action'])),
                    'amount' => $event->payload['amount'] ?? 0,
                ];

                continue;
            }
            if ($event->type->isShow()) {
                $events[] = [
                    'eventType' => 'knownPlayerCards',
                    'name' => $event->payload['uid'],
                    'cards' => CardVo::cardsToShort($event->payload['cards']),
                ];

                continue;
            }
        }

        if ($over) {
            foreach ($game->players as $player) {
                if ($player->winnings + $player->returned > 0) {
                    $events[] = [
                        'eventType' => 'playerWon',
                        'name' => $player->uid,
                        'amount' => $player->winnings + $player->returned,
                    ];
                }
            }
            foreach ($game->players as $player) {
                if ($player->cards) {
                    $events[] = [
                        'eventType' => 'handShown',
                        'name' => $player->uid,
                        'cards' => CardVo::cardsToShort($player->cards),
                    ];
                } else {
                    $events[] = [
                        'eventType' => 'noHandShown',
                        'name' => $player->uid,
                    ];
                }
            }
            $events[] = [
                'eventType' => 'gameOver',
            ];
        }

        return [
            'structType' => $over ? 'fullGameLog' : 'gameEvents',
            'game' => [
                'gameId' => $game->uuid,
                'pokerNetwork' => $this->options['network'] ?? 'WE',
                'gameType' => 'NL',
                'network' => $game->network->name,
                'bigBlind' => $game->bigBlind,
                'ante' => $game->ante,
                'currency' => $this->options['currency'] ?? 'USDT',
                'gameDate' => (string) $game->createdAtMs,
                'numPlayers' => count($game->players),
                'buttonSetToSeat' => $game->buttonSeatNumber,
            ],
            'events' => $events,
        ];
    }

    private static function enumToAction(ActionEnum $action): string
    {
        return match ($action) {
            ActionEnum::ALL_IN => 'all-in',
            default => strtolower($action->name),
        };
    }

    private static function actionToEnum(string $action): ActionEnum
    {
        return match (strtolower($action)) {
            'fold' => ActionEnum::FOLD,
            'check' => ActionEnum::CHECK,
            'call' => ActionEnum::CALL,
            'bet' => ActionEnum::BET,
            'raise' => ActionEnum::RAISE,
            'all-in' => ActionEnum::ALL_IN,
            default => throw GameException::providerFailed('Proto HTTP returned an unsupported action:'.$action),
        };
    }
}
