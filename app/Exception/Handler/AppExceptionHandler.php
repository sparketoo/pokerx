<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\AppException;
use App\Exception\FoundationException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function App\Support\di;

final class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();
        $status = 500;
        if ($throwable instanceof AppException) {
            $error = $throwable->toResponseArray();
            $status = 200;
        } elseif ($throwable instanceof ValidationException) {
            $status = 200;
            $error = [
                'code' => 'event_invalid', 'message' => '参数无效',
                'details' => $throwable->validator->errors()->getMessages(),
            ];
        } elseif ($throwable instanceof HttpException) {
            $status = $throwable->getStatusCode();
            $error = ['code' => $status === 429 ? 'rate_limited' : 'not_found', 'message' => $throwable->getMessage()];
        } else {
            di(LoggerInterface::class)->error('Request failed',
                ['exception' => $throwable::class, 'message' => $throwable->getMessage()]);
            $error = FoundationException::serverError()->toResponseArray();
        }
        if ($status === 429) {
            $status = 200;
        }

        return $response->withStatus($status)->withHeader('content-type',
            'application/json')->withBody(new SwooleStream(json_encode($error + ['data' => null],
                JSON_THROW_ON_ERROR)));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
