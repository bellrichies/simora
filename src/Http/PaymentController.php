<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Access\AccessException;
use App\Domain\Access\SessionCredentials;
use App\Domain\Payments\PaymentException;
use App\Services\PaymentLifecycleService;
use JsonException;

final class PaymentController
{
    public function __construct(private readonly PaymentLifecycleService $service)
    {
    }

    public static function matches(string $path): bool
    {
        $pattern = '#^/api/v1/(billing/(?:checkouts|webhooks/paystack|invoices)|wallet(?:/transactions)?)/?$#D';
        return preg_match($pattern, $path) === 1;
    }

    public function handle(
        string $method,
        string $path,
        string $body,
        array $headers,
        SessionCredentials $credentials
    ): ApiResponse {
        $path = rtrim($path, '/');
        $allowed = in_array($path, ['/api/v1/billing/checkouts', '/api/v1/billing/webhooks/paystack'], true)
            ? 'POST' : 'GET';
        $meta = ['request_id' => bin2hex(random_bytes(16)), 'as_of' => gmdate('Y-m-d\TH:i:s\Z'),
            'data_mode' => 'server_verified'];
        $reply = static fn (int $status, mixed $data, ?array $error = null, array $extra = []): ApiResponse =>
            new ApiResponse($status, ['data' => $data, 'error' => $error, 'meta' => $meta], $extra);
        if ($method !== $allowed) {
            return $reply(
                405,
                null,
                ['code' => 'method_not_allowed', 'message' => 'Method not allowed.'],
                ['Allow' => $allowed]
            );
        }
        try {
            if (strlen($body) > 262144) {
                throw new PaymentException('request_too_large', 413);
            }
            if ($path === '/api/v1/billing/webhooks/paystack') {
                return $reply(200, $this->service->receive($body, $headers));
            }
            if ($path === '/api/v1/billing/checkouts') {
                $input = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                if (
                    !is_array($input) || array_diff(array_keys($input), ['plan', 'operation', 'request_key'])
                    || !is_string($input['plan'] ?? null) || !is_string($input['operation'] ?? null)
                    || !is_string($input['request_key'] ?? null)
                ) {
                    throw new PaymentException('invalid_checkout_request', 422);
                }
                return $reply(202, $this->service->prepare(
                    $credentials,
                    $input['request_key'],
                    $input['plan'],
                    $input['operation']
                ));
            }
            if ($path === '/api/v1/billing/invoices') {
                return $reply(200, $this->service->invoices($credentials));
            }
            return $reply(200, $this->service->wallet($credentials, str_ends_with($path, '/transactions')));
        } catch (PaymentException | AccessException $error) {
            return $reply($error->status, null, ['code' => $error->errorCode, 'message' => 'Payment request denied.']);
        } catch (JsonException) {
            return $reply(422, null, ['code' => 'invalid_json', 'message' => 'Invalid JSON request.']);
        }
    }
}
