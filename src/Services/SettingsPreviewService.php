<?php

declare(strict_types=1);

namespace App\Services;

final class SettingsPreviewService
{
    public function __construct(private array $fixture, private array $configuration, private array $platforms)
    {
    }

    public function getPage(array $query, array $account): array
    {
        $section = is_string($query['section'] ?? null) && isset($this->fixture['sections'][$query['section']])
            ? $query['section'] : 'profile';
        $defaults = [
            'name' => $account['name'], 'timezone' => $this->configuration['timezone'],
            'platform' => $this->platforms[0]['slug'] ?? '', 'market' => $this->configuration['default_market'],
            'notifications' => array_column($this->fixture['notifications'], 'enabled', 'id'),
        ];
        return array_merge($this->fixture, [
            'section' => $section, 'account' => $account, 'defaults' => $defaults,
            'platforms' => array_map(static fn (array $platform): array => ['id' => $platform['slug'], 'label' => $platform['name']], $this->platforms),
            'markets' => $this->configuration['markets'], 'currency' => $this->configuration['currency'],
            'notification_count' => count(array_filter($defaults['notifications'])),
        ]);
    }
}
