<?php

use App\Exception\AppException;
use App\Exception\AuthException;
use App\Exception\FoundationException;
use App\Exception\GatewayException;
use App\Exception\PokerException;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\Stringable\Str;

it('provides unique scoped exception codes and translated safe messages', function () {
    $codes = [];
    foreach ([AuthException::class, FoundationException::class, GatewayException::class, PokerException::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || ! $method->isPublic()) {
                continue;
            }
            $exception = $class::{$method->name}();
            expect($exception)->toBeInstanceOf(AppException::class);
            expect($exception->getErrorCode())->toBe(Str::snake($method->name));
            expect(in_array($exception->getErrorCode(), $codes, true))->toBeFalse();
            $codes[] = $exception->getErrorCode();
            foreach (['en', 'zh-CN'] as $locale) {
                expect(\App\Support\di(TranslatorInterface::class)->trans($exception->getLocaleKey(), [], $locale))->not->toBe($exception->getLocaleKey());
                expect($exception->getLocaleMessage($locale))->not->toBeEmpty();
            }
        }
    }
});

it('keeps diagnostic context private and translates using the fallback locale', function () {
    $exception = PokerException::providerRejected(['request_id' => 'request'])->withContext(['token' => 'private-diagnostic']);
    expect($exception->toResponseArray())->toBe([
        'code' => 'provider_rejected',
        'message' => $exception->getLocaleMessage(),
        'details' => ['request_id' => 'request'],
    ]);
    expect($exception->getLocaleMessage('missing-locale'))->toBe($exception->getLocaleMessage('en'));
    expect(str_contains(json_encode($exception->toResponseArray(), JSON_THROW_ON_ERROR), 'private-diagnostic'))->toBeFalse();
});
