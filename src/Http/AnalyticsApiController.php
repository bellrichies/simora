<?php

declare(strict_types=1);

namespace App\Http;

use App\Contracts\Clock;
use App\Services\AnalyticsApiService;
use InvalidArgumentException;

final class AnalyticsApiController
{
    public function __construct(
        private AnalyticsApiService $service,
        private Clock $clock,
        private readonly ?\Closure $accessFactory = null
    ) {
    }

    public function handle(
        string $method,
        string $path,
        array $query,
        ?\App\Domain\Access\SessionCredentials $credentials = null
    ): ApiResponse {
        $meta = ['request_id' => bin2hex(random_bytes(16)),
            'as_of' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'data_mode' => 'unavailable'];
        $response = static fn (int $status, mixed $data, ?array $error = null, array $headers = []): ApiResponse =>
            new ApiResponse($status, ['data' => $data, 'error' => $error, 'meta' => $meta], $headers);
        $error = static fn (int $status, string $code, string $message, array $headers = []): ApiResponse =>
            $response($status, null, ['code' => $code, 'message' => $message], $headers);
        // Exact static routes are resolved before dynamic identifiers.
        $routes = [
            '#^/api/v1/platforms$#D' => 'platforms',
            '#^/api/v1/prediction-markets$#D' => 'markets',
            '#^/api/v1/platforms/([a-z0-9-]+)/leagues$#D' => 'leagues',
            '#^/api/v1/models/compare$#D' => 'compare',
            '#^/api/v1/predictions/best$#D' => 'best',
            '#^/api/v1/predictions/history$#D' => 'history',
            '#^/api/v1/me/model-preferences$#D' => 'preferences',
            '#^/api/v1/backtests$#D' => 'backtests',
            '#^/api/v1/backtests/[a-zA-Z0-9_.:-]{1,128}$#D' => 'backtest',
            '#^/api/v1/simulations/strategy$#D' => 'strategy',
            '#^/api/v1/simulations/[a-zA-Z0-9_.:-]{1,128}$#D' => 'simulation',
            '#^/api/v1/admin/models/health$#D' => 'health',
            '#^/api/v1/admin/models/[a-zA-Z0-9_.:-]{1,128}/training-runs$#D' => 'training',
            '#^/api/v1/admin/models/[a-zA-Z0-9_.:-]{1,128}/activate-version$#D' => 'activate',
            '#^/api/v1/admin/models/[a-zA-Z0-9_.:-]{1,128}/suspend$#D' => 'suspend',
            '#^/api/v1/models(?:/[a-zA-Z0-9_.:-]{1,128}(?:/performance)?)?$#D' => 'models',
            '#^/api/v1/predictions/[a-zA-Z0-9_.:-]{1,128}$#D' => 'prediction',
            '#^/api/v1/leagues/[a-zA-Z0-9_.:-]{1,128}/rounds$#D' => 'rounds',
            '#^/api/v1/rounds/[a-zA-Z0-9_.:-]{1,128}/matches$#D' => 'matches',
            '#^/api/v1/matches/[a-zA-Z0-9_.:-]{1,128}(?:/odds)?$#D' => 'match',
            '#^/api/v1/analytics/platforms/([a-z0-9-]+)/performance$#D' => 'performance',
        ];
        $route = null;
        $arguments = [];
        foreach ($routes as $pattern => $name) {
            if (preg_match($pattern, rtrim($path, '/'), $arguments)) {
                $route = $name;
                break;
            }
        }
        if ($route === null) {
            return $error(404, 'not_found', 'API route not found.');
        }
        $methods = match ($route) {
            'preferences' => ['GET', 'PUT'],
            'backtests', 'strategy', 'training', 'activate', 'suspend' => ['POST'],
            default => ['GET'],
        };
        if (!in_array($method, $methods, true)) {
            return $error(
                405,
                'method_not_allowed',
                'Method not allowed.',
                ['Allow' => implode(', ', $methods)]
            );
        }
        if ($route === 'platforms') {
            return $response(200, $this->service->platforms());
        }
        if ($route === 'markets') {
            return $response(200, $this->service->markets());
        }
        if (in_array($route, ['leagues', 'performance'], true) && !$this->service->hasPlatform($arguments[1])) {
            return $error(404, 'not_found', 'Platform not found.');
        }
        if (!in_array($route, ['leagues', 'rounds', 'matches', 'match'], true)) {
            // No trusted web-session/entitlement adapter exists yet. Never use preview access or request identity.
            if ($route === 'best') {
                try {
                    $this->service->validateBest($query);
                } catch (InvalidArgumentException $exception) {
                    return $error(422, 'validation_failed', $exception->getMessage());
                }
            }
            if ($this->accessFactory !== null) {
                try {
                    $this->checkPlan($route, $path, $query, $credentials);
                } catch (\App\Domain\Access\AccessException $exception) {
                    return $error($exception->status, $exception->errorCode, 'Plan access request denied.');
                } catch (InvalidArgumentException $exception) {
                    return $error(422, 'validation_failed', $exception->getMessage());
                } catch (\Throwable) {
                    return $error(503, 'access_unavailable', 'Verified plan access is unavailable.');
                }
            }
            return $error(503, 'access_unavailable', 'Canonical analytics persistence is not connected.');
        }
        return $error(503, 'source_unavailable', 'Authorized point-in-time source reads are not connected.');
    }

    private function checkPlan(
        string $route,
        string $path,
        array $query,
        ?\App\Domain\Access\SessionCredentials $credentials
    ): void {
        $feature = match ($route) {
            'models' => 'model.catalog',
            'best', 'prediction' => 'prediction.analyse',
            'history', 'performance' => 'prediction.history',
            'compare' => isset($query['export']) ? 'comparison.export' : 'comparison.read',
            'preferences' => 'model.preferences',
            'strategy', 'simulation' => 'simulation.run',
            default => null, // Existing administrative/research gates remain independently disabled.
        };
        if ($feature === null) {
            return;
        }
        if (isset($query['export']) && $query['export'] !== 'csv') {
            throw new InvalidArgumentException('Only CSV report export is specified.');
        }
        $list = static function (mixed $value): array {
            if ($value === null) {
                return [];
            }
            if (!is_string($value) || strlen($value) > 2048 || $value === '') {
                throw new InvalidArgumentException('Scope must be a bounded comma-separated list.');
            }
            return explode(',', $value);
        };
        $models = $list($query['model_code'] ?? $query['models'] ?? null);
        if ($route === 'models') {
            $segments = explode('/', trim($path, '/'));
            if (in_array($segments[3] ?? null, ['spe', 'mlpe', 'beve'], true)) {
                $models = [$segments[3]];
            }
        }
        $markets = $list($query['markets'] ?? null);
        $options = array_intersect_key($query, array_flip(['from', 'to']));
        if (isset($query['metrics'])) {
            $options['metrics'] = $list($query['metrics']);
        }
        $service = ($this->accessFactory)();
        $credentials ??= new \App\Domain\Access\SessionCredentials(false, null);
        $service->requirePlanRequest($credentials, $feature, $models, $markets, $options);
        if ($route === 'best' && ($query['model_mode'] ?? null) === 'compare') {
            $service->requirePlanRequest($credentials, 'comparison.read', $models, $markets);
        }
    }
}
