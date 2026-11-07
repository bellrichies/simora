<?php

declare(strict_types=1);

namespace SimoraTests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real front-controller HTTP regressions; no migrations, accounts or source records are written. */
final class LegacyRoutesTest extends TestCase
{
    private static mixed $server = null;
    private static string $base;
    private static string $log;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate HTTP test port: ' . $message);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base = 'http://' . $address;
        self::$log = tempnam(sys_get_temp_dir(), 'simora-http-');
        $pipes = [];
        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['file', self::$log, 'a']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource(self::$server)) {
            throw new RuntimeException('Cannot start PHP HTTP test server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $message, 0.1);
            if ($ready !== false) {
                fclose($ready);
                return;
            }
            usleep(100000);
        }
        self::tearDownAfterClass();
        throw new RuntimeException('PHP HTTP test server did not become ready.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (isset(self::$log) && is_file(self::$log)) {
            unlink(self::$log);
        }
    }

    private function request(string $path, string $method = 'GET', array $headers = []): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true,
            'timeout' => 15, 'header' => implode("\r\n", $headers)]]);
        $body = file_get_contents(self::$base . $path, false, $context);
        self::assertNotFalse($body, $path);
        preg_match('/ (\d{3}) /', $http_response_header[0], $status);
        return ['status' => (int) $status[1], 'headers' => implode("\n", $http_response_header), 'body' => $body];
    }

    public static function oldPages(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/', '/dashboard', '/platforms', '/predictions', '/sportybet', '/betpawa', '/betking',
            '/plans', '/wallet', '/referrals', '/reviews', '/support', '/settings',
        ]);
    }

    #[DataProvider('oldPages')]
    public function testExistingPhpPagesStillRender(string $path): void
    {
        $response = $this->request($path);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('text/html', $response['headers']);
        self::assertStringContainsString('<!doctype html', strtolower($response['body']));
        self::assertStringNotContainsString('Fatal error', $response['body']);
        self::assertStringNotContainsString('Warning:', $response['body']);
    }

    public function testLegacyHistoryShapeErrorsAndPaginationArePreserved(): void
    {
        $path = '/api/platforms/sportybet/predictions';
        $first = json_decode($this->request($path)['body'], true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($this->request($path . '?page=2')['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['data', 'error', 'meta'], array_keys($first));
        self::assertNull($first['error']);
        self::assertCount(6, $first['data']);
        self::assertTrue($first['meta']['demo']);
        self::assertSame(2, $first['meta']['next_page']);
        self::assertSame([], array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
        self::assertArrayHasKey('odds_hundredths', $first['data'][0]);
        foreach (
            [[$path, 'POST', 405, 'Method not allowed.'],
            ['/api/platforms/missing/predictions', 'GET', 404, 'Platform not found.'],
            [$path . '?league=missing', 'GET', 404, 'League not found.']] as [$url, $method, $status, $error]
        ) {
            $response = $this->request($url, $method);
            self::assertSame($status, $response['status']);
            self::assertSame(['data' => null, 'error' => $error, 'meta' => null], json_decode($response['body'], true));
        }
    }

    public function testAllMarketsRenderWithNoSyntheticExtension(): void
    {
        foreach (['Under 1.5', 'Over 3.5'] as $market) {
            $response = $this->request('/sportybet?market=' . rawurlencode($market));
            self::assertSame(200, $response['status']);
            self::assertStringContainsString('No prediction available', $response['body']);
            self::assertStringContainsString('value="' . $market . '" checked', $response['body']);
            $history = $this->request('/api/platforms/sportybet/predictions?market=' . rawurlencode($market));
            $api = json_decode($history['body'], true);
            self::assertSame([], $api['data']);
            self::assertSame(0, $api['meta']['total']);
        }
        $hub = $this->request('/predictions?market=over_35');
        self::assertSame(200, $hub['status']);
        self::assertStringContainsString('No upcoming prediction is available', $hub['body']);
    }

    public function testV1NeverFallsThroughToHtmlOrTrustsPreviewAccess(): void
    {
        $catalog = $this->request('/api/v1/prediction-markets');
        self::assertSame(200, $catalog['status']);
        self::assertStringContainsString('application/json', $catalog['headers']);
        self::assertStringContainsString('Cache-Control: no-store', $catalog['headers']);
        self::assertCount(8, json_decode($catalog['body'], true)['data']);
        $missing = $this->request('/api/v1/missing');
        self::assertSame(404, $missing['status']);
        self::assertSame('not_found', json_decode($missing['body'], true)['error']['code']);
        foreach (['GET', 'PUT'] as $method) {
            $private = $this->request(
                '/api/v1/me/model-preferences?access=pro&user_id=1',
                $method,
                ['Cookie: user_id=1; plan=pro', 'X-User-Id: 1', 'X-CSRF-Token: forged']
            );
            self::assertContains($private['status'], [403, 503]);
            $code = $private['status'] === 403 ? 'https_required' : 'access_unavailable';
            self::assertSame($code, json_decode($private['body'], true)['error']['code']);
        }
        $head = $this->request('/api/v1/prediction-markets', 'HEAD');
        self::assertSame(405, $head['status']);
        self::assertSame('', $head['body']);
        self::assertStringContainsString('Allow: GET', $head['headers']);
    }

    public function testAccessHttpRoutesDoNotTrustIdentityOrProxyHeaders(): void
    {
        $public = $this->request('/api/v1/plans');
        self::assertContains($public['status'], [200, 503]);
        self::assertStringContainsString('application/json', $public['headers']);
        $data = json_decode($public['body'], true, 32, JSON_THROW_ON_ERROR);
        if ($public['status'] === 200) {
            foreach ($data['data'] as $plan) {
                self::assertFalse($plan['purchase_available']);
            }
        } else {
            self::assertSame('access_unavailable', $data['error']['code']);
        }
        foreach (['/api/v1/subscription', '/api/v1/me/notifications'] as $path) {
            $response = $this->request(
                $path . '?access=pro&user_id=1',
                'GET',
                ['X-Forwarded-Proto: https', 'X-User-Id: 1', 'Cookie: plan=pro; user_id=1']
            );
            self::assertContains($response['status'], [403, 422, 503]);
            self::assertNull(json_decode($response['body'], true)['data']);
            $headersOnly = $this->request(
                $path,
                'GET',
                ['X-Forwarded-Proto: https', 'X-User-Id: 1', 'Cookie: plan=pro; user_id=1']
            );
            self::assertContains($headersOnly['status'], [403, 503]);
            self::assertNull(json_decode($headersOnly['body'], true)['data']);
        }
    }

    public function testExperienceHttpPreservesPrivacyAndGatesLiveOperations(): void
    {
        foreach (['referrals', 'reviews', 'support/tickets', 'support/articles', 'chat/messages'] as $route) {
            $response = $this->request(
                '/api/v1/' . $route,
                'GET',
                ['Cookie: user_id=1; plan=max', 'X-User-Id: 1', 'X-Forwarded-Proto: https']
            );
            self::assertContains($response['status'], [403, 503]);
            self::assertStringContainsString('application/json', $response['headers']);
            self::assertStringContainsString('Cache-Control: no-store', $response['headers']);
            self::assertNull(json_decode($response['body'], true)['data']);
        }
        self::assertSame(404, $this->request('/api/v1/referrals/rewards/claim', 'POST')['status']);
        self::assertSame(404, $this->request('/api/v1/referrals/capture', 'POST')['status']);
    }

    public function testBillingHttpDefaultsCannotChargeOrTrustPreviewIdentity(): void
    {
        foreach (['/api/v1/billing/invoices', '/api/v1/wallet', '/api/v1/wallet/transactions'] as $path) {
            $response = $this->request(
                $path . '?user_id=1&access=max',
                'GET',
                ['X-Forwarded-Proto: https', 'Cookie: user_id=1; plan=max']
            );
            self::assertContains($response['status'], [403, 503]);
            self::assertNull(json_decode($response['body'], true)['data']);
        }
        foreach (['/api/v1/billing/checkouts', '/api/v1/billing/webhooks/paystack'] as $path) {
            $response = $this->request($path, 'POST');
            self::assertContains($response['status'], [401, 422, 503]);
            self::assertNull(json_decode($response['body'], true)['data']);
            self::assertStringContainsString('Cache-Control: no-store', $response['headers']);
        }
        self::assertSame(404, $this->request('/api/v1/wallet/withdraw', 'POST')['status']);
        self::assertSame(404, $this->request('/api/v1/billing/refunds', 'POST')['status']);
    }
}
