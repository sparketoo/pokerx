<?php

declare(strict_types=1);

namespace App\Vo\Game;

use App\Enum\ActionEnum;
use App\Enum\SolveStatusEnum;
use App\Vo\Vo;

final class SolveVo extends Vo
{
    public function __construct(
        public readonly SolveStatusEnum $status,
        public readonly ?bool $success,
        public readonly ?ActionEnum $action,
        public readonly ?int $amount,
        public readonly int $cost,
        public readonly ?string $errorCode,
        public readonly ?string $reason,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?int $balance = null,
        public readonly ?string $creditUuid = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(SolveStatusEnum::fromNameOrFail(strtoupper($data['status'])), $data['success'],
            isset($data['action']) ? ActionEnum::fromNameOrFail(strtoupper($data['action'])) : null, $data['amount'],
            $data['cost'], $data['error_code'], $data['reason'], $data['created_at'], $data['updated_at'],
            $data['balance'] ?? null, $data['credit_uuid'] ?? null);
    }
}
