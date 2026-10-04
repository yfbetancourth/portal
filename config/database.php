<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('PORTAL_DB_HOST') ?: '127.0.0.1';
    $database = getenv('PORTAL_DB_NAME') ?: 'AdminLTE';
    $username = getenv('PORTAL_DB_USER') ?: 'root';
    $password = getenv('PORTAL_DB_PASSWORD');
    $connection = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $username,
        $password === false ? '' : $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}
