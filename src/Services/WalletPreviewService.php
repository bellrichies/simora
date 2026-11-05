<?php

declare(strict_types=1);

namespace App\Services;

final class WalletPreviewService
{
    public function __construct(private array $activity)
    {
    }

    public function getPage(array $query): array
    {
        $type = is_string($query['type'] ?? null) && in_array($query['type'], ['Reward', 'Subscription'], true) ? $query['type'] : 'all';
        $status = is_string($query['status'] ?? null) && in_array($query['status'], ['Completed', 'Pending', 'Reserved'], true) ? $query['status'] : 'all';
        $available = 0;
        $pending = 0;
        $earned = 0;
        $used = 0;
        $reserved = 0;
        foreach ($this->activity as $item) {
            if ($item['status'] === 'Pending') {
                $pending += $item['amount_minor'];
            } elseif ($item['status'] === 'Reserved') {
                $reserved += abs($item['amount_minor']);
                $available -= abs($item['amount_minor']);
            } else {
                $available += $item['amount_minor'];
                $earned += max(0, $item['amount_minor']);
                $used += max(0, -$item['amount_minor']);
            }
        }
        $rows = array_values(array_filter($this->activity, static fn (array $item): bool =>
            ($type === 'all' || $item['type'] === $type) && ($status === 'all' || $item['status'] === $status)
        ));

        usort($rows, static fn (array $left, array $right): int => strcmp($right['date'], $left['date']));

        return compact('type', 'status', 'available', 'pending', 'reserved', 'earned', 'used', 'rows');
    }
}
