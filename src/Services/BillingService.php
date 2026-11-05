<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\BillingRepository;

final class BillingService
{
    public function __construct(private BillingRepository $repository) {}

    public function getPage(array $query): array
    {
        $data = $this->repository->getData();
        $status = is_string($query['status'] ?? null) ? $query['status'] : 'all';
        if (!in_array($status, ['all', 'successful', 'pending', 'failed', 'refunded'], true)) {
            $status = 'all';
        }
        $sort = ($query['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest';
        $records = array_values(array_filter($data['transactions'], static fn (array $row): bool => $status === 'all' || $row['status'] === $status));
        usort($records, static fn (array $a, array $b): int => $sort === 'oldest' ? strcmp($a['date'] . $a['id'], $b['date'] . $b['id']) : strcmp($b['date'] . $b['id'], $a['date'] . $a['id']));
        $pages = max(1, (int) ceil(count($records) / 3));
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $query['page']) ? min((int) $query['page'], $pages) : 1;
        $invoiceId = is_string($query['invoice'] ?? null) ? $query['invoice'] : '';
        $invoice = null;
        foreach ($data['transactions'] as $row) {
            if ($row['invoice_id'] === $invoiceId) {
                $invoice = $row;
                break;
            }
        }
        return $data + ['history' => array_slice($records, ($page - 1) * 3, 3), 'total' => count($records), 'status_filter' => $status, 'sort' => $sort, 'page' => $page, 'pages' => $pages, 'selected_invoice' => $invoice];
    }
}
