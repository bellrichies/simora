<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

use App\Domain\MarketCatalog;
use App\Domain\MarketSettlement;
use App\Domain\SourceScopedId;
use App\Infrastructure\LegacyCatalogAdapter;

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$markets = MarketCatalog::all();
$check(count($markets) === 8, 'Eight markets required');
$migration = file_get_contents(dirname(__DIR__) . '/database/migrations/010_catalog.sql');
$check(is_string($migration), 'Catalog migration readable');
preg_match_all("/\\('(over_15|under_15|over_25|under_25|over_35|under_35|btts_yes|btts_no)'\\s*,/", $migration, $seedMatches);
$seedIds = $seedMatches[1];
sort($seedIds);
$registryIds = array_keys($markets);
sort($registryIds);
$check($seedIds === $registryIds, 'SQL seed and canonical registry differ');
foreach ($markets as $id => $rule) {
    $check(isset($markets[$rule['complement']]) && $markets[$rule['complement']]['complement'] === $id, 'Complement registry');
    for ($home = 0; $home <= 8; $home++) {
        for ($away = 0; $away <= 8; $away++) {
            $check(MarketSettlement::won($id, $home, $away) !== MarketSettlement::won($rule['complement'], $home, $away), 'Complement settlement');
            $check(!MarketSettlement::won('over_35', $home, $away) || MarketSettlement::won('over_25', $home, $away), '3.5 implies 2.5');
            $check(!MarketSettlement::won('over_25', $home, $away) || MarketSettlement::won('over_15', $home, $away), '2.5 implies 1.5');
        }
    }
}
foreach ([['unknown', 0, 0], ['over_15', -1, 0]] as [$market, $home, $away]) {
    try {
        MarketSettlement::won($market, $home, $away);
        throw new RuntimeException('Invalid settlement accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
$a = SourceScopedId::fromExternal('match', 'source-a', 'league-a', '123');
$check($a === SourceScopedId::fromExternal('match', 'source-a', 'league-a', '123'), 'Stable ID');
$check($a !== SourceScopedId::fromExternal('match', 'source-b', 'league-a', '123'), 'Source separation');
$check($a !== SourceScopedId::fromExternal('match', 'source-a', 'league-b', '123'), 'Scope separation');
$check(SourceScopedId::fromExternal('match', 'ab', 'c', 'd') !== SourceScopedId::fromExternal('match', 'a', 'bc', 'd'), 'Length separation');
$check(strlen($a) <= 128 && preg_match('/^[\x20-\x7e]+$/D', $a) === 1, 'Canonical ASCII length');
$adapter = new LegacyCatalogAdapter();
$check($adapter->roundId('source-a', 'league-a', 'week-1') !== $adapter->roundId('source-a', 'league-b', 'week-1'), 'Legacy week scope');
try {
    SourceScopedId::fromExternal('match', '', 'scope', 'id');
    throw new RuntimeException('Empty source accepted');
} catch (InvalidArgumentException $expected) {
}
echo "Catalog market and identity tests passed\n";
