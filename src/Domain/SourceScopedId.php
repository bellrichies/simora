<?php

declare(strict_types=1);

namespace App\Domain;

/** Stable opaque ID. Source and scope are length-prefixed before hashing to prevent collisions. */
final class SourceScopedId
{
    public static function fromExternal(string $entity, string $source, string $scope, string $externalId): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $entity)) {
            throw new \InvalidArgumentException('Invalid entity type.');
        }
        foreach ([$source, $scope, $externalId] as $value) {
            if ($value === '' || strlen($value) > 4096) {
                throw new \InvalidArgumentException('Source, scope and external ID must be non-empty and bounded.');
            }
        }
        $parts = [$entity, $source, $scope, $externalId];
        $encoded = '';
        foreach ($parts as $part) {
            $encoded .= pack('N', strlen($part)) . $part;
        }
        return $entity . '_' . hash('sha256', $encoded);
    }
}
