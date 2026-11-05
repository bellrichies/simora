<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

$options = getopt('', ['seed:', 'as-of:']);
$seed = filter_var($options['seed'] ?? '20261103', FILTER_VALIDATE_INT);
$date = $options['as-of'] ?? gmdate('Y-m-d');
if ($seed === false || $seed < 1 || $seed > 2147483646 || !is_string($date)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
    || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
    fwrite(STDERR, "Usage: php bin/generate-demo-data.php [--seed=20261103] [--as-of=2026-11-03]\n");
    exit(1);
}
$asOf = new DateTimeImmutable($date . 'T00:00:00Z');
$settings = require dirname(__DIR__) . '/config/app.php';
$platforms = require dirname(__DIR__) . '/config/platforms.php';
$generator = new App\Infrastructure\DemoDatasetGenerator();
foreach ($platforms as $slug => $platform) {
    $data = $generator->generate($slug, $platform, $settings, $seed, $asOf);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $path = dirname(__DIR__) . '/database/fixtures/' . $slug . '.json';
    // Readers see a complete snapshot, even if regeneration happens while serving requests.
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        throw new RuntimeException('Could not write demo fixture: ' . $slug);
    }
    echo $slug . ': 6 leagues; 3,000 settled matches, 4,320 displayed predictions, 60 scheduled matches, 72 previews.' . PHP_EOL;
}
