<?php

declare(strict_types=1);

namespace App\Model;

abstract class Model extends \Hyperf\DbConnection\Model\Model
{
    /** @var list<string> */
    protected array $guarded = [];

    protected ?string $dateFormat = 'Y-m-d H:i:s.u';
}
