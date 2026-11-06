<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Infrastructure/MigrationRunner.php';

use App\Infrastructure\MigrationRunner;

final class LedgerTestPdo extends PDO
{
    public array $inserted = [];
    public function __construct(public string $dependencyChecksum) {}
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new LedgerTestStatement($this, $query);
    }
    public function exec(string $statement): int|false { return 0; }
}

final class LedgerTestStatement extends PDOStatement
{
    private array $params = [];
    public function __construct(private LedgerTestPdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_starts_with($this->sql, 'INSERT INTO schema_migrations')) {
            $this->pdo->inserted = $this->params;
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return ['status' => 'succeeded', 'checksum' => $this->pdo->dependencyChecksum];
    }
    public function fetchColumn(int $column = 0): mixed { return 0; }
}

$directory = dirname(__DIR__) . '/database/migrations';
$dependency = hash_file('sha256', $directory . '/000_schema_ledger.sql');
$target = hash_file('sha256', $directory . '/010_catalog.sql');
$pdo = new LedgerTestPdo($dependency);
$runner = new MigrationRunner($pdo, $directory);
$apply = new ReflectionMethod($runner, 'apply');
$apply->invoke($runner, '010_catalog', 'SELECT 1', $target, ['000_schema_ledger'], 'test', 'test');
if (($pdo->inserted[1] ?? null) !== $target || $target === $dependency) {
    throw new RuntimeException('Migration ledger recorded an incorrect checksum.');
}
echo "Migration checksum regression test passed\n";
