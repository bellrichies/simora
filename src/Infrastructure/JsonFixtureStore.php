<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class JsonFixtureStore
{
    private array $cache = [];

    public function __construct(private string $directory)
    {
    }

    /** Fingerprint the source so cached read models follow fixture replacements. */
    public function fingerprint(string $name): string
    {
        if (!preg_match('/^[a-z0-9-]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid fixture name.');
        }
        $path = $this->directory . '/' . $name . '.json';
        if (!is_readable($path) || ($hash = hash_file('sha256', $path)) === false) {
            throw new \RuntimeException('Demo data is unavailable.');
        }
        return $hash;
    }

    public function read(string $name): array
    {
        if (!preg_match('/^[a-z0-9-]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid fixture name.');
        }
        if (!isset($this->cache[$name])) {
            $path = $this->directory . '/' . $name . '.json';
            if (!is_readable($path)) {
                throw new \RuntimeException('Demo data is unavailable.');
            }
            $contents = file_get_contents($path);
            $data = json_decode($contents === false ? '' : $contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema_version'] ?? null) !== ($name === 'account' ? 2 : 3)) {
                throw new \RuntimeException('Unsupported demo data schema.');
            }
            FixtureValidator::validate($data, $name);
            $this->cache[$name] = $data;
        }
        return $this->cache[$name];
    }
}
