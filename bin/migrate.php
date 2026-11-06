<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
require dirname(__DIR__) . '/bootstrap.php';

try {
    $actor = getenv('SIMORA_MIGRATION_ACTOR') ?: ($_ENV['SIMORA_MIGRATION_ACTOR'] ?? '');
    $sha = getenv('SIMORA_BUILD_SHA') ?: ($_ENV['SIMORA_BUILD_SHA'] ?? '');
    if ($actor === '' || $sha === '') {
        throw new RuntimeException('Set SIMORA_MIGRATION_ACTOR and SIMORA_BUILD_SHA.');
    }
    $pdo = App\Infrastructure\DatabaseConnection::connect(true);
    $runner = new App\Infrastructure\MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
    foreach ($runner->migrate($argv[1] ?? '010_catalog', $actor, $sha) as $message) {
        echo $message . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
