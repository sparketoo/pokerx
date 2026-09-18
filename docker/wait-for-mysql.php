<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'mysql';
$port = (int) (getenv('DB_PORT') ?: 3306);
$database = getenv('DB_DATABASE') ?: 'pokerx';
$username = getenv('DB_USERNAME') ?: 'pokerx';
$password = getenv('DB_PASSWORD') ?: 'pokerx';
$timeout = max(1, (int) (getenv('MYSQL_WAIT_TIMEOUT') ?: 60));
$deadline = time() + $timeout;

do {
    try {
        new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("MySQL is not ready yet (%s). Retrying...\n", $exception->getMessage()));
        sleep(2);
    }
} while (time() < $deadline);

fwrite(STDERR, sprintf("MySQL did not become ready within %d seconds.\n", $timeout));
exit(1);
