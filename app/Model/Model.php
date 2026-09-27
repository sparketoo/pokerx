<?php

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

use function App\Support\appTimezone;

abstract class Model extends \Hyperf\DbConnection\Model\Model
{
    /** @var list<string> */
    protected array $guarded = [];

    protected ?string $dateFormat = 'Y-m-d H:i:s.u';

    public function fromDateTime(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $value = DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone(appTimezone()));
        }

        return parent::fromDateTime($value);
    }
}
