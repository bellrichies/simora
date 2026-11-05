<?php

declare(strict_types=1);

namespace App\Domain\Reviews;

/** Approved review eligibility and publication rules; identity remains authoritative. */
final class ReviewPolicy
{
    public function canSubmit(bool $authenticated, bool $active, bool $emailVerified, bool $hasReview): bool
    {
        return $authenticated && $active && $emailVerified && !$hasReview;
    }

    public function isValidRating(int $rating): bool
    {
        return $rating >= 1 && $rating <= 5;
    }

    public function isPublic(string $state): bool
    {
        return $state === 'published';
    }
}
