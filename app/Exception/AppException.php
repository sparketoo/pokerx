<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\Stringable\Str;
use Hyperf\Translation\Translator;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use Throwable;

use function App\Support\di;
use function Hyperf\Config\config;

abstract class AppException extends Exception
{
    protected const string SAFE_FALLBACK_MESSAGE = '请求处理失败，请稍后重试。';

    /**
     * @var array<string, string|int|float>
     */
    protected array $replace = [];

    /**
     * @var array<string, mixed>
     */
    protected array $details = [];

    /**
     * @var array<string, mixed>
     */
    protected array $context = [];

    protected string $reportLevel = LogLevel::INFO;

    protected ?string $localeKey = null;

    protected bool $missingTranslationReported = false;

    protected string $errorCode;

    /**
     * @param  array<string, string|int|float>  $replace
     */
    final protected function __construct(
        protected string $methodName,
        array $replace = [],
        ?Throwable $previous = null,
    ) {
        $this->replace = $replace;
        $this->errorCode = Str::snake($methodName);

        parent::__construct($this->getLocaleMessage(), previous: $previous);
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getLocaleFile(): string
    {
        $className = (new ReflectionClass(static::class))->getShortName();
        $exceptionName = Str::beforeLast($className, 'Exception');

        return Str::snake($exceptionName);
    }

    public function getLocaleKey(): string
    {
        return $this->localeKey ??= sprintf(
            'errors/%s.%s',
            $this->getLocaleFile(),
            $this->methodName,
        );
    }

    public function getLocaleMessage(?string $language = null): string
    {
        $translator = di(TranslatorInterface::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Hyperf translator required');
        }
        $language ??= $translator->getLocale();
        $localeKey = $this->getLocaleKey();

        if ($translator->has($localeKey, $language)) {
            return $this->translated($translator->get($localeKey, $this->replace, $language));
        }

        $fallbackLanguage = (string) config('app.fallback_locale', 'en');
        if ($fallbackLanguage !== $language && $translator->has($localeKey, $fallbackLanguage)) {
            return $this->translated($translator->get($localeKey, $this->replace, $fallbackLanguage));
        }

        $this->reportMissingTranslation($language, $localeKey);

        $fallbackKey = 'errors/foundation.serverError';
        foreach (array_unique([$language, $fallbackLanguage]) as $fallbackLocale) {
            if ($translator->has($fallbackKey, $fallbackLocale)) {
                return $this->translated($translator->get($fallbackKey, locale: $fallbackLocale));
            }
        }

        return self::SAFE_FALLBACK_MESSAGE;
    }

    /**
     * @return array<string, mixed>
     */
    /** @param  array<string, mixed>|string  $value */
    private function translated(array|string $value): string
    {
        return is_string($value) ? trim($value) : self::SAFE_FALLBACK_MESSAGE;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @param  array<string, mixed>|string  $details
     */
    public function withDetails(array|string $details, mixed $value = null): static
    {
        if (is_array($details)) {
            $this->details = array_merge($this->details, $details);
        } elseif ($value !== null) {
            $this->details[$details] = $value;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>|string  $context
     */
    public function withContext(array|string $context, mixed $value = null): static
    {
        if (is_array($context)) {
            $this->context = array_merge($this->context, $context);
        } elseif ($value !== null) {
            $this->context[$context] = $value;
        }

        return $this;
    }

    public function getReportLevel(): string
    {
        return $this->reportLevel;
    }

    /**
     * @return array{code: string, message: string, details?: array<string, mixed>}
     */
    public function toResponseArray(): array
    {
        $response = [
            'code' => $this->getErrorCode(),
            'message' => $this->getLocaleMessage(),
        ];

        if ($this->details !== []) {
            $response['details'] = $this->details;
        }

        return $response;
    }

    public function report(): void
    {
        $context = [
            'code' => $this->getErrorCode(),
            'exception_class' => static::class,
            ...$this->context,
        ];

        if (in_array($this->reportLevel, [LogLevel::WARNING, LogLevel::ERROR], true)) {
            $context['exception'] = ['class' => static::class, 'message' => $this->getMessage()];
        }

        di(LoggerInterface::class)->log($this->reportLevel, $this->getLocaleMessage(), $context);
    }

    protected function warning(): static
    {
        $this->reportLevel = LogLevel::WARNING;

        return $this;
    }

    protected function error(): static
    {
        $this->reportLevel = LogLevel::ERROR;

        return $this;
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    protected static function create(
        string $methodName,
        array $replace = [],
        ?Throwable $previous = null,
    ): static {
        return new static($methodName, $replace, $previous);
    }

    protected function reportMissingTranslation(string $language, string $localeKey): void
    {
        if ($this->missingTranslationReported) {
            return;
        }

        $this->missingTranslationReported = true;

        di(LoggerInterface::class)->warning('Exception translation is missing.', [
            'exception_class' => static::class,
            'method' => $this->methodName,
            'locale' => $language,
            'locale_key' => $localeKey,
        ]);
    }
}
