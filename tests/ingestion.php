<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Domain\Ingestion\GrantPolicy;
use App\Domain\Ingestion\Idempotency;
use App\Domain\Ingestion\PayloadEvidence;

$check = static function (bool $value, string $message): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
};
$now = new DateTimeImmutable('2026-01-02T00:00:00Z');
$grant = ['source' => 'a', 'scope' => 'league', 'method' => 'import', 'use' => 'ingest',
    'source_status' => 'approved', 'evidence' => 'contract-1',
    'valid_from' => new DateTimeImmutable('2026-01-01T00:00:00Z'),
    'expires_at' => new DateTimeImmutable('2026-01-03T00:00:00Z'), 'revoked_at' => null];
$policy = new GrantPolicy();
$check($policy->permits($grant, 'a', 'league', 'import', 'ingest', $now), 'valid grant');
foreach ([['b','league','import','ingest'], ['a','other','import','ingest'], ['a','league','scrape','ingest'], ['a','league','import','train']] as $wrong) {
    $check(!$policy->permits($grant, ...[...$wrong, $now]), 'grant scope');
}
$check(!$policy->permits($grant, 'a', 'league', 'import', 'ingest', $grant['expires_at']), 'expiry');
$grant['revoked_at'] = $now;
$check(!$policy->permits($grant, 'a', 'league', 'import', 'ingest', $now), 'revocation');
$evidence = new PayloadEvidence();
$check($evidence->hash('abc') === 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', 'raw hash');
$evidence->validateTimes($now, $now, $now);
try {
    $evidence->validateTimes($now, $now->modify('-1 second'), $now);
    throw new RuntimeException('Past availability accepted');
} catch (InvalidArgumentException $expected) {
}
$ids = new Idempotency();
$first = $ids->fingerprint(['ab', 'c']);
$check($ids->isReplay($first, $ids->fingerprint(['ab', 'c'])), 'replay');
$check(!$ids->isReplay($first, $ids->fingerprint(['a', 'bc'])), 'fingerprint collision');
echo "Ingestion policy and evidence tests passed\n";
