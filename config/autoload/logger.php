<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 *
 * @document https://hyperf.wiki
 *
 * @contact  group@hyperf.io
 *
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

use function Hyperf\Support\env;

return [
    'default' => 'default',
    'channels' => [
        'default' => [
            'handler' => [
                'class' => StreamHandler::class,
                'constructor' => [
                    'stream' => env('LOG_OUTPUT', dirname(__DIR__, 2).'/runtime/logs/hyperf.log'),
                    'level' => Level::Info,
                ],
            ],
            'formatter' => [
                'class' => LineFormatter::class,
                'constructor' => [
                    'format' => null,
                    'dateFormat' => 'Y-m-d H:i:s',
                    'allowInlineLineBreaks' => true,
                ],
            ],
        ],
    ],
];
