<?php

declare(strict_types=1);

namespace App\Domain\Experience;

use DateTimeImmutable;
use DateTimeZone;

/** Trusted deployment policy; references are operator-supplied evidence, never client fields. */
final class ExperiencePolicy
{
    public function __construct(private readonly array $config)
    {
    }

    public function requireModule(string $module, string $now): void
    {
        if (($this->config['features'][$module] ?? false) !== true) {
            throw new ExperienceException('experience_feature_disabled', 503);
        }
        foreach (['approval_reference', 'privacy_reference', 'moderation_reference', 'retention_reference'] as $key) {
            if (
                !is_string($this->config[$key] ?? null) || trim($this->config[$key]) === ''
                || strlen($this->config[$key]) > 255
            ) {
                throw new ExperienceException('experience_approval_required', 503);
            }
        }
        try {
            $expires = new DateTimeImmutable($this->config['expires_at'] ?? '1970-01-01T00:00:00Z');
        } catch (\Exception) {
            throw new ExperienceException('experience_approval_required', 503);
        }
        if ($expires <= new DateTimeImmutable($now, new DateTimeZone('UTC'))) {
            throw new ExperienceException('experience_approval_expired', 503);
        }
        foreach (['write_limit_minute', 'read_limit_minute', 'tickets_limit_day'] as $key) {
            if (!is_int($this->config[$key] ?? null) || $this->config[$key] < 1 || $this->config[$key] > 1000) {
                throw new ExperienceException('experience_abuse_policy_required', 503);
            }
        }
        if ($module === 'chat') {
            foreach (
                ['moderation_owner', 'privacy_owner', 'abuse_process_reference',
                'rights_process_reference'] as $key
            ) {
                if (
                    !is_string($this->config[$key] ?? null) || trim($this->config[$key]) === ''
                    || strlen($this->config[$key]) > 255
                ) {
                    throw new ExperienceException('chat_operational_approval_required', 503);
                }
            }
        }
    }

    public function reference(): string
    {
        return $this->config['approval_reference'];
    }

    public function limit(string $key): int
    {
        return $this->config[$key];
    }

    public static function text(mixed $value, int $minimum, int $maximum, bool $public = false): string
    {
        if (!is_string($value) || strlen($value) > $maximum * 4 + 64 || !mb_check_encoding($value, 'UTF-8')) {
            throw new ExperienceException('invalid_text', 422);
        }
        $value = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
        $length = mb_strlen($value, 'UTF-8');
        if (
            $length < $minimum || $length > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || preg_match('/\A[\s\p{Z}\p{Cf}]*\z/u', $value)
        ) {
            throw new ExperienceException('invalid_text', 422);
        }
        $sensitive = '/<[^>]*>|https?:\/\/|[\w.+-]+@[\w.-]+\.[a-z]{2,}|[0-9][0-9 ()+-]{7,}[0-9]/iu';
        if ($public && preg_match($sensitive, $value)) {
            throw new ExperienceException('public_content_requires_redaction', 422);
        }
        return $value;
    }

    public static function key(string $key): void
    {
        if (preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $key) !== 1) {
            throw new ExperienceException('invalid_request_key', 422);
        }
    }

    public static function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public static function replay(array $row, string $fingerprint): void
    {
        if (!hash_equals($row['fingerprint'], $fingerprint)) {
            throw new ExperienceException('idempotency_conflict');
        }
    }

    public static function version(array $row, int $version): void
    {
        if ($version < 1 || (int) $row['version'] !== $version) {
            throw new ExperienceException('stale_version');
        }
    }

    public static function utc(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }
}
