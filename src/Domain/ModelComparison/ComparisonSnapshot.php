<?php

declare(strict_types=1);

namespace App\Domain\ModelComparison;

use JsonException;
use RuntimeException;

/** Verify the exact Python payload bytes without cross-language JSON re-encoding. */
final class ComparisonSnapshot
{
    public const SCHEMA = 'simora.comparison-snapshot.v1';

    public function __construct(private readonly array $keys)
    {
        foreach ($keys as $keyId => $key) {
            if (
                !is_string($keyId) || $keyId === '' || str_contains($keyId, "\n")
                || !is_string($key) || strlen($key) < 32
            ) {
                throw new RuntimeException('Invalid report verification keyring.');
            }
        }
    }

    public function verify(string $envelopeJson, string $expectedSha256): array
    {
        if (strlen($envelopeJson) > 32 * 1024 * 1024) {
            throw new RuntimeException('Report snapshot exceeds size bound.');
        }
        try {
            $envelope = json_decode($envelopeJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($envelope)) {
                throw new RuntimeException('Malformed snapshot envelope.');
            }
            if (!is_string($envelope['key_id'] ?? null)) {
                throw new RuntimeException('Snapshot verification key identity required.');
            }
            $key = $this->keys[$envelope['key_id'] ?? ''] ?? null;
            $payload = $envelope['payload_json'] ?? null;
            if (
                ($envelope['schema_version'] ?? null) !== self::SCHEMA || $key === null
                || !is_string($payload) || !is_string($envelope['signature'] ?? null)
                || !hash_equals($expectedSha256, hash('sha256', $payload))
                || ($envelope['payload_sha256'] ?? null) !== $expectedSha256
                || !hash_equals(hash_hmac('sha256', self::SCHEMA . "\n" . $envelope['key_id']
                    . "\n" . $expectedSha256, $key), $envelope['signature'])
            ) {
                throw new RuntimeException('Report snapshot hash/signature mismatch.');
            }
            $report = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($report) || ($report['schema_version'] ?? null) !== 'simora.comparison-report.v1') {
                throw new RuntimeException('Unsupported comparison report schema.');
            }
            return $report;
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed comparison snapshot.', 0, $exception);
        }
    }
}
