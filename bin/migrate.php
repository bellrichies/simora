<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

try {
    $dsn = getenv('SIMORA_DB_DSN');
    $user = getenv('SIMORA_DB_USER');
    $actor = getenv('SIMORA_MIGRATION_ACTOR');
    $sha = getenv('SIMORA_BUILD_SHA');
    if (!$dsn || !str_starts_with($dsn, 'mysql:') || !$user || !$actor || !$sha) {
        throw new RuntimeException('Set SIMORA_DB_DSN, SIMORA_DB_USER, SIMORA_MIGRATION_ACTOR and SIMORA_BUILD_SHA.');
    }
    $pdo = new PDO($dsn, $user, getenv('SIMORA_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $runner = new App\Infrastructure\MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations');
    foreach ($runner->migrate($argv[1] ?? '010_catalog', $actor, $sha) as $message) {
        echo $message . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
