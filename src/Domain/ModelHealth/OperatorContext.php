<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use App\Domain\Identity\Csrf;
use DateTimeImmutable;
use RuntimeException;

/** Construct only from a verified active server-side session/RBAC lookup. */
final class OperatorContext
{
    public function __construct(
        public readonly int $userId,
        private readonly array $permissions,
        private readonly DateTimeImmutable $expiresAt,
        private readonly bool $https,
        private readonly ?string $sessionCsrf,
        private readonly ?string $providedCsrf
    ) {
    }

    public function authorize(string $permission, DateTimeImmutable $now): void
    {
        if (
            $this->userId < 1 || !$this->https || $this->expiresAt <= $now
            || !in_array($permission, $this->permissions, true)
            || !(new Csrf())->verify($this->sessionCsrf, $this->providedCsrf)
        ) {
            throw new RuntimeException('Model operation unauthorized.');
        }
    }
}
