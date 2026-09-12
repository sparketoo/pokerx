<?php

declare(strict_types=1);

namespace App\Poker;

use Hyperf\WebSocketClient\Client;
use Hyperf\WebSocketClient\Exception\ConnectException;
use Psr\Http\Message\UriInterface;
use Swoole\Coroutine\Http\Client as SwooleClient;

use function Hyperf\Config\config;

/** Apply TLS verification and bounds BEFORE Hyperf's client performs its upgrade. */
class VerifiedClient extends Client
{
    /** @param  array<string, string>  $headers */
    public function __construct(UriInterface $uri, array $headers = [])
    {
        if (! in_array($uri->getScheme(), ['ws', 'wss'],
            true) || $uri->getHost() === '' || $uri->getUserInfo() !== '') {
            throw new ConnectException('Invalid WebSocket URI');
        }
        $this->uri = $uri;
        $ssl = $uri->getScheme() === 'wss';
        $this->client = new SwooleClient($uri->getHost(), $uri->getPort() ?? ($ssl ? 443 : 80), $ssl);
        $this->client->set([
            'timeout' => (float) config('poker.proto.connect_timeout', 10),
            'connect_timeout' => (float) config('poker.proto.connect_timeout', 10), 'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false, 'ssl_host_name' => $uri->getHost(), 'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true, 'open_websocket_close_frame' => true, 'websocket_mask' => true,
            'websocket_compression' => false, 'package_max_length' => 1048576,
        ]);
        if ($headers !== []) {
            $this->client->setHeaders($headers);
        }
        $path = ($uri->getPath() ?: '/').($uri->getQuery() !== '' ? '?'.$uri->getQuery() : '');
        if (! $this->client->upgrade($path)) {
            throw new ConnectException('WebSocket connection or upgrade failed');
        }
        $key = $this->client->requestHeaders['Sec-WebSocket-Key'] ?? '';
        $response = $this->client->headers;
        $expected = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($this->client->statusCode !== 101 || ! hash_equals($expected,
            $response['sec-websocket-accept'] ?? '') || strtolower($response['upgrade'] ?? '') !== 'websocket' || ! in_array('upgrade',
                array_map('trim', explode(',', strtolower($response['connection'] ?? ''))),
                true) || isset($response['sec-websocket-extensions']) || isset($response['sec-websocket-protocol'])) {
            $this->client->close();
            throw new ConnectException('Invalid WebSocket handshake');
        }
    }
}
