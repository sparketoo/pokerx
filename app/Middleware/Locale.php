<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Model\User;
use Hyperf\Context\Context;
use Hyperf\Contract\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Selects a request's translation locale without leaking it to another request.
 *
 * This middleware must run after Authenticate, so the authenticated user is
 * already available from the coroutine context when X-Language is absent.
 */
final readonly class Locale implements MiddlewareInterface
{
    private const array SUPPORTED_LOCALES = [
        'zh-cn' => 'zh-CN',
        'en-us' => 'en-US',
    ];

    public function __construct(private TranslatorInterface $translator) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = Context::get(User::class);
        $locale = $this->localeFrom($request->getHeaderLine('X-Language'))
            ?? $this->localeFrom($user instanceof User ? $user->language : '');

        // Let the translator's configured default apply when neither source
        // provides a supported locale.
        if ($locale === null) {
            return $handler->handle($request);
        }

        $contextKey = sprintf('%s::locale', TranslatorInterface::class);
        $hadLocale = Context::has($contextKey);
        $previousLocale = $hadLocale ? Context::get($contextKey) : null;
        $this->translator->setLocale($locale);

        try {
            return $handler->handle($request);
        } finally {
            // Context is coroutine-local under Swoole. Restoring it also makes
            // nested middleware and non-coroutine invocations safe from leakage.
            if ($hadLocale) {
                Context::set($contextKey, $previousLocale);
            } else {
                Context::destroy($contextKey);
            }
        }
    }

    private function localeFrom(string $value): ?string
    {
        return self::SUPPORTED_LOCALES[strtolower(trim($value))] ?? null;
    }
}
