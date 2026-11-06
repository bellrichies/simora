<?php

declare(strict_types=1);

namespace App\Domain\ModelHealth;

use DateTimeImmutable;
use InvalidArgumentException;

final class DeploymentAssignment
{
    public function __construct(
        public readonly string $id,
        public readonly string $versionId,
        public readonly DeploymentScope $scope,
        public readonly string $channel,
        public readonly int $basisPoints,
        public readonly DateTimeImmutable $effectiveAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $approval,
        public readonly ?DateTimeImmutable $endedAt = null,
        public readonly ?string $rollbackOf = null
    ) {
        if (
            $id === '' || $versionId === '' || !in_array($channel, ['shadow', 'limited'], true)
            || $basisPoints < 1 || $basisPoints > 10000
            || ($channel === 'shadow' && $basisPoints !== 10000)
            || $expiresAt <= $effectiveAt || ($endedAt !== null && $endedAt < $effectiveAt)
        ) {
            throw new InvalidArgumentException('Invalid assignment; production channel is gated.');
        }
    }

    public function active(DateTimeImmutable $at): bool
    {
        return $this->effectiveAt <= $at && $at < $this->expiresAt
            && ($this->endedAt === null || $at < $this->endedAt);
    }

    public function end(DateTimeImmutable $at): self
    {
        return new self(
            $this->id,
            $this->versionId,
            $this->scope,
            $this->channel,
            $this->basisPoints,
            $this->effectiveAt,
            $this->expiresAt,
            $this->approval,
            $at,
            $this->rollbackOf
        );
    }

    /** The server must provide a stable authenticated subject, never a caller-chosen nonce. */
    public function includes(string $subjectId): bool
    {
        if ($subjectId === '') {
            return false;
        }
        // Scope-stable enrollment avoids reshuffling users on every release.
        $bucket = hexdec(substr(hash('sha256', $this->scope->key() . ':' . $subjectId), 0, 8)) % 10000;
        return $bucket < $this->basisPoints;
    }
}
