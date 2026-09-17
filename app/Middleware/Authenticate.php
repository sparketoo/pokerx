<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exception\AuthException;
use App\Model\User;
use App\Model\UserToken;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Authenticate implements MiddlewareInterface
{
    public function __construct(private Redis $redis) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/')) {
            $ip = (string) ($request->getServerParams()['remote_addr'] ?? 'unknown');
            if ($path === '/api/health') {
                return $handler->handle($request);
            }
            if ($path === '/api/auth/login') {
                $this->limit('login:'.$ip, 10);
                $input = $request->getParsedBody();
                $account = is_array($input) ? ($input['account'] ?? '') : '';
                $this->limit('account:'.hash('sha256', is_string($account) ? strtolower(trim($account)) : ''), 5);
            } else {
                $header = $request->getHeaderLine('authorization');
                if (! preg_match('/^Bearer (\d+)\|(.+)$/D', $header, $parts)) {
                    throw AuthException::authRequired();
                }
                $token = UserToken::query()->find((int) $parts[1]);
                if (! $token || ! hash_equals($token->token, hash('sha256',
                    $parts[2])) || ($token->expires_at && $token->expires_at->isPast())) {
                    throw AuthException::authRequired();
                }
                $user = User::query()->find($token->user_id);
                if (! $user || ! $user->status->isNormal()) {
                    throw AuthException::authRequired();
                }
                $this->limit('api:'.$user->id, 120);
                Context::set(User::class, $user);
            }
        }

        return $handler->handle($request);
    }

    private function limit(string $key, int $limit): void
    {
        $key = 'rate:'.$key.':'.intdiv(time(), 60);
        $count = $this->redis->eval('local n=redis.call("INCR",KEYS[1]); if n==1 then redis.call("EXPIRE",KEYS[1],ARGV[1]) end return n',
            [$key, 61], 1);
        if ($count > $limit) {
            throw new HttpException(429, 'Too many requests');
        }
    }
}
