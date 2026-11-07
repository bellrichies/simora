<?php

declare(strict_types=1);

// Let the PHP development server serve existing public assets directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = $path !== null ? realpath(__DIR__ . $path) : false;
    if ($file !== false && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$settings = require dirname(__DIR__) . '/config/app.php';
$platforms = require dirname(__DIR__) . '/config/platforms.php';
if ($requestPath === '/api/v1' || str_starts_with($requestPath, '/api/v1/')) {
    $credentials = new App\Domain\Access\SessionCredentials(
        ($_SERVER['HTTPS'] ?? '') === 'on',
        is_string($_COOKIE['__Host-simora_session'] ?? null) ? $_COOKIE['__Host-simora_session'] : null,
        is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null
    );
    if (App\Http\ExperienceController::matches($requestPath)) {
        try {
            $experience = App\Infrastructure\ExperienceServiceFactory::create(
                App\Infrastructure\DatabaseConnection::connect(), require dirname(__DIR__) . '/config/experience.php'
            );
            $raw = file_get_contents('php://input', false, null, 0, 65537);
            $experience->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath, $_GET,
                $raw === false ? '' : $raw, $credentials)->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        } catch (\Throwable $error) {
            (new App\Http\ApiResponse(503, ['data' => null,
                'error' => ['code' => 'experience_unavailable', 'message' => 'Experience service is unavailable.'],
                'meta' => ['request_id' => bin2hex(random_bytes(16)), 'as_of' => gmdate('Y-m-d\TH:i:s\Z'),
                    'data_mode' => 'unavailable']]))->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        }
        return;
    }
    if (App\Http\PaymentController::matches($requestPath)) {
        try {
            $payments = new App\Http\PaymentController(App\Infrastructure\PaymentServiceFactory::create(
                App\Infrastructure\DatabaseConnection::connect(), require dirname(__DIR__) . '/config/payments.php'
            ));
            $rawBody = file_get_contents('php://input', false, null, 0, 262145);
            $payments->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath, $rawBody === false ? '' : $rawBody,
                ['Paystack-Signature' => $_SERVER['HTTP_PAYSTACK_SIGNATURE'] ?? null,
                    'X-Paystack-Signature' => $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null], $credentials)
                ->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        } catch (\Throwable $error) {
            (new App\Http\ApiResponse(503, ['data' => null,
                'error' => ['code' => 'payments_unavailable', 'message' => 'Payment service is unavailable.'],
                'meta' => ['request_id' => bin2hex(random_bytes(16)), 'data_mode' => 'unavailable']]))
                ->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        }
        return;
    }
    if (App\Http\AccessController::matches($requestPath)) {
        try {
            $access = new App\Http\AccessController(new App\Services\AccessService(
                new App\Repositories\AccessRepository(App\Infrastructure\DatabaseConnection::connect())
            ));
            $access->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath, $_GET, $credentials)
                ->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        } catch (\Throwable $error) {
            (new App\Http\ApiResponse(503, ['data' => null,
                'error' => ['code' => 'access_unavailable', 'message' => 'Access storage is unavailable.'],
                'meta' => ['request_id' => bin2hex(random_bytes(16)), 'as_of' => gmdate('Y-m-d\TH:i:s\Z'),
                    'data_mode' => 'unavailable']]))->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
        }
        return;
    }
    $api = new App\Http\AnalyticsApiController(
        new App\Services\AnalyticsApiService($platforms),
        new App\Infrastructure\SystemClock(),
        static fn () => new App\Services\AccessService(
            new App\Repositories\AccessRepository(App\Infrastructure\DatabaseConnection::connect())
        )
    );
    $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath, $_GET, $credentials)
        ->send(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD');
    return;
}
$store = new App\Infrastructure\JsonFixtureStore(dirname(__DIR__) . '/database/fixtures');
$repository = new App\Repositories\DemoPlatformDataRepository($store, new App\Infrastructure\SystemClock(), $settings, $platforms);
$service = new App\Services\PlatformDetailsService($repository, $settings);
try {
    if (preg_match('#^/api/platforms/([a-z0-9-]+)/predictions/?$#D', $requestPath, $match)) {
        (new App\PredictionsController($service, $platforms))->index($match[1]);
    } else {
        $dashboardService = new App\Services\DashboardDataService($repository, $store, $platforms, $settings);
        $predictionsHubService = new App\Services\PredictionsHubService($repository, new App\Infrastructure\SystemClock(), $platforms, $settings);
        (new App\DashboardController($service, $dashboardService, $settings, $predictionsHubService))->show();
    }
} catch (\RuntimeException | \JsonException $exception) {
    error_log('Simora data error: ' . $exception->getMessage());
    http_response_code(503);
    header('Cache-Control: no-store');
    if (str_starts_with($requestPath, '/api/')) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['data' => null, 'error' => 'Demo data is temporarily unavailable.', 'meta' => null]);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Simora - Unavailable</title><h1>Demo data unavailable</h1><p>Please try again shortly.</p></html>';
    }
}
