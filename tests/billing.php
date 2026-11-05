<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});
$repository = new App\Repositories\DemoBillingRepository(dirname(__DIR__) . '/database/fixtures/billing.php');
$service = new App\Services\BillingService($repository);
$data = $repository->getData();
$check = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
foreach ($data['transactions'] as $row) {
    $invoice = $row['invoice'];
    $sum = array_sum(array_column($invoice['line_items'], 'amount_minor'));
    $check($sum === $invoice['subtotal_minor'], 'Line items must equal subtotal');
    $check($sum - $invoice['discount_minor'] + $invoice['tax_minor'] === $invoice['total_minor'], 'Invoice total mismatch');
    $check($invoice['total_minor'] === $row['amount_minor'], 'Transaction amount mismatch');
    $check($invoice['subscription_id'] === $data['subscription']['id'], 'Subscription mismatch');
}
$first = $service->getPage([]);
$check(count($first['history']) === 3 && $first['pages'] === 2, 'Pagination failed');
$second = $service->getPage(['page' => '2']);
$check($first['history'][0]['id'] !== $second['history'][0]['id'], 'Pagination repeats rows');
$failed = $service->getPage(['status' => 'failed']);
$check(count($failed['history']) === 1 && $failed['history'][0]['status'] === 'failed', 'Filtering failed');
$oldest = $service->getPage(['sort' => 'oldest']);
$check($oldest['history'][0]['date'] < $first['history'][0]['date'], 'Sorting failed');
$detail = $service->getPage(['invoice' => 'demo-inv-001']);
$check($detail['selected_invoice']['invoice']['number'] === 'INV-2026-000001', 'Invoice lookup failed');
echo "Billing tests passed\n";
