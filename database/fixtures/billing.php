<?php

declare(strict_types=1);

// Synthetic examples only. Amounts are in kobo; no customer or provider data.
$plans = [
    ['id' => 'trial', 'name' => 'Trial', 'amount_minor' => 200000, 'interval' => 'day', 'description' => 'Get started with daily access.', 'icon' => 'lightning'],
    ['id' => 'standard', 'name' => 'Standard', 'amount_minor' => 3000000, 'interval' => '2 weeks', 'description' => 'Access for two weeks.', 'icon' => 'layers'],
    ['id' => 'pro', 'name' => 'Pro', 'amount_minor' => 5000000, 'interval' => 'month', 'description' => 'Access for one month.', 'icon' => 'stars'],
    ['id' => 'max', 'name' => 'Max', 'amount_minor' => 8000000, 'interval' => 'month', 'description' => 'Access for one month.', 'icon' => 'gem'],
];
$records = [];
$presentation = [
    'trial' => ['tagline' => 'Find your feet. Explore the insights.', 'cadence' => 'One day, one simple payment', 'features' => ['24-hour platform access', '2 match analyses', 'Core prediction models'], 'action' => 'Start your trial'],
    'standard' => ['tagline' => 'Build a rhythm with more access.', 'cadence' => 'Billed every two weeks', 'features' => ['14 days of platform access', '5 match analyses', 'Core prediction models'], 'action' => 'Choose Standard'],
    'pro' => ['tagline' => 'More insights. More possibilities.', 'cadence' => 'Billed monthly', 'features' => ['30 days of platform access', '10 match analyses', 'All prediction models'], 'action' => 'Current plan'],
    'max' => ['tagline' => 'The full picture. Without limits.', 'cadence' => 'Billed monthly', 'features' => ['30 days of platform access', '25 match analyses', 'All models + priority support'], 'action' => 'Upgrade to Max'],
];
// Mockup allowances illustrate the design; they are not production entitlements.
foreach ($plans as &$plan) {
    $plan += $presentation[$plan['id']];
}
unset($plan);
foreach ([
    ['001', '2026-11-01', '2026-11-01', '2026-12-01', 'successful', 'paid'],
    ['002', '2026-09-01', '2026-09-01', '2026-10-01', 'successful', 'paid'],
    ['003', '2026-08-01', '2026-08-01', '2026-09-01', 'refunded', 'refunded'],
    ['004', '2026-07-01', '2026-07-01', '2026-08-01', 'failed', 'unpaid'],
    ['005', '2026-06-01', '2026-06-01', '2026-07-01', 'pending', 'pending'],
    ['006', '2026-05-01', '2026-05-01', '2026-06-01', 'successful', 'paid'],
] as [$id, $date, $start, $end, $status, $invoiceStatus]) {
    $records[] = ['id' => 'demo-txn-' . $id, 'invoice_id' => 'demo-inv-' . $id, 'reference' => 'PAY-2026-' . str_pad($id, 6, '0', STR_PAD_LEFT), 'date' => $date, 'plan_id' => 'pro', 'plan' => 'Pro', 'interval' => 'month', 'amount_minor' => 5000000, 'currency' => 'NGN', 'payment_method' => 'Card payment', 'status' => $status, 'invoice' => ['number' => 'INV-2026-' . str_pad($id, 6, '0', STR_PAD_LEFT), 'issue_date' => $date, 'due_date' => $date, 'period_start' => $start, 'period_end' => $end, 'subscription_id' => 'demo-sub-001', 'line_items' => [['description' => 'Pro monthly subscription', 'amount_minor' => 5000000]], 'subtotal_minor' => 5000000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 5000000, 'status' => $invoiceStatus]];
}
return ['plans' => $plans, 'subscription' => ['id' => 'demo-sub-001', 'plan_id' => 'pro', 'status' => 'active', 'activated_at' => '2026-05-01', 'period_start' => '2026-11-01', 'period_end' => '2026-12-01', 'auto_renew' => true, 'cancel_at_period_end' => false], 'usage' => [['label' => 'Analyses', 'used' => 7, 'limit' => 10, 'reset_at' => '2026-12-01']], 'preferences' => ['payment_method' => 'Card payment', 'invoice_delivery' => 'Email', 'notifications' => 'Renewal reminders'], 'transactions' => $records];

