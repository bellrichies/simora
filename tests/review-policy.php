<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Domain/Reviews/ReviewPolicy.php';

use App\Domain\Reviews\ReviewPolicy;

$policy = new ReviewPolicy();
foreach ([
    [true, true, true, false, true],
    [false, true, true, false, false],
    [true, false, true, false, false],
    [true, true, false, false, false],
    [true, true, true, true, false],
] as [$authenticated, $active, $verified, $hasReview, $expected]) {
    if ($policy->canSubmit($authenticated, $active, $verified, $hasReview) !== $expected) {
        throw new RuntimeException('Eligibility rule failed');
    }
}
foreach ([-1, 0, 1, 2, 3, 4, 5, 6] as $rating) {
    if ($policy->isValidRating($rating) !== ($rating >= 1 && $rating <= 5)) {
        throw new RuntimeException('Rating rule failed');
    }
}
foreach (['pending', 'published', 'rejected', 'hidden', 'withdrawn', 'removed'] as $state) {
    if ($policy->isPublic($state) !== ($state === 'published')) {
        throw new RuntimeException('Publication rule failed');
    }
}
echo "Review policy tests passed\n";
