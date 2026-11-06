<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

final class Idempotency
{
    /** The fingerprint must cover every input that changes the transaction's effects. */
    public function fingerprint(array $parts): string
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('Fingerprint inputs are required.');
        }
        $encoded = '';
        foreach ($parts as $part) {
            if (!is_string($part) || strlen($part) > 1048576) {
                throw new \InvalidArgumentException('Fingerprint inputs must be bounded strings.');
            }
            $encoded .= pack('N', strlen($part)) . $part;
        }
        return hash('sha256', $encoded);
    }

    public function isReplay(string $storedFingerprint, string $incomingFingerprint): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $storedFingerprint)
            || !preg_match('/^[a-f0-9]{64}$/D', $incomingFingerprint)) {
            throw new \InvalidArgumentException('Invalid fingerprint.');
        }
        return hash_equals($storedFingerprint, $incomingFingerprint);
    }
}
