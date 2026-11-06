<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Infrastructure\DatabaseConnection;
use App\Infrastructure\MigrationRunner;

$pdo = DatabaseConnection::connect();
if (!in_array($pdo->query('SELECT DATABASE()')->fetchColumn(), ['simora_dev', 'simora_test'], true)) {
    throw new RuntimeException('Run database integration tests only against simora_dev or simora_test.');
}
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$reject = static function (callable $write, int $expectedCode) use ($check): void {
    try {
        $write();
    } catch (PDOException $exception) {
        $check(($exception->errorInfo[1] ?? null) === $expectedCode, 'Unexpected database rejection code.');
        return;
    }
    throw new RuntimeException('Invalid write was accepted.');
};
$token = 'test_' . bin2hex(random_bytes(8));
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO virtual_leagues (id,platform_id,name,league_type) VALUES (?,\'sportybet\',?,\'scheduled\')');
    $insert->execute([$token, $token]);
    $invalidLeague = $pdo->prepare('INSERT INTO virtual_leagues (id,platform_id,name,league_type) VALUES (?, ?, ?, \'scheduled\')');
    $reject(static fn () => $invalidLeague->execute([$token . '_invalid', 'missing_platform', 'invalid']), 1452);
    $pdo->prepare("INSERT INTO data_sources (code,source_type,access_status) VALUES (?, 'test', 'pending')")->execute([$token]);
    $sourceId = $pdo->lastInsertId();
    $grant = $pdo->prepare('INSERT INTO source_access_grants (source_id,scope_key,access_method,permission_code,evidence_reference,valid_from,expires_at) VALUES (?, ?, \'test\', \'ingest\', \'test-only\', ?, ?)');
    $reject(static fn () => $grant->execute([$sourceId, $token, '2026-01-02', '2026-01-01']), 3819);
    $batch = $pdo->prepare("INSERT INTO ingestion_batches (source_id,idempotency_key,fingerprint,partition_key,committed_at) VALUES (?, ?, ?, 'synthetic', UTC_TIMESTAMP(6))");
    $batch->execute([$sourceId, $token, str_repeat('a', 64)]);
    $batchId = $pdo->lastInsertId();
    $reject(static fn () => $batch->execute([$sourceId, $token, str_repeat('b', 64)]), 1062);
    $payload = $pdo->prepare("INSERT INTO raw_source_payloads (source_id,batch_id,storage_uri,sha256,parser_version,observed_at,available_at,ingested_at) VALUES (?, ?, 'test://rollback-only', ?, 'test-v1', '2026-01-02', '2026-01-01', '2026-01-02')");
    $reject(static fn () => $payload->execute([$sourceId, $batchId, str_repeat('c', 64)]), 3819);
    $outbox = $pdo->prepare("INSERT INTO transactional_outbox (event_key,batch_id,event_type,payload,created_at) VALUES (?, ?, 'test', '{}', UTC_TIMESTAMP(6))");
    $outbox->execute([$token, $batchId]);
} finally {
    $pdo->rollBack();
}
$query = $pdo->prepare('SELECT COUNT(*) FROM virtual_leagues WHERE id = ?');
$query->execute([$token]);
$check((int) $query->fetchColumn() === 0, 'Transaction did not roll back.');
$query = $pdo->prepare('SELECT COUNT(*) FROM transactional_outbox WHERE event_key = ?');
$query->execute([$token]);
$check((int) $query->fetchColumn() === 0, 'Outbox survived batch rollback.');
$reject(static fn () => $pdo->exec('CREATE TABLE simora_privilege_probe (id INT)'), 1142);
$migrator = DatabaseConnection::connect(true);
$migrator->beginTransaction();
try {
    $migrator->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE id = '030_source_provenance'");
    $runner = new MigrationRunner($migrator, dirname(__DIR__) . '/database/migrations');
    $rejected = false;
    try {
        $runner->migrate('030_source_provenance', 'integration_test', 'test');
    } catch (RuntimeException $exception) {
        $rejected = str_contains($exception->getMessage(), 'checksum or state mismatch');
    }
    $check($rejected, 'Migration accepted a corrupted ledger checksum.');
} finally {
    $migrator->rollBack();
}
echo "MySQL prerequisite integration tests passed\n";
