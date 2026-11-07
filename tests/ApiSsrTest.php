<?php

declare(strict_types=1);

namespace SimoraTests;

use App\Domain\MarketCatalog;
use App\Domain\ModelPreference;
use App\Http\AnalyticsApiController;
use App\Infrastructure\SystemClock;
use App\Services\AnalyticsApiService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiSsrTest extends TestCase
{
    private function controller(): AnalyticsApiController
    {
        return new AnalyticsApiController(
            new AnalyticsApiService(require dirname(__DIR__) . '/config/platforms.php'),
            new SystemClock()
        );
    }

    public function testMarketContractContainsEveryCanonicalRule(): void
    {
        $response = $this->controller()->handle('GET', '/api/v1/prediction-markets', []);
        self::assertSame(200, $response->status);
        self::assertSame(array_keys(MarketCatalog::all()), array_column($response->body['data'], 'id'));
        self::assertSame(array_fill(0, 8, 1), array_column($response->body['data'], 'rule_version'));
        self::assertNull($response->body['error']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $response->body['meta']['request_id']);
        self::assertStringEndsWith('Z', $response->body['meta']['as_of']);
        $settings = require dirname(__DIR__) . '/config/app.php';
        self::assertEqualsCanonicalizing(array_keys(MarketCatalog::all()), array_column($settings['markets'], 'id'));
    }

    public function testCatalogDoesNotClaimAConnectedProvider(): void
    {
        $response = $this->controller()->handle('GET', '/api/v1/platforms', []);
        self::assertSame(200, $response->status);
        self::assertCount(3, $response->body['data']);
        self::assertSame(array_fill(0, 3, 'unavailable'), array_column($response->body['data'], 'availability_state'));
    }

    public static function unavailableRoutes(): array
    {
        return array_map(static fn (string $path): array => [$path], [
            '/api/v1/models', '/api/v1/models/compare', '/api/v1/models/spe',
            '/api/v1/models/spe/performance', '/api/v1/predictions/history', '/api/v1/predictions/example',
            '/api/v1/me/model-preferences', '/api/v1/platforms/sportybet/leagues',
            '/api/v1/leagues/example/rounds', '/api/v1/rounds/example/matches',
            '/api/v1/matches/example', '/api/v1/matches/example/odds',
            '/api/v1/analytics/platforms/sportybet/performance',
            '/api/v1/backtests/example', '/api/v1/simulations/example', '/api/v1/admin/models/health',
        ]);
    }

    #[DataProvider('unavailableRoutes')]
    public function testUnavailableDependenciesNeverServeDemoRecords(string $path): void
    {
        $response = $this->controller()->handle('GET', $path, ['access' => 'pro', 'user_id' => '1']);
        self::assertSame(503, $response->status);
        self::assertNull($response->body['data']);
        self::assertContains($response->body['error']['code'], ['access_unavailable', 'source_unavailable']);
        self::assertSame('unavailable', $response->body['meta']['data_mode']);
    }

    public function testCanonicalRoutesAndMethodErrors(): void
    {
        $controller = $this->controller();
        foreach (['/api/v1/strategy-simulations', '/api/v1/users/me/model-preferences', '/api/v1/missing'] as $path) {
            self::assertSame(404, $controller->handle('GET', $path, [])->status);
        }
        $method = $controller->handle('POST', '/api/v1/me/model-preferences', []);
        self::assertSame(405, $method->status);
        self::assertSame('GET, PUT', $method->headers['Allow']);
        self::assertSame(503, $controller->handle('PUT', '/api/v1/me/model-preferences', [])->status);
        self::assertSame(404, $controller->handle('GET', '/api/v1/platforms/missing/leagues', [])->status);
        self::assertSame(422, $controller->handle('GET', '/api/v1/predictions/best', [])->status);
        foreach (
            ['/api/v1/backtests', '/api/v1/simulations/strategy',
            '/api/v1/admin/models/spe/training-runs', '/api/v1/admin/models/spe/activate-version',
            '/api/v1/admin/models/spe/suspend'] as $path
        ) {
            self::assertSame(503, $controller->handle('POST', $path, [])->status);
            self::assertSame(405, $controller->handle('GET', $path, [])->status);
        }
    }

    public function testBestRequestRejectsAmbiguousAndMalformedScope(): void
    {
        $controller = $this->controller();
        $valid = ['model_mode' => 'manual', 'model_code' => 'spe', 'ranking_mode' => 'best_value',
            'platforms' => 'sportybet', 'leagues' => 'sportybet-league',
            'from' => '2026-11-06T00:00:00Z', 'to' => '2026-11-07T00:00:00Z'];
        self::assertSame(503, $controller->handle('GET', '/api/v1/predictions/best', $valid)->status);
        foreach (
            [['mode' => 'manual'], ['markets' => 'invalid'], ['markets' => ['over_15']],
            ['from' => '2026-02-30T00:00:00Z'], ['limit' => '101'], ['model_code' => 'unknown'],
            ['from' => $valid['to']], ['platforms' => ''], ['leagues' => '']] as $change
        ) {
            $response = $controller->handle('GET', '/api/v1/predictions/best', array_replace($valid, $change));
            self::assertSame(422, $response->status, json_encode($change));
            self::assertSame('validation_failed', $response->body['error']['code']);
        }
    }

    public function testNormalizedPreferenceScopeAndPrecedence(): void
    {
        $platforms = require dirname(__DIR__) . '/config/platforms.php';
        $base = ['model_mode' => 'validated_best', 'expected_version' => 0];
        $global = ModelPreference::validate($base, $platforms);
        $platform = ModelPreference::validate($base + ['platform' => 'sportybet'], $platforms);
        $market = ModelPreference::validate($base + ['market' => 'under_15'], $platforms);
        $combined = ModelPreference::validate($base + ['market' => 'under_15', 'platform' => 'sportybet'], $platforms);
        self::assertSame('global', $global['scope_key']);
        self::assertSame('platform:sportybet|market:under_15', $combined['scope_key']);
        $rows = [$global, $platform, $market, $combined];
        self::assertSame($combined, ModelPreference::resolve($rows, 'sportybet', 'under_15'));
        self::assertSame($market, ModelPreference::resolve([$global, $platform, $market], 'sportybet', 'under_15'));
        self::assertSame($platform, ModelPreference::resolve([$global, $platform], 'sportybet', 'under_15'));
        self::assertSame($global, ModelPreference::resolve([$global], 'sportybet', 'under_15'));
        self::assertNull(ModelPreference::resolve([], 'sportybet', 'under_15'));
    }

    public static function invalidPreferences(): array
    {
        return array_map(static fn (array $change): array => [$change], [
            ['user_id' => 2], ['expected_version' => '1'], ['expected_version' => -1],
            ['platform' => 'unknown'], ['market' => 'unknown'], ['model_mode' => 'auto'],
            ['model_mode' => 'manual'], ['model_mode' => 'manual', 'model_code' => 'unapproved'],
            ['model_code' => 'spe'], ['platform' => []], ['market' => []],
        ]);
    }

    #[DataProvider('invalidPreferences')]
    public function testPreferenceValidationDoesNotTrustOwnerOrMode(array $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModelPreference::validate(
            array_replace(['model_mode' => 'validated_best', 'expected_version' => 0], $change),
            require dirname(__DIR__) . '/config/platforms.php'
        );
    }
}
