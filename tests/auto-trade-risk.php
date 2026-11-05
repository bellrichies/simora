<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Domain/AutoTrade/RiskAssessment.php';

$risk = new App\Domain\AutoTrade\RiskAssessment();
$cases = [
    [[100, 100, 200, 300, 600, 100], true, []],
    [[101, 100, 200, 300, 600, 100], false, ['max_stake', 'insufficient_balance', 'loss_budget']],
    [[1, 100, 600, 0, 600, 100], false, ['loss_budget']],
    [[1, 100, 0, PHP_INT_MAX, PHP_INT_MAX, 100], false, ['loss_budget']],
];
foreach ($cases as [$arguments, $allowed, $reasons]) {
    $result = $risk->evaluate(...$arguments);
    if ($result !== ['allowed' => $allowed, 'reasons' => $reasons]) {
        throw new RuntimeException('Unexpected risk assessment: ' . json_encode($result));
    }
}
try {
    $risk->evaluate(0, 1, 0, 0, 1, 1);
    throw new RuntimeException('Invalid stake was accepted.');
} catch (InvalidArgumentException $expected) {
}
echo "Auto Trade risk checks passed.\n";
