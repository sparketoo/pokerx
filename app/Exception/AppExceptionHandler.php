<?php

declare(strict_types=1);

namespace App\Exception;

use App\Constants\ErrorCode;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function App\Support\di;
use function Hyperf\Translation\__;

final class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();
        $status = 500;
        if ($throwable instanceof AppException) {
            $error = $throwable->toResponseArray();
            $status = 200;
            if ($throwable instanceof ProviderException) {
                di(LoggerInterface::class)->warning('Provider request failed', [
                    ...$throwable->context(),
                    'code' => $throwable->getCode(),
                    'exception' => $throwable,
                    'file' => $throwable->getFile(),
                    'line' => $throwable->getLine(),
                ]);
            }
        } elseif ($throwable instanceof ValidationException) {
            $status = 200;
            $error = [
                'code' => ErrorCode::INVALID_INPUT, 'message' => __('messages.common.invalid_input'),
                'details' => $throwable->validator->errors()->getMessages(),
            ];
        } elseif ($throwable instanceof HttpException) {
            $status = $throwable->getStatusCode();
            $error = [
                'code' => $status === 429 ? ErrorCode::RATE_LIMITED : ErrorCode::NOT_FOUND,
                'message' => __($status === 429 ? 'messages.common.rate_limited' : 'messages.common.not_found'),
            ];
        } else {
            di(LoggerInterface::class)->error('Request failed',
                ['exception' => $throwable, 'file' => $throwable->getFile(), 'line' => $throwable->getLine()]);
            $error = ['code' => ErrorCode::SERVER_ERROR, 'message' => __('messages.common.server_error')];
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
