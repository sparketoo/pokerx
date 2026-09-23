<?php

declare(strict_types=1);

namespace App\Vo;

use Hyperf\Stringable\Str;
use JsonSerializable;
use UnexpectedValueException;
use UnitEnum;

abstract class Vo implements JsonSerializable
{
    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach (get_object_vars($this) as $key => $value) {
            $out[Str::snake($key)] = self::serializeValue($value);
        }

        return $out;
    }

    /** @return array<array-key, mixed> */
    protected static function arrayValue(mixed $value): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException('Expected an array in game snapshot');
        }

        return $value;
    }

    private static function serializeValue(mixed $value): mixed
    {
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }
        if (is_array($value)) {
            return array_map(self::serializeValue(...), $value);
        }

        return $value;
    }
}
