<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Services\AccessService;
use InvalidArgumentException;

final class AccessController
{
    public function __construct(private readonly AccessService $service)
    {
    }

    public static function matches(string $path): bool
    {
        $pattern = '#^/api/v1/(plans|subscription|me/notifications(?:/[1-9][0-9]{0,17}/read)?)/?$#D';
        return preg_match($pattern, $path) === 1;
    }

    public function handle(string $method, string $path, array $query, SessionCredentials $credentials): ApiResponse
    {
        $meta = ['request_id' => bin2hex(random_bytes(16)), 'as_of' => gmdate('Y-m-d\TH:i:s\Z'),
            'data_mode' => 'server_verified'];
        $response = static fn (int $status, mixed $data, ?array $error = null, array $headers = []): ApiResponse =>
            new ApiResponse($status, ['data' => $data, 'error' => $error, 'meta' => $meta], $headers);
        $path = rtrim($path, '/');
        $write = str_ends_with($path, '/read');
        $allowed = $write ? 'PATCH' : 'GET';
        if ($method !== $allowed) {
            return $response(
                405,
                null,
                ['code' => 'method_not_allowed', 'message' => 'Method not allowed.'],
                ['Allow' => $allowed]
            );
        }
        try {
            if ($path === '/api/v1/plans') {
                return $response(200, $this->service->plans());
            }
            if ($path === '/api/v1/subscription') {
                return $response(200, $this->service->subscriptions($credentials));
            }
            if ($write) {
                $id = (int) basename(dirname($path));
                return $response(200, $this->service->markRead($credentials, $id, $meta['request_id']));
            }
            if (array_diff(array_keys($query), ['before_id', 'limit'])) {
                throw new InvalidArgumentException('Unknown notification query parameter.');
            }
            foreach ($query as $value) {
                if (!is_string($value) || preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1) {
                    throw new InvalidArgumentException('Invalid notification cursor or limit.');
                }
            }
            $rows = $this->service->notifications(
                $credentials,
                (int) ($query['before_id'] ?? PHP_INT_MAX),
                (int) ($query['limit'] ?? 20)
            );
            $result = $response(200, $rows);
            $result->body['meta']['next_before_id'] = $rows ? (string) end($rows)['id'] : null;
            return $result;
        } catch (AccessException $error) {
            $details = ['code' => $error->errorCode, 'message' => 'Access request denied.'];
            return $response($error->status, null, $details);
        } catch (InvalidArgumentException $error) {
            return $response(422, null, ['code' => 'validation_failed', 'message' => $error->getMessage()]);
        }
    }
}
