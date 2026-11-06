<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;
use Throwable;

/** Database primitives only; callers must enforce HTTPS, CSRF, rate limits and request authorization. */
final class IdentityRepository
{
    public function __construct(private PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function findActiveUserByEmail(string $normalizedEmail): ?array
    {
        $query = $this->db->prepare("SELECT id, normalized_email, password_hash FROM users WHERE normalized_email = ? AND status = 'active'");
        $query->execute([$normalizedEmail]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function findSession(string $tokenHash): ?array
    {
        $query = $this->db->prepare("SELECT s.id, s.user_id FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND u.status = 'active'");
        $query->execute([$tokenHash]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<string> */
    public function permissions(int $userId): array
    {
        $query = $this->db->prepare('SELECT DISTINCT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id JOIN user_roles ur ON ur.role_id = rp.role_id JOIN users u ON u.id = ur.user_id WHERE ur.user_id = ? AND u.status = \'active\'');
        $query->execute([$userId]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public function createSession(int $userId, string $tokenHash, string $expiresAt, string $correlationId): void
    {
        if (strlen($tokenHash) !== 32) {
            throw new \InvalidArgumentException('Invalid token digest.');
        }
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare('INSERT INTO sessions (user_id,token_hash,created_at,expires_at) VALUES (?,?,UTC_TIMESTAMP(6),?)');
            $insert->execute([$userId, $tokenHash, $expiresAt]);
            $this->audit($userId, 'session.created', 'session', (string) $this->db->lastInsertId(), $correlationId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function revokeSession(int $userId, int $sessionId, string $correlationId): bool
    {
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('UPDATE sessions SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
            $query->execute([$sessionId, $userId]);
            $changed = $query->rowCount() === 1;
            if ($changed) {
                $this->audit($userId, 'session.revoked', 'session', (string) $sessionId, $correlationId);
            }
            $this->db->commit();
            return $changed;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateSetting(int $userId, string $key, string $value, int $expectedVersion, string $correlationId): void
    {
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('UPDATE settings SET setting_value = ?, version = version + 1, updated_at = UTC_TIMESTAMP(6) WHERE user_id = ? AND setting_key = ? AND version = ?');
            $query->execute([$value, $userId, $key, $expectedVersion]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Setting missing or version conflict.');
            }
            $this->audit($userId, 'setting.updated', 'setting', $key, $correlationId);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    private function audit(int $actor, string $operation, string $type, string $id, string $correlationId): void
    {
        $query = $this->db->prepare('INSERT INTO audit_logs (actor_user_id,operation,object_type,object_id,correlation_id,occurred_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
        $query->execute([$actor, $operation, $type, $id, $correlationId]);
    }
}
