<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Domain\LiveSource\OutcomeEngine;
use App\Domain\LiveSource\QuoteEligibility;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$t = static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time, new DateTimeZone('UTC'));
$kickoff = $t('2026-01-01 12:00:00.000000');
$quote = new QuoteEligibility();
$eligible = static fn (string $decision, string $observed, string $available, string $ingested, string $expires, int $buffer = 60): bool => $quote->isEligible(
    $kickoff, $t($decision), $t($observed), $t($available), $t($ingested), $t($expires), $buffer
);
$check($eligible('2026-01-01 11:58:59.999999', '2026-01-01 11:58:00', '2026-01-01 11:58:00', '2026-01-01 11:58:00', '2026-01-01 11:59:00'), 'valid quote');
$check(!$eligible('2026-01-01 11:59:00', '2026-01-01 11:58:00', '2026-01-01 11:58:00', '2026-01-01 11:58:00', '2026-01-01 12:00:00'), 'cutoff is exclusive');
$check(!$eligible('2026-01-01 11:58:00', '2026-01-01 11:58:00.000001', '2026-01-01 11:58:01', '2026-01-01 11:58:01', '2026-01-01 12:00:00'), 'future observation');
$check(!$eligible('2026-01-01 11:58:00', '2026-01-01 11:57:00', '2026-01-01 11:58:00.000001', '2026-01-01 11:57:00', '2026-01-01 12:00:00'), 'future availability');
$check(!$eligible('2026-01-01 11:58:00', '2026-01-01 11:57:00', '2026-01-01 11:57:00', '2026-01-01 11:58:00.000001', '2026-01-01 12:00:00'), 'late ingestion');
$check(!$eligible('2026-01-01 11:58:00', '2026-01-01 11:57:00', '2026-01-01 11:57:00', '2026-01-01 11:57:00', '2026-01-01 11:58:00'), 'expiry is exclusive');
$engine = new OutcomeEngine();
foreach (range(0, 8) as $home) {
    foreach (range(0, 8) as $away) {
        $outcomes = $engine->outcomes('accepted', $home, $away);
        $check(count($outcomes) === 8, 'eight outcomes');
        foreach ([['over_15', 'under_15'], ['over_25', 'under_25'], ['over_35', 'under_35'], ['btts_yes', 'btts_no']] as [$a, $b]) {
            $check($outcomes[$a] !== $outcomes[$b], 'complementary outcomes');
        }
    }
}
$check($engine->outcomes('accepted', 0, 0)['over_25'] === 'lost', 'original revision');
$check($engine->outcomes('accepted', 2, 1)['over_25'] === 'won', 'corrected revision');
foreach (['void', 'disputed'] as $status) {
    $check(count(array_filter($engine->outcomes($status, null, null), static fn ($value) => $value === $status)) === 8, $status);
}
foreach ([static fn () => $engine->outcomes('accepted', null, 1), static fn () => $engine->outcomes('void', 0, 0), static fn () => $eligible('2026-01-01 11:58:00', '2026-01-01 11:57:00', '2026-01-01 11:56:00', '2026-01-01 11:57:00', '2026-01-01 12:00:00')] as $invalid) {
    try {
        $invalid();
        throw new RuntimeException('Invalid input accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
echo "Live source domain tests passed\n";
