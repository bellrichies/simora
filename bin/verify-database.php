<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $pdo = App\Infrastructure\DatabaseConnection::connect();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') !== false || !preg_match('/^8\./', $version)) {
        throw new RuntimeException('MySQL 8 is required.');
    }
    if ($pdo->query('SELECT @@session.time_zone')->fetchColumn() !== '+00:00') {
        throw new RuntimeException('Database session must use UTC.');
    }
    $ledger = $pdo->query('SELECT id, checksum, status FROM schema_migrations ORDER BY id')->fetchAll();
    $expected = ['000_schema_ledger', '010_catalog', '020_identity', '030_source_provenance'];
    if (array_column($ledger, 'id') !== $expected) {
        throw new RuntimeException('Unexpected or incomplete migration ledger.');
    }
    $expectedTables = [];
    $expectedForeignKeys = [];
    foreach ($ledger as $row) {
        $path = dirname(__DIR__) . '/database/migrations/' . $row['id'] . '.sql';
        $checksum = hash_file('sha256', $path);
        if ($row['status'] !== 'succeeded' || !hash_equals($checksum, $row['checksum'])) {
            throw new RuntimeException('Migration mismatch: ' . $row['id']);
        }
        $sql = file_get_contents($path);
        preg_match_all('/CREATE TABLE (\w+)/', $sql, $tables);
        array_push($expectedTables, ...$tables[1]);
        preg_match_all('/CONSTRAINT (\w+) FOREIGN KEY \(([^)]+)\) REFERENCES (\w+)\(([^)]+)\)/', $sql, $keys, PREG_SET_ORDER);
        foreach ($keys as $key) {
            $expectedForeignKeys[$key[1]] = [str_replace(' ', '', $key[2]), $key[3], str_replace(' ', '', $key[4])];
        }
    }
    $actualTables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
    sort($expectedTables);
    if ($actualTables !== $expectedTables) {
        throw new RuntimeException('Physical table inventory differs from migration files.');
    }
    $markets = $pdo->query('SELECT id FROM markets ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $canonical = array_keys(App\Domain\MarketCatalog::all());
    sort($canonical);
    if ($markets !== $canonical) {
        throw new RuntimeException('Canonical market registry mismatch.');
    }
    $engines = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'")->fetchColumn();
    if ((int) $engines !== 0) {
        throw new RuntimeException('Non-InnoDB tables found.');
    }
    $foreignKeys = $pdo->query('SELECT table_name, constraint_name, referenced_table_name, delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() ORDER BY table_name, constraint_name')->fetchAll();
    $actualForeignKeys = [];
    $columns = $pdo->query('SELECT constraint_name AS fk_name, column_name AS col_name, referenced_table_name AS ref_table, referenced_column_name AS ref_column FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL ORDER BY constraint_name, ordinal_position')->fetchAll();
    foreach ($columns as $column) {
        $name = $column['fk_name'];
        $actualForeignKeys[$name][0][] = $column['col_name'];
        $actualForeignKeys[$name][1] = $column['ref_table'];
        $actualForeignKeys[$name][2][] = $column['ref_column'];
    }
    foreach ($actualForeignKeys as $name => $key) {
        $actualForeignKeys[$name] = [implode(',', $key[0]), $key[1], implode(',', $key[2])];
    }
    ksort($expectedForeignKeys);
    ksort($actualForeignKeys);
    if ($expectedForeignKeys !== $actualForeignKeys) {
        throw new RuntimeException('Physical foreign key columns differ from migration files.');
    }
    echo json_encode([
        'verified_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'mysql_version' => $version,
        'timezone' => '+00:00',
        'table_count' => count($actualTables),
        'foreign_key_count' => count($foreignKeys),
        'verified_migrations' => array_column($ledger, 'id'),
        'canonical_markets' => count($markets),
        'foreign_keys' => $foreignKeys,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database verification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
