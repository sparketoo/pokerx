<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Middleware\Locale;
use App\Model\User;
use Closure;
use Hyperf\Context\Context;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\TestCase;

use function App\Support\di;

final class LocaleTest extends TestCase
{
    public function test_header_has_priority_and_locale_is_restored_after_request(): void
    {
        $seenLocale = null;
        $restoredLocale = null;

        \Tests\run(function () use (&$seenLocale, &$restoredLocale): void {
            $translator = di(TranslatorInterface::class);
            $translator->setLocale('zh-CN');
            Context::set(User::class, new User(['language' => 'zh-CN']));

            try {
                (new Locale($translator))->process(
                    new Request('GET', '/api/mine', ['X-Language' => 'EN-us']),
                    $this->handler(function () use ($translator, &$seenLocale): void {
                        $seenLocale = $translator->getLocale();
                    }),
                );
                $restoredLocale = $translator->getLocale();
            } finally {
                Context::destroy(User::class);
                Context::destroy(sprintf('%s::locale', TranslatorInterface::class));
            }
        });

        self::assertSame('en-US', $seenLocale);
        self::assertSame('zh-CN', $restoredLocale);
    }

    public function test_user_language_is_used_when_header_is_missing_or_unsupported(): void
    {
        $seenLocales = [];

        \Tests\run(function () use (&$seenLocales): void {
            $translator = di(TranslatorInterface::class);
            Context::set(User::class, new User(['language' => 'en-US']));

            try {
                foreach (['', 'fr-FR'] as $header) {
                    (new Locale($translator))->process(
                        new Request('GET', '/api/mine', ['X-Language' => $header]),
                        $this->handler(function () use ($translator, &$seenLocales): void {
                            $seenLocales[] = $translator->getLocale();
                        }),
                    );
                }
            } finally {
                Context::destroy(User::class);
                Context::destroy(sprintf('%s::locale', TranslatorInterface::class));
            }
        });

        self::assertSame(['en-US', 'en-US'], $seenLocales);
    }

    /** @param Closure(): void $callback */
    private function handler(Closure $callback): RequestHandlerInterface
    {
        return new class($callback) implements RequestHandlerInterface
        {
            public function __construct(private Closure $callback) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->callback)();

                return new Response;
            }
        };
    }
}
