<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Domain\Experience\ExperienceException;
use App\Services\ChatService;
use App\Services\ReferralService;
use App\Services\ReviewService;
use App\Services\SupportService;
use JsonException;
use PDOException;

final class ExperienceController
{
    private const ROUTES = [
        ['GET', '#^/api/v1/referrals$#D', 'referrals'],
        ['POST', '#^/api/v1/referrals/code$#D', 'referralCode'],
        ['GET', '#^/api/v1/reviews$#D', 'reviews'],
        ['POST', '#^/api/v1/reviews$#D', 'submitReview'],
        ['GET', '#^/api/v1/me/review$#D', 'ownReview'],
        ['POST', '#^/api/v1/me/review/(rev_[a-f0-9]{32})/withdraw$#D', 'withdrawReview'],
        ['GET', '#^/api/v1/admin/reviews$#D', 'reviewQueue'],
        ['POST', '#^/api/v1/admin/reviews/(rev_[a-f0-9]{32})/decisions$#D', 'reviewDecision'],
        ['GET', '#^/api/v1/support/articles$#D', 'articles'],
        ['POST', '#^/api/v1/admin/support/articles$#D', 'draftArticle'],
        ['POST', '#^/api/v1/admin/support/articles/([a-z0-9][a-z0-9-]{0,99})/([1-9][0-9]{0,5})/publication$#D',
            'publishArticle'],
        ['GET', '#^/api/v1/support/tickets$#D', 'tickets'],
        ['POST', '#^/api/v1/support/tickets$#D', 'createTicket'],
        ['GET', '#^/api/v1/support/tickets/(tic_[a-f0-9]{32})$#D', 'ticket'],
        ['POST', '#^/api/v1/support/tickets/(tic_[a-f0-9]{32})/messages$#D', 'reply'],
        ['GET', '#^/api/v1/admin/support/tickets$#D', 'triage'],
        ['GET', '#^/api/v1/admin/support/tickets/(tic_[a-f0-9]{32})$#D', 'agentTicket'],
        ['POST', '#^/api/v1/admin/support/tickets/(tic_[a-f0-9]{32})/messages$#D', 'agentReply'],
        ['POST', '#^/api/v1/admin/support/tickets/(tic_[a-f0-9]{32})/assignment$#D', 'assignTicket'],
        ['POST', '#^/api/v1/admin/chat/room$#D', 'configureChat'],
        ['POST', '#^/api/v1/chat/membership$#D', 'joinChat'],
        ['GET', '#^/api/v1/chat/messages$#D', 'chat'],
        ['POST', '#^/api/v1/chat/messages$#D', 'sendChat'],
        ['GET', '#^/api/v1/admin/chat/messages$#D', 'chatQueue'],
        ['GET', '#^/api/v1/admin/chat/reports$#D', 'chatReports'],
        ['POST', '#^/api/v1/admin/chat/messages/(cht_[a-f0-9]{32})/decisions$#D', 'chatDecision'],
        ['POST', '#^/api/v1/chat/messages/(cht_[a-f0-9]{32})/reports$#D', 'reportChat'],
        ['POST', '#^/api/v1/admin/chat/members/([1-9][0-9]{0,17})/restriction$#D', 'restrictChat'],
    ];

    public function __construct(
        private readonly ReferralService $referrals,
        private readonly ReviewService $reviews,
        private readonly SupportService $support,
        private readonly ChatService $chat
    ) {
    }

    public static function matches(string $path): bool
    {
        foreach (self::ROUTES as [, $pattern]) {
            if (preg_match($pattern, rtrim($path, '/'))) {
                return true;
            }
        }
        return false;
    }

    public function handle(
        string $method,
        string $path,
        array $query,
        string $raw,
        SessionCredentials $credentials
    ): ApiResponse {
        $meta = ['request_id' => bin2hex(random_bytes(16)), 'as_of' => gmdate('Y-m-d\TH:i:s\Z'),
            'data_mode' => 'server_verified'];
        $respond = static fn (int $status, mixed $data, ?array $error = null, array $headers = []): ApiResponse =>
            new ApiResponse($status, ['data' => $data, 'error' => $error, 'meta' => $meta], $headers);
        $allowed = [];
        $action = null;
        $args = [];
        foreach (self::ROUTES as [$verb, $pattern, $candidate]) {
            if (preg_match($pattern, rtrim($path, '/'), $matches)) {
                $allowed[] = $verb;
                if ($verb === $method) {
                    $action = $candidate;
                    $args = array_slice($matches, 1);
                }
            }
        }
        if ($action === null) {
            return $respond($allowed ? 405 : 404, null, ['code' => $allowed ? 'method_not_allowed' : 'not_found',
                'message' => 'Request unavailable.'], $allowed ? ['Allow' => implode(', ', $allowed)] : []);
        }
        try {
            if (strlen($raw) > 65536) {
                throw new ExperienceException('request_too_large', 413);
            }
            if (array_diff(array_keys($query), ['before_id', 'limit'])) {
                throw new ExperienceException('unknown_query_parameter', 422);
            }
            foreach ($query as $value) {
                if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1) {
                    throw new ExperienceException('invalid_pagination', 422);
                }
            }
            $before = (int) ($query['before_id'] ?? PHP_INT_MAX);
            $limit = (int) ($query['limit'] ?? 20);
            if ($limit < 1 || $limit > 50) {
                throw new ExperienceException('invalid_pagination', 422);
            }
            $input = $method === 'POST' ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : [];
            if (!is_array($input) || ($method === 'POST' && !str_starts_with(ltrim($raw), '{'))) {
                throw new ExperienceException('invalid_json_object', 422);
            }
            $schema = match ($action) {
                'submitReview' => ['rating' => 'integer', 'title' => 'string', 'body' => 'string',
                    'request_key' => 'string'],
                'withdrawReview' => ['version' => 'integer', 'request_key' => 'string'],
                'reviewDecision' => ['action' => 'string', 'reason' => 'string', 'version' => 'integer',
                    'request_key' => 'string'],
                'createTicket' => ['subject' => 'string', 'category' => 'string', 'body' => 'string',
                    'request_key' => 'string'],
                'reply', 'agentReply' => ['body' => 'string', 'action' => 'string', 'version' => 'integer',
                    'request_key' => 'string'],
                'assignTicket' => ['agent_id' => 'integer', 'version' => 'integer'],
                'draftArticle' => ['slug' => 'string', 'version' => 'integer', 'title' => 'string',
                    'body' => 'string', 'request_key' => 'string'],
                'publishArticle' => ['withdraw' => 'boolean'],
                'configureChat' => ['open' => 'boolean'],
                'joinChat' => ['consent_reference' => 'string'],
                'sendChat' => ['body' => 'string', 'request_key' => 'string'],
                'chatDecision' => ['publish' => 'boolean', 'reason' => 'string', 'version' => 'integer'],
                'reportChat' => ['reason' => 'string'],
                'restrictChat' => ['ban' => 'boolean', 'mute_minutes' => 'integer'],
                default => [],
            };
            if (array_diff(array_keys($input), array_keys($schema))) {
                throw new ExperienceException('unknown_input_field', 422);
            }
            foreach ($schema as $key => $type) {
                if (!array_key_exists($key, $input) || gettype($input[$key]) !== $type) {
                    throw new ExperienceException('invalid_input_field', 422);
                }
            }
            $c = $credentials;
            $i = $input;
            $data = match ($action) {
                'referrals' => $this->referrals->read($c),
                'referralCode' => $this->referrals->createCode($c),
                'reviews', 'reviewQueue' => $this->reviews->listing($c, $before, $limit, $action === 'reviewQueue'),
                'ownReview' => $this->reviews->own($c),
                'submitReview' => $this->reviews->submit($c, $i['rating'], $i['title'], $i['body'], $i['request_key']),
                'withdrawReview' => $this->reviews->decide($c, $args[0], 'withdraw', 'owner_withdrawal',
                    $i['version'], $i['request_key']),
                'reviewDecision' => $this->reviews->decide($c, $args[0], $i['action'], $i['reason'],
                    $i['version'], $i['request_key']),
                'articles' => $this->support->articles($c, $before, $limit),
                'draftArticle' => $this->support->draftArticle($c, $i['slug'], $i['version'], $i['title'],
                    $i['body'], $i['request_key']),
                'publishArticle' => $this->support->publishArticle($c, $args[0], (int) $args[1], $i['withdraw']),
                'tickets', 'triage' => $this->support->listing($c, $before, $limit, $action === 'triage'),
                'createTicket' => $this->support->create($c, $i['subject'], $i['category'], $i['body'],
                    $i['request_key']),
                'ticket', 'agentTicket' => $this->support->detail($c, $args[0], $before, $limit,
                    $action === 'agentTicket'),
                'reply', 'agentReply' => $this->support->reply($c, $args[0], $i['body'], $i['action'],
                    $i['version'], $i['request_key'], $action === 'agentReply'),
                'assignTicket' => $this->support->assign($c, $args[0], $i['agent_id'], $i['version']),
                'configureChat' => $this->chat->configure($c, $i['open']),
                'joinChat' => $this->chat->join($c, $i['consent_reference']),
                'chat', 'chatQueue' => $this->chat->listing($c, $before, $limit, $action === 'chatQueue'),
                'chatReports' => $this->chat->reports($c, $before, $limit),
                'sendChat' => $this->chat->send($c, $i['body'], $i['request_key']),
                'chatDecision' => $this->chat->moderate($c, $args[0], $i['publish'], $i['reason'], $i['version']),
                'reportChat' => $this->chat->report($c, $args[0], $i['reason']),
                'restrictChat' => $this->chat->restrict($c, (int) $args[0], $i['ban'], $i['mute_minutes']),
            };
            return $respond(200, $data);
        } catch (ExperienceException | AccessException $error) {
            return $respond(
                $error->status,
                null,
                ['code' => $error->errorCode, 'message' => 'Experience request denied.'],
                $error->status === 429 ? ['Retry-After' => (string) $error->retryAfter] : []
            );
        } catch (JsonException) {
            return $respond(422, null, ['code' => 'invalid_json', 'message' => 'Invalid JSON request.']);
        } catch (PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                return $respond(409, null, ['code' => 'state_conflict',
                    'message' => 'State conflict; refresh and retry.']);
            }
            throw $error;
        }
    }
}
