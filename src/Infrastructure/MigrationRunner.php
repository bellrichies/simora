<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const DEPENDENCIES = [
        '000_schema_ledger' => [],
        '010_catalog' => ['000_schema_ledger'],
        '020_identity' => ['000_schema_ledger'],
        '030_source_provenance' => ['010_catalog'],
        '080_access_notifications' => ['020_identity'],
        '081_approved_access_policy' => ['080_access_notifications'],
        '090_payments_wallet_gated' => ['081_approved_access_policy', '030_source_provenance'],
        '100_experience_gated' => ['090_payments_wallet_gated'],
        '110_chat_optional' => ['100_experience_gated', '010_catalog'],
    ];

    public function __construct(private PDO $connection, private string $directory)
    {
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function migrate(string $through, string $actor, string $buildSha): array
    {
        if (!isset(self::DEPENDENCIES[$through]) || $actor === '' || strlen($actor) > 128
            || !preg_match('/^[a-zA-Z0-9._-]{1,64}$/D', $buildSha)) {
            throw new RuntimeException('Invalid migration target, actor or build SHA.');
        }
        $version = (string) $this->connection->query('SELECT VERSION()')->fetchColumn();
        if (stripos($version, 'mariadb') !== false || !preg_match('/^(\d+\.\d+\.\d+)/', $version, $match)
            || version_compare($match[1], '8.0.16', '<') || version_compare($match[1], '9.0.0', '>=')) {
            throw new RuntimeException('MySQL 8.0.16 or later in the 8.x series is required; found ' . $version);
        }
        $database = $this->connection->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('A target database must be selected.');
        }
        $lock = 'simora_migrate_' . substr(hash('sha256', $database), 0, 40);
        $statement = $this->connection->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire migration lock.');
        }
        try {
            return $this->runLocked($through, $actor, $buildSha);
        } finally {
            $release = $this->connection->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    private function runLocked(string $through, string $actor, string $buildSha): array
    {
        $results = [];
        $required = $this->requiredMigrations($through);
        foreach (self::DEPENDENCIES as $id => $dependencies) {
            if (!isset($required[$id])) {
                continue;
            }
            // Ingestion must not silently deploy the still-unverified catalog phase.
            if ($through === '030_source_provenance' && $id === '010_catalog') {
                continue;
            }
            $path = $this->directory . DIRECTORY_SEPARATOR . $id . '.sql';
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('Missing migration: ' . $id);
            }
            $checksum = hash('sha256', $sql);
            if ($id === '000_schema_ledger' && !$this->tableExists('schema_migrations')) {
                $started = microtime(true);
                $this->connection->exec($sql);
                $insert = $this->connection->prepare('INSERT INTO schema_migrations (id,checksum,applied_at,applied_by,duration_ms,dependencies,status,build_sha) VALUES (?,?,UTC_TIMESTAMP(6),?,?,?,\'succeeded\',?)');
                $insert->execute([$id, $checksum, $actor, (int) ((microtime(true) - $started) * 1000), '[]', $buildSha]);
                $results[] = $id . ': applied';
            } else {
                $query = $this->connection->prepare('SELECT checksum,status FROM schema_migrations WHERE id = ?');
                $query->execute([$id]);
                $row = $query->fetch(PDO::FETCH_ASSOC);
                if ($row !== false) {
                    if (!hash_equals($row['checksum'], $checksum) || $row['status'] !== 'succeeded') {
                        throw new RuntimeException('Migration checksum or state mismatch: ' . $id . '; manual recovery required.');
                    }
                    $results[] = $id . ': already applied';
                } else {
                    if ($id === '000_schema_ledger') {
                        throw new RuntimeException('Existing ledger is not owned by this runner; reconcile manually.');
                    }
                    $this->apply($id, $sql, $checksum, $dependencies, $actor, $buildSha);
                    $results[] = $id . ': applied';
                }
            }
            if ($id === $through) {
                break;
            }
        }
        return $results;
    }

    private function apply(string $id, string $sql, string $checksum, array $dependencies, string $actor, string $buildSha): void
    {
        foreach ($dependencies as $dependency) {
            $query = $this->connection->prepare('SELECT status, checksum FROM schema_migrations WHERE id = ?');
            $query->execute([$dependency]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $path = $this->directory . DIRECTORY_SEPARATOR . $dependency . '.sql';
            $dependencyChecksum = is_file($path) ? hash_file('sha256', $path) : false;
            if ($row === false || $row['status'] !== 'succeeded' || !is_string($dependencyChecksum)
                || !hash_equals($row['checksum'], $dependencyChecksum)) {
                throw new RuntimeException('Missing, failed or mismatched dependency: ' . $dependency);
            }
        }
        // Legacy and partially created tables need an operator-reviewed upgrade, not a parallel schema.
        $tables = match ($id) {
            '010_catalog' => ['platforms', 'leagues', 'league_weeks', 'teams', 'prediction_models', 'predictions',
                'virtual_leagues', 'league_seasons', 'team_aliases', 'virtual_rounds', 'football_matches', 'markets', 'market_rules']
            , '020_identity' => ['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'sessions',
                'email_verification_tokens', 'password_resets', 'audit_logs', 'settings'],
            '030_source_provenance' => ['data_sources', 'source_access_grants', 'source_league_mappings',
                'source_fixture_mappings', 'raw_source_payloads', 'ingestion_observations', 'collection_jobs',
                'collection_cursors', 'ingestion_batches', 'transactional_outbox', 'data_quality_issues', 'league_sync_metrics'],
            '080_access_notifications' => ['plans', 'plan_versions', 'plan_prices', 'subscriptions',
                'subscription_events', 'entitlements', 'usage_counters', 'usage_events',
                'notifications', 'notification_outbox'],
            '081_approved_access_policy' => [],
            '090_payments_wallet_gated' => ['invoices', 'payments', 'payment_webhooks', 'refunds',
                'wallet_accounts', 'wallet_journals', 'wallet_postings', 'wallet_balances', 'wallet_adjustments'],
            '100_experience_gated' => ['referral_codes', 'referral_attributions', 'referral_rewards', 'reviews',
                'review_moderation', 'support_tickets', 'support_messages', 'faq_articles'],
            '110_chat_optional' => ['chat_rooms', 'chat_memberships', 'chat_messages'],
            default => throw new RuntimeException('Unsupported migration.'),
        };
        foreach ($tables as $table) {
            if ($this->tableExists($table)) {
                throw new RuntimeException('Existing table ' . $table . ' requires reconciliation; no DDL for ' . $id . ' applied.');
            }
        }
        if ($id === '080_access_notifications') {
            $column = $this->connection->query("SELECT COUNT(*) FROM information_schema.columns "
                . "WHERE table_schema=DATABASE() AND table_name='sessions' AND column_name='csrf_token_hash'");
            if ((int) $column->fetchColumn() !== 0) {
                throw new RuntimeException('Existing session CSRF column requires reconciliation before access migration.');
            }
        }
        if ($id === '081_approved_access_policy') {
            $column = $this->connection->query("SELECT COUNT(*) FROM information_schema.columns "
                . "WHERE table_schema=DATABASE() AND table_name='plan_versions' AND column_name='approval_kind'");
            if ((int) $column->fetchColumn() !== 0) {
                throw new RuntimeException('Existing policy approval columns require reconciliation before DDL.');
            }
        }
        $started = microtime(true);
        $insert = $this->connection->prepare('INSERT INTO schema_migrations (id,checksum,applied_by,dependencies,status,build_sha) VALUES (?,?,?,?,\'running\',?)');
        $insert->execute([$id, $checksum, $actor, json_encode($dependencies, JSON_THROW_ON_ERROR), $buildSha]);
        try {
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) !== '') {
                    $this->connection->exec($statement);
                }
            }
            $this->finish($id, 'succeeded', $started);
        } catch (Throwable $exception) {
            $this->finish($id, 'failed', $started);
            throw $exception;
        }
    }

    /** @return array<string, true> */
    private function requiredMigrations(string $target): array
    {
        $required = [];
        $visit = function (string $id) use (&$visit, &$required): void {
            if (isset($required[$id])) {
                return;
            }
            $required[$id] = true;
            foreach (self::DEPENDENCIES[$id] as $dependency) {
                $visit($dependency);
            }
        };
        $visit($target);
        return $required;
    }

    private function finish(string $id, string $status, float $started): void
    {
        $query = $this->connection->prepare('UPDATE schema_migrations SET status = ?, applied_at = UTC_TIMESTAMP(6), duration_ms = ? WHERE id = ?');
        $query->execute([$status, (int) ((microtime(true) - $started) * 1000), $id]);
    }

    private function tableExists(string $table): bool
    {
        $query = $this->connection->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $query->execute([$table]);
        return (int) $query->fetchColumn() > 0;
    }
}
