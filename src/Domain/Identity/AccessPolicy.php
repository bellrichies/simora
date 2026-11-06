<?php

declare(strict_types=1);

namespace App\Domain\Identity;

final class AccessPolicy
{
    /** @param list<string> $permissions */
    public function allows(array $permissions, string $permission): bool
    {
        return $permission !== '' && in_array($permission, $permissions, true);
    }

    public function owns(int $actorId, int $ownerId): bool
    {
        return $actorId > 0 && $actorId === $ownerId;
    }
}
