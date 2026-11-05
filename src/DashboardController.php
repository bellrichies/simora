<?php

declare(strict_types=1);

namespace App;

final class DashboardController
{
    public function __construct(
        private \App\Services\PlatformDetailsService $detailsService,
        private \App\Services\DashboardDataService $dashboardService,
        private array $settings,
        private \App\Services\PredictionsHubService $predictionsHubService
    ) {
    }

    public function show(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $sections = [
            'overview' => 'Overview', 'platforms' => 'Platforms', 'predictions' => 'Predictions', 'plans' => 'Plans & billing', 'wallet' => 'Wallet',
            'referrals' => 'Referrals', 'reviews' => 'Reviews', 'support' => 'Support',
            'settings' => 'Settings', 'notifications' => 'Notifications', 'results' => 'Results',
        ];
        $key = trim($path, '/');
        $key = $key === '' || $key === 'dashboard' ? 'overview' : $key;
        if (in_array($key, ['platforms', 'predictions', 'plans', 'referrals', 'wallet', 'reviews', 'support', 'settings'], true)
            && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            return;
        }
        $settings = $this->settings;
        $dashboard = $this->dashboardService->getData($key === 'overview', in_array($key, ['platforms', 'predictions'], true), $key === 'overview' ? $_GET : []);
        $platforms = $dashboard['platforms'];
        if (in_array($key, ['platforms', 'predictions'], true)) {
            $predictionsHub = $this->predictionsHubService->getPage($_GET);
        }
        foreach ($platforms as $item) {
            $sections[$item['slug']] = $item['name'];
        }
        if (!isset($sections[$key])) {
            http_response_code(404);
            $title = 'Page not found';
        } else {
            $title = $sections[$key];
        }
        $platform = null;
        foreach ($platforms as $item) {
            if ($item['slug'] === $key) {
                $platform = $item;
                break;
            }
        }
        if ($platform !== null) {
            $query = $_GET;
            // Old page bookmarks now expand the table instead of hiding earlier rows.
            if (!isset($query['show']) && is_string($query['page'] ?? null)
                && preg_match('/^[1-9]\d{0,5}$/D', $query['page'])) {
                $query['show'] = (string) min(999999, (int) $query['page'] * $settings['history_page_size']);
            }
            try {
                $details = $this->detailsService->getDetails($key, $query);
            } catch (\InvalidArgumentException $exception) {
                http_response_code(404);
                $title = 'Page not found';
                $platform = null;
                $key = '__not_found__';
            }
            if ($platform !== null && !empty($details['league']['name'])) {
                $title = $platform['name'] . ' - ' . $details['league']['name'];
            } elseif ($platform !== null && !empty($details['week']['league_name'])) {
                $title = $platform['name'] . ' - ' . $details['week']['league_name'];
            }
        }
        if ($key === 'wallet') {
            $wallet = (new \App\Services\WalletPreviewService(require dirname(__DIR__) . '/database/fixtures/wallet.php'))->getPage($_GET);
        }
        if ($key === 'referrals') {
            $referrals = (new \App\Services\ReferralsPreviewService(require dirname(__DIR__) . '/database/fixtures/referrals.php'))->getPage($_GET);
        }
        if ($key === 'reviews') {
            $reviews = (new \App\Services\ReviewsPreviewService(require dirname(__DIR__) . '/database/fixtures/reviews.php'))->getPage($_GET);
        }
        if ($key === 'support') {
            $support = (new \App\Services\SupportPreviewService(require dirname(__DIR__) . '/database/fixtures/support.php'))->getPage($_GET);
        }
        if ($key === 'settings') {
            $preferences = (new \App\Services\SettingsPreviewService(
                require dirname(__DIR__) . '/database/fixtures/settings.php', $settings, $platforms
            ))->getPage($_GET, $dashboard['account']);
        }
        if ($key === 'plans') {
            $billingConfig = require dirname(__DIR__) . '/config/billing.php';
            $billing = null;
            $billingError = null;
            try {
                if ($billingConfig['provider'] !== 'mock') {
                    throw new \RuntimeException('Live billing is not connected.');
                }
                $billingService = new \App\Services\BillingService(
                    new \App\Repositories\DemoBillingRepository(dirname(__DIR__) . '/database/fixtures/billing.php')
                );
                $billing = $billingService->getPage($_GET);
            } catch (\RuntimeException $exception) {
                $billingError = 'Billing data is unavailable. Please try again later.';
            }
        }
        if ($key === 'predictions' && !empty($predictionsHub['preview'])) {
            $title = $predictionsHub['preview']['name'] . ' - ' . $predictionsHub['preview']['league'] . ' - Predictions';
        }
        require dirname(__DIR__) . '/views/layout.php';
    }
}



