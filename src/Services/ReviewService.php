<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;
use App\Domain\Experience\ExperienceException;
use App\Domain\Experience\ExperiencePolicy as Policy;
use App\Domain\Reviews\ReviewPolicy;

final class ReviewService
{
    public function __construct(private readonly ExperienceContext $context, private readonly ReviewPolicy $rules)
    {
    }

    public function listing(SessionCredentials $credentials, int $before, int $limit, bool $queue = false): array
    {
        return $this->context->run(
            $credentials,
            'reviews',
            $queue ? 'reviews.moderate' : 'reviews.read',
            false,
            function (int $actor) use ($before, $limit, $queue) {
                $rows = $this->context->repository->reviews($queue, $before, $limit);
                return ['items' => array_map(fn ($row) => $this->present($row, $queue), $rows),
                    'next_before_id' => $rows ? (string) end($rows)['id'] : null];
            }
        );
    }

    public function own(SessionCredentials $credentials): ?array
    {
        return $this->context->run($credentials, 'reviews', 'reviews.read', false, function (int $actor) {
            $row = $this->context->repository->reviewByOwner($actor);
            return $row === null ? null : $this->present($row, true);
        });
    }

    public function submit(
        SessionCredentials $credentials,
        int $rating,
        string $title,
        string $body,
        string $key
    ): array {
        Policy::key($key);
        $title = Policy::text($title, 1, 120, true);
        $body = Policy::text($body, 20, 2000, true);
        if (!$this->rules->isValidRating($rating)) {
            throw new ExperienceException('invalid_rating', 422);
        }
        $hash = Policy::fingerprint([$rating, $title, $body]);
        return $this->context->run(
            $credentials,
            'reviews',
            'reviews.submit',
            true,
            function (int $actor) use ($rating, $title, $body, $key, $hash) {
                $r = $this->context->repository;
                $review = $r->reviewByOwner($actor);
                if ($review !== null) {
                    if ($review['request_key'] !== $key) {
                        throw new ExperienceException('review_already_exists');
                    }
                    Policy::replay($review, $hash);
                } else {
                    $review = $r->createReview(
                        $actor,
                        $rating,
                        $title,
                        $body,
                        $this->context->policy->reference(),
                        $key,
                        $hash
                    );
                    $this->context->write($actor, 'review.submitted', 'review', $review['public_id'], $key);
                    $r->emitReview($review);
                }
                return ['id' => $review['public_id'], 'accepted_for_moderation' => true];
            }
        );
    }

    public function decide(
        SessionCredentials $credentials,
        string $id,
        string $action,
        string $reason,
        int $version,
        string $key
    ): array {
        Policy::key($key);
        $withdraw = $action === 'withdraw';
        $hash = Policy::fingerprint([$id, $action, $reason, $version]);
        return $this->context->run(
            $credentials,
            'reviews',
            $withdraw ? 'reviews.submit' : 'reviews.moderate',
            true,
            function (int $actor) use ($id, $action, $reason, $version, $key, $hash, $withdraw) {
                $r = $this->context->repository;
                $row = $r->review($id);
                if ($row === null || ($withdraw && (int) $row['author_user_id'] !== $actor)) {
                    throw new ExperienceException('not_found', 404);
                }
                if (!$withdraw && (int) $row['author_user_id'] === $actor) {
                    throw new ExperienceException('independent_moderator_required', 403);
                }
                $prior = $r->moderation($actor, $key);
                if ($prior !== null) {
                    Policy::replay($prior, $hash);
                    return ['id' => $id, 'status' => $prior['to_status'],
                        'version' => (int) $prior['resulting_version']];
                }
                Policy::version($row, $version);
                $state = match ($action) {
                    'approve' => 'published', 'reject' => 'rejected', 'hide' => 'hidden', 'withdraw' => 'withdrawn',
                    default => throw new ExperienceException('invalid_moderation_action', 422),
                };
                $allowed = match ($action) {
                    'approve' => ['pending', 'hidden'], 'reject' => ['pending'], 'hide' => ['published'],
                    'withdraw' => ['pending', 'published', 'rejected', 'hidden'],
                };
                if (!in_array($row['status'], $allowed, true)) {
                    throw new ExperienceException('invalid_transition');
                }
                if (
                    !in_array($reason, ['approved', 'spam', 'privacy', 'abuse', 'off_topic', 'owner_withdrawal'], true)
                    || ($withdraw && $reason !== 'owner_withdrawal') || ($action === 'approve'
                        && $reason !== 'approved')
                ) {
                    throw new ExperienceException('invalid_moderation_reason', 422);
                }
                $updated = $r->decideReview(
                    $row,
                    $actor,
                    $state,
                    $reason,
                    $this->context->policy->reference(),
                    $key,
                    $hash
                );
                $this->context->write($actor, 'review.' . $action, 'review', $id, $key);
                $r->emitReview($updated);
                $this->context->notify(
                    (int) $row['author_user_id'],
                    $actor,
                    'review:decision:' . $row['id'] . ':' . $updated['version'],
                    'review.decision',
                    ['review_id' => $id, 'status' => $state]
                );
                return ['id' => $id, 'status' => $state, 'version' => (int) $updated['version']];
            }
        );
    }

    private function present(array $row, bool $private): array
    {
        $result = ['id' => $row['public_id'], 'rating' => (int) $row['rating'], 'title' => $row['title'],
            'body' => $row['body'], 'author' => 'Community member',
            'published_at' => $row['published_at'] === null ? null : Policy::utc($row['published_at'])];
        if ($private) {
            $result['status'] = $row['status'];
            $result['version'] = (int) $row['version'];
        }
        return $result;
    }
}
