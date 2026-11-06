<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use DateTimeImmutable;

final class GrantPolicy
{
    /** A matching grant is necessary for each use; catalog listing is never authorization. */
    public function permits(array $grant, string $source, string $scope, string $method, string $use, DateTimeImmutable $at): bool
    {
        if ($source === '' || $scope === '' || $method === '' || $use === ''
            || ($grant['source'] ?? null) !== $source || ($grant['scope'] ?? null) !== $scope
            || ($grant['method'] ?? null) !== $method || ($grant['use'] ?? null) !== $use
            || ($grant['source_status'] ?? null) !== 'approved'
            || !is_string($grant['evidence'] ?? null) || trim($grant['evidence']) === ''
            || !($grant['valid_from'] ?? null) instanceof DateTimeImmutable
            || !($grant['expires_at'] ?? null) instanceof DateTimeImmutable
            || (($grant['revoked_at'] ?? null) !== null && !($grant['revoked_at'] instanceof DateTimeImmutable))) {
            return false;
        }
        return $grant['valid_from'] <= $at && $at < $grant['expires_at']
            && (($grant['revoked_at'] ?? null) === null || $at < $grant['revoked_at']);
    }
}
