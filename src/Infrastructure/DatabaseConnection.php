<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use RuntimeException;

final class DatabaseConnection
{
    public static function connect(bool $migration = false): PDO
    {
        $read = static function (string $key): string {
            $value = getenv($key);
            return $value !== false ? $value : (string) ($_ENV[$key] ?? '');
        };
        $dsn = $read('SIMORA_DB_DSN');
        $prefix = $migration ? 'SIMORA_MIGRATION_DB_' : 'SIMORA_DB_';
        $user = $read($prefix . 'USER');
        $password = $read($prefix . 'PASSWORD');
        if (!str_starts_with($dsn, 'mysql:') || $user === '' || $password === '') {
            throw new RuntimeException('Missing MySQL connection configuration.');
        }
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
