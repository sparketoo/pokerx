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
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\ClassLoader;
use Hyperf\Engine\DefaultOption;

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

foreach ([
    'APP_ENV' => 'testing',
    'DB_DATABASE' => 'pokerx_hyperf_test',
    'REDIS_PREFIX' => 'pokerx_hyperf_test:',
    'REDIS_PORT' => '6379',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require dirname(__DIR__).'/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', DefaultOption::hookFlags());

ClassLoader::init();

$container = require dirname(__DIR__).'/config/container.php';

$container->get(ApplicationInterface::class);

require_once __DIR__.'/functions.php';
