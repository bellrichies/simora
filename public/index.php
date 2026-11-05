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

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$settings = require dirname(__DIR__) . '/config/app.php';
$platforms = require dirname(__DIR__) . '/config/platforms.php';
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
