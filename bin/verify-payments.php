<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $db = App\Infrastructure\DatabaseConnection::connect();
    $queries = [
        'unbalanced_or_incomplete_journals' => 'SELECT COUNT(*) FROM (SELECT j.id FROM wallet_journals j '
            . 'LEFT JOIN wallet_postings p ON p.journal_id=j.id GROUP BY j.id '
            . 'HAVING COUNT(p.id)<>2 OR SUM(p.debit_minor)<>SUM(p.credit_minor) OR SUM(p.debit_minor)<=0) bad',
        'posting_owner_or_currency_mismatches' => 'SELECT COUNT(*) FROM wallet_postings p '
            . 'JOIN wallet_accounts a ON a.id=p.account_id JOIN wallet_journals j ON j.id=p.journal_id '
            . "WHERE a.currency<>j.currency OR (a.account_type='subscription_credit' AND a.user_id<>j.user_id)",
        'cache_mismatches' => 'SELECT COUNT(*) FROM wallet_accounts a '
            . 'LEFT JOIN wallet_balances b ON b.account_id=a.id LEFT JOIN '
            . '(SELECT account_id,SUM(CAST(credit_minor AS SIGNED)-CAST(debit_minor AS SIGNED)) total '
            . 'FROM wallet_postings GROUP BY account_id) p ON p.account_id=a.id '
            . 'WHERE b.account_id IS NULL OR b.current_minor<>COALESCE(p.total,0)',
        'negative_customer_credit' => 'SELECT COUNT(*) FROM (SELECT a.id FROM wallet_accounts a '
            . 'JOIN wallet_postings p ON p.account_id=a.id '
            . "WHERE a.account_type='subscription_credit' GROUP BY a.id "
            . 'HAVING SUM(CAST(p.credit_minor AS SIGNED)-CAST(p.debit_minor AS SIGNED))<0) bad',
        'invalid_paid_invoices' => 'SELECT COUNT(*) FROM invoices i LEFT JOIN payments p ON p.id=i.paid_payment_id '
            . "WHERE i.status='paid' AND (p.id IS NULL OR p.status<>'succeeded' OR p.invoice_id<>i.id "
            . 'OR p.user_id<>i.user_id OR p.amount_minor<>i.total_minor OR p.currency<>i.currency)',
        'unaccounted_successful_payments' => 'SELECT COUNT(*) FROM payments p WHERE p.status=\'succeeded\' '
            . 'AND ((SELECT COUNT(*) FROM wallet_journals j WHERE j.payment_id=p.id '
            . "AND j.purpose='verified_subscription_funding')<>1 OR "
            . '(SELECT COUNT(*) FROM wallet_journals j WHERE j.payment_id=p.id '
            . "AND j.purpose='subscription_application')<>1)",
    ];
    $violations = [];
    foreach ($queries as $key => $sql) {
        $violations[$key] = (int) $db->query($sql)->fetchColumn();
    }
    echo json_encode(['verified_at' => gmdate('Y-m-d\TH:i:s\Z'), 'violations' => $violations,
        'payments' => (int) $db->query('SELECT COUNT(*) FROM payments')->fetchColumn(),
        'journals' => (int) $db->query('SELECT COUNT(*) FROM wallet_journals')->fetchColumn(),
        'payouts_enabled' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(array_sum($violations) === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "Payment accounting verification unavailable.\n");
    exit(1);
}
