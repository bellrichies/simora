<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$db = SimoraTests\Support\AccessTestDatabase::connect();
$clock = new App\Infrastructure\SystemClock();
$http = new SimoraTests\Support\PaystackTestTransport();
$http->response = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$access = new App\Repositories\AccessRepository($db, 'sandbox');
$service = new App\Services\PaymentLifecycleService(
    new App\Repositories\PaymentRepository($db, $access),
    new App\Infrastructure\PaystackProvider($http, $clock, 'unit', ['whk_unit' => 'whsec_unit_only']),
    new App\Infrastructure\SandboxInvoiceQuoteAuthority(),
    new App\Services\SubscriptionCreditLedger($db, $access),
    new App\Domain\Payments\PaymentApproval(
        'paystack',
        'unit',
        'sandbox',
        'unit-only',
        'sandbox-only:no-real-tax',
        'sandbox-only:unissued-document',
        new DateTimeImmutable('+1 day'),
        300
    ),
    $clock,
    'https://example.invalid/plans'
);
try {
    $result = $service->process((int) ($argv[1] ?? 0));
    echo $result['status'];
} catch (Throwable $error) {
    fwrite(STDERR, 'isolated_worker_failed');
    exit(1);
}
