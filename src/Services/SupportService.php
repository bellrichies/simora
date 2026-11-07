<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Access\SessionCredentials;
use App\Domain\Experience\ExperienceException;
use App\Domain\Experience\ExperiencePolicy as Policy;

final class SupportService
{
    public function __construct(private readonly ExperienceContext $context)
    {
    }

    public function listing(SessionCredentials $credentials, int $before, int $limit, bool $triage = false): array
    {
        return $this->context->run(
            $credentials,
            'support',
            $triage ? 'support.triage' : 'support.read',
            false,
            function (int $actor) use ($before, $limit, $triage) {
                $restricted = $this->context->access->permitted($actor, 'support.restricted');
                $rows = $this->context->repository->tickets($actor, $triage, $restricted, $before, $limit);
                return ['items' => array_map(fn ($row) => $this->ticketDto($row), $rows),
                    'next_before_id' => $rows ? (string) end($rows)['id'] : null];
            }
        );
    }

    public function create(
        SessionCredentials $credentials,
        string $subject,
        string $category,
        string $body,
        string $key
    ): array {
        Policy::key($key);
        $subject = Policy::text($subject, 3, 160);
        $body = Policy::text($body, 10, 5000);
        if (!in_array($category, ['general', 'account', 'billing', 'technical', 'privacy_security'], true)) {
            throw new ExperienceException('invalid_category', 422);
        }
        $hash = Policy::fingerprint([$subject, $category, $body]);
        return $this->context->run(
            $credentials,
            'support',
            'support.create',
            true,
            function (int $actor) use ($subject, $category, $body, $key, $hash) {
                $r = $this->context->repository;
                $prior = $r->ticketRequest($actor, $key);
                if ($prior !== null) {
                    Policy::replay($prior, $hash);
                    return ['id' => $prior['public_id'], 'accepted' => true];
                }
                $r->rate($actor, 'support.ticket.created', 86400, $this->context->policy->limit('tickets_limit_day'));
                $ticket = $r->createTicket(
                    $actor,
                    $subject,
                    $category,
                    $this->context->policy->reference(),
                    $key,
                    $hash
                );
                $r->message($ticket, $actor, $body, 'customer', 'reply', 'initial:' . $ticket['public_id'], $hash, 1);
                $this->context->write($actor, 'support.ticket.created', 'support_ticket', $ticket['public_id'], $key);
                return ['id' => $ticket['public_id'], 'accepted' => true];
            }
        );
    }

    private function authorizeTicket(string $id, int $actor, bool $manager = false): array
    {
        $row = $this->context->repository->ticket($id);
        if ($row === null) {
            throw new ExperienceException('not_found', 404);
        }
        $owner = (int) $row['owner_user_id'] === $actor;
        $agent = (int) $row['assigned_user_id'] === $actor && $this->context->access->permitted(
            $actor,
            'support.agent'
        );
        if (
            (!$owner && !$agent && !$manager) || (!$owner && $row['category'] === 'privacy_security'
            && !$this->context->access->permitted($actor, 'support.restricted'))
        ) {
            throw new ExperienceException('not_found', 404);
        }
        return $row;
    }

    public function detail(
        SessionCredentials $credentials,
        string $id,
        int $before,
        int $limit,
        bool $staff = false
    ): array {
        return $this->context->run(
            $credentials,
            'support',
            $staff ? 'support.agent' : 'support.read',
            false,
            function (int $actor) use ($id, $before, $limit, $staff) {
                $ticket = $this->authorizeTicket($id, $actor);
                if (!$staff && (int) $ticket['owner_user_id'] !== $actor) {
                    throw new ExperienceException('not_found', 404);
                }
                if ($staff && (int) $ticket['owner_user_id'] === $actor) {
                    throw new ExperienceException('independent_support_agent_required', 403);
                }
                $rows = $this->context->repository->messages((int) $ticket['id'], $staff, $before, $limit);
                return ['ticket' => $this->ticketDto($ticket),
                    'messages' => array_map(fn ($row) => ['id' => $row['public_id'], 'body' => $row['body'],
                        'visibility' => $row['visibility'],
                        'author' => $row['actor_user_id'] === $row['owner_user_id'] ? 'Customer' : 'Support',
                        'created_at' => Policy::utc($row['created_at'])], $rows),
                    'next_before_id' => $rows ? (string) end($rows)['id'] : null];
            }
        );
    }

    public function reply(
        SessionCredentials $credentials,
        string $id,
        string $body,
        string $action,
        int $version,
        string $key,
        bool $staff = false
    ): array {
        Policy::key($key);
        $body = Policy::text($body, 1, 5000);
        if (!in_array($action, $staff ? ['reply', 'note', 'resolve'] : ['reply'], true)) {
            throw new ExperienceException('invalid_support_action', 422);
        }
        $hash = Policy::fingerprint([$id, $body, $action, $version, $staff]);
        return $this->context->run(
            $credentials,
            'support',
            $staff ? 'support.agent' : 'support.reply',
            true,
            function (int $actor) use ($id, $body, $action, $version, $key, $hash, $staff) {
                $r = $this->context->repository;
                $ticket = $this->authorizeTicket($id, $actor);
                $owner = (int) $ticket['owner_user_id'] === $actor;
                if ((!$staff && !$owner) || ($staff && $owner)) {
                    throw new ExperienceException('not_found', 404);
                }
                $prior = $r->messageRequest($actor, $key);
                if ($prior !== null) {
                    Policy::replay($prior, $hash);
                    return ['id' => $prior['public_id'], 'ticket_version' => (int) $prior['resulting_version']];
                }
                Policy::version($ticket, $version);
                if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
                    throw new ExperienceException('ticket_closed');
                }
                $status = !$staff ? 'waiting_support' : match ($action) {
                    'reply' => 'waiting_customer', 'resolve' => 'resolved', 'note' => $ticket['status'],
                };
                $message = $r->message(
                    $ticket,
                    $actor,
                    $body,
                    $action === 'note' ? 'internal' : 'customer',
                    $action,
                    $key,
                    $hash,
                    $version + 1
                );
                $r->updateTicket((int) $ticket['id'], $status);
                $this->context->write($actor, 'support.' . $action, 'support_ticket', $id, $key);
                if ($staff && $action !== 'note') {
                    $this->context->notify(
                        (int) $ticket['owner_user_id'],
                        $actor,
                        'support:message:' . $message['id'],
                        'support.reply',
                        ['ticket_id' => $id, 'message_id' => $message['public_id']]
                    );
                }
                return ['id' => $message['public_id'], 'ticket_version' => $version + 1];
            }
        );
    }

    public function assign(SessionCredentials $credentials, string $id, int $agent, int $version): array
    {
        return $this->context->run(
            $credentials,
            'support',
            'support.manage',
            true,
            function (int $actor) use ($id, $agent, $version) {
                $ticket = $this->authorizeTicket($id, $actor, true);
                Policy::version($ticket, $version);
                if (
                    $agent < 1 || $agent === (int) $ticket['owner_user_id']
                    || !$this->context->access->permitted($agent, 'support.agent')
                    || ($ticket['category'] === 'privacy_security'
                        && !$this->context->access->permitted($agent, 'support.restricted'))
                ) {
                    throw new ExperienceException('invalid_support_agent', 422);
                }
                // The identity eligibility read is non-locking to preserve actor -> ticket lock order.
                if (!$this->context->repository->activeAgent($agent)) {
                    throw new ExperienceException('invalid_support_agent', 422);
                }
                $this->context->repository->assignTicket((int) $ticket['id'], $agent);
                $this->context->write(
                    $actor,
                    'support.assigned',
                    'support_ticket',
                    $id . ':' . $agent,
                    'assignment:' . $version
                );
                return ['id' => $id, 'version' => $version + 1];
            }
        );
    }

    public function articles(SessionCredentials $credentials, int $before, int $limit): array
    {
        return $this->context->run(
            $credentials,
            'support',
            'support.read',
            false,
            function (int $actor) use ($before, $limit) {
                $rows = $this->context->repository->articles($before, $limit);
                return ['items' => array_map(fn ($r) => $this->articleDto($r), $rows),
                'next_before_id' => $rows ? (string) end($rows)['id'] : null];
            }
        );
    }

    public function draftArticle(
        SessionCredentials $credentials,
        string $slug,
        int $version,
        string $title,
        string $body,
        string $key
    ): array {
        Policy::key($key);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug) !== 1 || $version !== 1) {
            throw new ExperienceException('invalid_article_version', 422);
        }
        $title = Policy::text($title, 3, 160, true);
        $body = Policy::text($body, 10, 5000, true);
        $hash = Policy::fingerprint([$slug, $version, $title, $body]);
        return $this->context->run(
            $credentials,
            'support',
            'support.articles.write',
            true,
            function (int $actor) use ($slug, $version, $title, $body, $key, $hash) {
                $r = $this->context->repository;
                $prior = $r->articleRequest($actor, $key);
                if ($prior !== null) {
                    Policy::replay($prior, $hash);
                } else {
                    if ($r->article($slug, $version) !== null) {
                        throw new ExperienceException('article_version_exists');
                    }
                    $r->draftArticle(
                        $actor,
                        $slug,
                        $version,
                        $title,
                        $body,
                        $this->context->policy->reference(),
                        $key,
                        $hash
                    );
                    $this->context->write($actor, 'support.article.drafted', 'faq_article', $slug . ':'
                        . $version, $key);
                }
                return ['slug' => $slug, 'version' => $version, 'accepted' => true];
            }
        );
    }

    public function publishArticle(SessionCredentials $credentials, string $slug, int $version, bool $withdraw): array
    {
        return $this->context->run(
            $credentials,
            'support',
            'support.articles.publish',
            true,
            function (int $actor) use ($slug, $version, $withdraw) {
                $r = $this->context->repository;
                $article = $r->article($slug, $version);
                if ($article === null) {
                    throw new ExperienceException('not_found', 404);
                }
                if (!$withdraw && (int) $article['author_user_id'] === $actor) {
                    throw new ExperienceException('independent_publisher_required', 403);
                }
                if ($article['status'] === ($withdraw ? 'withdrawn' : 'published')) {
                    return $this->articleDto($article);
                }
                if ($article['status'] !== ($withdraw ? 'published' : 'draft')) {
                    throw new ExperienceException('invalid_transition');
                }
                $row = $r->publishArticle($slug, $version, $actor, $withdraw);
                $this->context->write(
                    $actor,
                    $withdraw ? 'support.article.withdrawn' : 'support.article.published',
                    'faq_article',
                    $slug . ':' . $version,
                    'publish:' . $version
                );
                return $this->articleDto($row);
            }
        );
    }

    private function ticketDto(array $row): array
    {
        return ['id' => $row['public_id'], 'subject' => $row['subject'], 'category' => $row['category'],
            'status' => $row['status'], 'version' => (int) $row['version'], 'priority' => 'normal',
            'created_at' => Policy::utc($row['created_at'])];
    }

    private function articleDto(array $row): array
    {
        return ['slug' => $row['slug'], 'version' => (int) $row['version'], 'title' => $row['title'],
            'body' => $row['body'],
            'published_at' => $row['published_at'] === null ? null : Policy::utc($row['published_at'])];
    }
}
