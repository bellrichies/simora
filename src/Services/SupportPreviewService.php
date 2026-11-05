<?php

declare(strict_types=1);

namespace App\Services;

final class SupportPreviewService
{
    public function __construct(private array $fixture)
    {
    }

    public function getPage(array $query): array
    {
        $categories = $this->fixture['categories'];
        $statuses = ['open' => 'Open', 'waiting_support' => 'In review', 'waiting_customer' => 'Needs your reply', 'resolved' => 'Resolved', 'closed' => 'Closed'];
        $search = is_string($query['q'] ?? null) ? substr(trim($query['q']), 0, 100) : '';
        $category = is_string($query['category'] ?? null) && isset($categories[$query['category']]) ? $query['category'] : 'all';
        $status = is_string($query['status'] ?? null) && isset($statuses[$query['status']]) ? $query['status'] : 'all';
        $articles = array_values(array_filter($this->fixture['articles'], static fn (array $row): bool =>
            ($category === 'all' || $row['category'] === $category)
            && ($search === '' || stripos($row['question'] . ' ' . $row['answer'] . ' ' . $categories[$row['category']], $search) !== false)
        ));
        $counts = array_fill_keys(array_keys($statuses), 0);
        foreach ($this->fixture['tickets'] as $ticket) {
            $counts[$ticket['status']]++;
        }
        $tickets = array_values(array_filter($this->fixture['tickets'], static fn (array $row): bool => $status === 'all' || $row['status'] === $status));
        usort($tickets, static fn (array $a, array $b): int => strcmp($b['updated_at'] . $b['id'], $a['updated_at'] . $a['id']));
        $selected = null;
        foreach ($this->fixture['tickets'] as $ticket) {
            if (is_string($query['ticket'] ?? null) && $query['ticket'] === $ticket['id']) {
                $selected = $ticket;
                break;
            }
        }
        return [
            'categories' => $categories, 'statuses' => $statuses, 'search' => $search, 'category' => $category,
            'status' => $status, 'articles' => $articles, 'tickets' => $tickets, 'selected' => $selected,
            'active' => $counts['open'] + $counts['waiting_support'] + $counts['waiting_customer'],
            'waiting' => $counts['waiting_customer'], 'resolved' => $counts['resolved'] + $counts['closed'],
            'total' => count($this->fixture['tickets']), 'article_count' => count($this->fixture['articles']),
        ];
    }
}
