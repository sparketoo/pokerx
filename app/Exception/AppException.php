<?php

declare(strict_types=1);

namespace App\Exception;

use App\Constants\ErrorCode;
use RuntimeException;
use Throwable;

abstract class AppException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Client-safe response data.
     * @param  array<string, mixed>  $context  Internal diagnostics for logs only.
     */
    public function __construct(
        string $message,
        int $code = ErrorCode::BUSINESS_ERROR,
        private readonly array $details = [],
        ?Throwable $previous = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return array{code: int, message: string, details?: array<string, mixed>} */
    public function toResponseArray(): array
    {
        $response = ['code' => $this->getCode(), 'message' => $this->getMessage()];
        if ($this->details !== []) {
            $response['details'] = $this->details;
        }

        return $response;
    }
}
