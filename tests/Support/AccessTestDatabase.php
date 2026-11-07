<?php

declare(strict_types=1);

namespace SimoraTests\Support;

use App\Infrastructure\DatabaseConnection;
use PDO;
use RuntimeException;

final class AccessTestDatabase
{
    public static function connect(bool $migration = false): PDO
    {
        $database = getenv('SIMORA_ACCESS_TEST_DB') ?: '';
        if (preg_match('/^simora_access_test_[a-f0-9]{16}$/D', $database) !== 1) {
            throw new RuntimeException('Use bin/test-access.ps1 to run against an isolated database.');
        }
        $dsn = getenv('SIMORA_DB_DSN') ?: ($_ENV['SIMORA_DB_DSN'] ?? '');
        $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn);
        putenv('SIMORA_DB_DSN=' . $dsn);
        return DatabaseConnection::connect($migration);
    }
}
