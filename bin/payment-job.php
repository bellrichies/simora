<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

if (
    PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['checkout', 'webhook', 'reconcile'], true)
    || preg_match('/^[1-9][0-9]{0,17}$/D', $argv[2] ?? '') !== 1
) {
    fwrite(STDERR, "Usage: php bin/payment-job.php checkout|webhook|reconcile <internal-id>\n");
    exit(1);
}
try {
    $service = App\Infrastructure\PaymentServiceFactory::create(
        App\Infrastructure\DatabaseConnection::connect(),
        require dirname(__DIR__) . '/config/payments.php'
    );
    $method = ['checkout' => 'initialize', 'webhook' => 'process', 'reconcile' => 'reconcile'][$argv[1]];
    $result = $service->$method((int) $argv[2]);
    echo json_encode(
        ['status' => $result['status'] ?? 'unknown', 'result' => $result['result_code'] ?? null],
        JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (Throwable $error) {
    $code = $error instanceof App\Domain\Payments\PaymentException ? $error->errorCode : 'payment_job_failed';
    fwrite(STDERR, $code . PHP_EOL);
    exit(1);
}
