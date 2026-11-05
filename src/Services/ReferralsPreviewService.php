<?php

declare(strict_types=1);

namespace App\Services;

final class ReferralsPreviewService
{
    public function __construct(private array $fixture)
    {
    }

    public function getPage(array $query): array
    {
        $statuses = ['Registered', 'Pending', 'Credited', 'Ineligible'];
        $status = is_string($query['status'] ?? null) && in_array($query['status'], $statuses, true)
            ? $query['status'] : 'all';
        $sort = ($query['sort'] ?? null) === 'oldest' ? 'oldest' : 'newest';
        $counts = array_fill_keys($statuses, 0);
        $earned = 0;
        $pending = 0;
        foreach ($this->fixture['referrals'] as $row) {
            $counts[$row['status']]++;
            if ($row['status'] === 'Credited') {
                $earned += $row['reward_minor'];
            } elseif ($row['status'] === 'Pending') {
                $pending += $row['reward_minor'];
            }
        }
        $rows = array_values(array_filter($this->fixture['referrals'], static fn (array $row): bool =>
            $status === 'all' || $row['status'] === $status
        ));
        usort($rows, static fn (array $a, array $b): int => $sort === 'oldest'
            ? strcmp($a['joined_at'] . $a['id'], $b['joined_at'] . $b['id'])
            : strcmp($b['joined_at'] . $b['id'], $a['joined_at'] . $a['id']));
        $total = count($rows);
        $pageSize = 6;
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $query['page'])
            ? min((int) $query['page'], $pages) : 1;
        $rows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return [
            'code' => $this->fixture['code'], 'link' => $this->fixture['link'],
            'as_of' => $this->fixture['as_of'], 'referred' => count($this->fixture['referrals']),
            'qualified' => $counts['Pending'] + $counts['Credited'],
            'counts' => $counts, 'earned' => $earned, 'pending' => $pending,
            'rows' => $rows, 'status' => $status, 'sort' => $sort, 'statuses' => $statuses,
            'page' => $page, 'pages' => $pages, 'page_size' => $pageSize, 'total' => $total,
        ];
    }
}
