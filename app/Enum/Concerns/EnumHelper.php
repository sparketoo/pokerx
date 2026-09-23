<?php

declare(strict_types=1);

namespace App\Enum\Concerns;

use Hyperf\Contract\TranslatorInterface;
use Hyperf\Stringable\Str;
use ReflectionClass;
use ValueError;

use function App\Support\di;
use function Hyperf\Translation\__;

trait EnumHelper
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(fn ($case) => $case->name, self::cases());
    }

    public static function has(string $name): bool
    {
        return in_array($name, self::names());
    }

    public static function implode(string $separator = ','): string
    {
        return implode($separator, self::names());
    }

    public static function fromName(?string $name): ?static
    {
        return array_find(self::cases(), static fn (self $case): bool => $case->name === $name);
    }

    public static function fromNameOrFail(string $name): static
    {
        return self::fromName($name) ?? throw new ValueError('Invalid enum name');
    }

    public function is(self $other): bool
    {
        return $this === $other;
    }

    public function wire(): string
    {
        return strtolower($this->name);
    }

    public function label(?string $locale = null): string
    {
        $file = Str::snake(substr((new ReflectionClass($this))->getShortName(), 0, -4));
        $key = 'enums/'.$file.'.'.$this->name;
        $value = __($key, [], $locale ?? di(TranslatorInterface::class)->getLocale());

        return $value === $key ? $this->name : $value;
    }
}
