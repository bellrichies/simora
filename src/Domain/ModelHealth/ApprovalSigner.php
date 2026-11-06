<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use InvalidArgumentException;

/** Server-side HMAC integrity, not a substitute for independent human approval. */
final class ApprovalSigner
{
    public function __construct(private readonly array $keys, private readonly string $currentKeyId)
    {
        if (!isset($keys[$currentKeyId])) {
            throw new InvalidArgumentException('Current approval signing key unavailable.');
        }
        foreach ($keys as $id => $key) {
            if (!is_string($id) || $id === '' || !is_string($key) || strlen($key) < 32) {
                throw new InvalidArgumentException('Invalid approval signing keyring.');
            }
        }
    }

    public function sign(array $claims): array
    {
        return ['claims' => $claims, 'key_id' => $this->currentKeyId,
            'signature' => hash_hmac('sha256', $this->encode($claims), $this->keys[$this->currentKeyId])];
    }

    public function verify(array $approval): bool
    {
        $keyId = $approval['key_id'] ?? null;
        return is_string($keyId) && isset($this->keys[$keyId])
            && is_array($approval['claims'] ?? null) && is_string($approval['signature'] ?? null)
            && hash_equals(
                hash_hmac('sha256', $this->encode($approval['claims']), $this->keys[$keyId]),
                $approval['signature']
            );
    }

    private function encode(array $claims): string
    {
        ksort($claims);
        return json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
