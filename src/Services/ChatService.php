<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;
use App\Domain\Experience\ExperienceException;
use App\Domain\Experience\ExperiencePolicy as Policy;
use App\Repositories\ChatRepository;

/** Optional, members-only, human-premoderated snapshot feed. The existing preview does not call it. */
final class ChatService
{
    public function __construct(
        private readonly ExperienceContext $context,
        private readonly ChatRepository $repository
    ) {
    }

    private function room(): array
    {
        $room = $this->repository->room();
        if (
            $room === null || $room['status'] !== 'open'
            || $room['policy_reference'] !== $this->context->policy->reference()
        ) {
            throw new ExperienceException('chat_room_unavailable', 503);
        }
        return $room;
    }

    private function member(int $actor, array $room): array
    {
        $member = $this->repository->membership((int) $room['id'], $actor);
        if (
            $member === null || $member['status'] !== 'active'
            || $member['consent_reference'] !== $this->context->policy->reference()
        ) {
            throw new ExperienceException('chat_membership_required', 403);
        }
        return $member;
    }

    public function configure(SessionCredentials $credentials, bool $open): array
    {
        return $this->context->run($credentials, 'chat', 'chat.manage', true, function (int $actor) use ($open) {
            $row = $this->repository->configureRoom($this->context->policy->reference(), $open);
            $this->context->write(
                $actor,
                $open ? 'chat.room.opened' : 'chat.room.closed',
                'chat_room',
                $row['public_id'],
                'room:' . $row['version']
            );
            return ['id' => $row['public_id'], 'status' => $row['status']];
        });
    }

    public function join(SessionCredentials $credentials, string $consent): array
    {
        return $this->context->run($credentials, 'chat', 'chat.join', true, function (int $actor) use ($consent) {
            $room = $this->room();
            if (!hash_equals($this->context->policy->reference(), $consent)) {
                throw new ExperienceException('chat_consent_required', 403);
            }
            $prior = $this->repository->membership((int) $room['id'], $actor);
            if ($prior !== null && $prior['status'] === 'banned') {
                throw new ExperienceException('chat_membership_required', 403);
            }
            if ($prior === null || $prior['consent_reference'] !== $consent) {
                $this->repository->join((int) $room['id'], $actor, $consent);
                $this->context->write($actor, 'chat.joined', 'chat_room', $room['public_id'], 'consent');
            }
            return ['room_id' => $room['public_id'], 'joined' => true];
        });
    }

    public function listing(SessionCredentials $credentials, int $before, int $limit, bool $queue = false): array
    {
        return $this->context->run(
            $credentials,
            'chat',
            $queue ? 'chat.moderate' : 'chat.read',
            false,
            function (int $actor) use ($before, $limit, $queue) {
                $room = $this->room();
                $this->member($actor, $room);
                $rows = $this->repository->messages((int) $room['id'], $queue, $before, $limit);
                return ['items' => array_map(fn ($row) => $this->present($row, $queue), $rows),
                    'next_before_id' => $rows ? (string) end($rows)['id'] : null, 'snapshot' => true];
            }
        );
    }

    public function send(SessionCredentials $credentials, string $body, string $key): array
    {
        Policy::key($key);
        $body = Policy::text($body, 1, 1000, true);
        $hash = Policy::fingerprint([$body]);
        return $this->context->run($credentials, 'chat', 'chat.send', true, function (int $actor) use (
            $body,
            $key,
            $hash
) {
            $room = $this->room();
            $member = $this->member($actor, $room);
            if ($member['muted_until'] !== null && $member['muted_until'] > $this->context->repository->now()) {
                throw new ExperienceException('chat_muted', 403);
            }
            $row = $this->repository->prior($actor, $key);
            if ($row !== null) {
                Policy::replay($row, $hash);
            } else {
                $row = $this->repository->send((int) $room['id'], $actor, $body, $key, $hash);
                $this->context->write($actor, 'chat.message.submitted', 'chat_message', $row['public_id'], $key);
            }
            return ['id' => $row['public_id'], 'accepted_for_moderation' => true];
        });
    }

    public function moderate(
        SessionCredentials $credentials,
        string $id,
        bool $publish,
        string $reason,
        int $version
    ): array {
        if (
            !in_array($reason, ['approved', 'spam', 'privacy', 'abuse', 'off_topic'], true)
            || ($publish && $reason !== 'approved')
        ) {
            throw new ExperienceException('invalid_moderation_reason', 422);
        }
        return $this->context->run(
            $credentials,
            'chat',
            'chat.moderate',
            true,
            function (int $actor) use ($id, $publish, $reason, $version) {
                $room = $this->room();
                $this->member($actor, $room);
                $row = $this->repository->message($id);
                if ($row === null || (int) $row['room_id'] !== (int) $room['id']) {
                    throw new ExperienceException('not_found', 404);
                }
                if ($publish && (int) $row['user_id'] === $actor) {
                    throw new ExperienceException('independent_moderator_required', 403);
                }
                Policy::version($row, $version);
                if ($row['status'] !== ($publish ? 'pending' : 'published')) {
                    throw new ExperienceException('invalid_transition');
                }
                $updated = $this->repository->moderate($id, $actor, $publish, $reason);
                $this->context->write(
                    $actor,
                    $publish ? 'chat.published' : 'chat.hidden',
                    'chat_message',
                    $id,
                    'moderation:' . $version . ':' . $reason
                );
                return $this->present($updated, true);
            }
        );
    }

    public function report(SessionCredentials $credentials, string $id, string $reason): array
    {
        if (!in_array($reason, ['spam', 'privacy', 'abuse', 'off_topic'], true)) {
            throw new ExperienceException('invalid_report_reason', 422);
        }
        return $this->context->run($credentials, 'chat', 'chat.read', true, function (int $actor) use ($id, $reason) {
            $room = $this->room();
            $this->member($actor, $room);
            $row = $this->repository->message($id);
            if ($row === null || $row['status'] !== 'published' || (int) $row['room_id'] !== (int) $room['id']) {
                throw new ExperienceException('not_found', 404);
            }
            if (!$this->repository->reportExists($actor, $id)) {
                $this->context->write($actor, 'chat.reported', 'chat_message', $id, 'reason:' . $reason);
            }
            return ['received' => true];
        });
    }

    public function restrict(SessionCredentials $credentials, int $user, bool $ban, int $minutes): array
    {
        if ($user < 1 || $minutes < 0 || $minutes > 10080) {
            throw new ExperienceException('invalid_chat_restriction', 422);
        }
        return $this->context->run(
            $credentials,
            'chat',
            'chat.manage',
            true,
            function (int $actor) use ($user, $ban, $minutes) {
                $room = $this->room();
                if ($actor === $user || $this->repository->membership((int) $room['id'], $user) === null) {
                    throw new ExperienceException('invalid_chat_restriction', 422);
                }
                $this->repository->restrict((int) $room['id'], $user, $ban, $minutes);
                $this->context->write(
                    $actor,
                    'chat.member.restricted',
                    'chat_membership',
                    $room['id'] . ':' . $user,
                    ($ban ? 'ban' : 'allow') . ':mute:' . $minutes
                );
                return ['updated' => true];
            }
        );
    }

    public function reports(SessionCredentials $credentials, int $before, int $limit): array
    {
        return $this->context->run(
            $credentials,
            'chat',
            'chat.moderate',
            false,
            function (int $actor) use ($before, $limit) {
                $room = $this->room();
                $this->member($actor, $room);
                $rows = $this->repository->reports($before, $limit);
                foreach ($rows as &$row) {
                    $row['occurred_at'] = Policy::utc($row['occurred_at']);
                }
                return ['items' => $rows, 'next_before_id' => $rows ? (string) end($rows)['id'] : null];
            }
        );
    }

    private function present(array $row, bool $private): array
    {
        $result = ['id' => $row['public_id'], 'body' => $row['body'], 'author' => 'Community member',
            'created_at' => Policy::utc($row['created_at'])];
        if ($private) {
            $result['author_user_id'] = (string) $row['user_id'];
            $result['status'] = $row['status'];
            $result['version'] = (int) $row['version'];
        }
        return $result;
    }
}
