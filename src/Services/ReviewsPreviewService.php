<?php

declare(strict_types=1);

namespace App\Services;

final class ReviewsPreviewService
{
    public function __construct(private array $reviews)
    {
    }

    public function getPage(array $query): array
    {
        $published = array_values(array_filter($this->reviews, static fn (array $row): bool => $row['status'] === 'published'));
        $count = count($published);
        $buckets = array_fill(1, 5, 0);
        foreach ($published as $row) {
            $buckets[$row['rating']]++;
        }
        $average = $count ? array_sum(array_column($published, 'rating')) / $count : null;
        $positive = $count ? (int) round(100 * ($buckets[4] + $buckets[5]) / $count) : null;
        $rating = is_string($query['rating'] ?? null) && in_array($query['rating'], ['1', '2', '3', '4', '5'], true)
            ? $query['rating'] : 'all';
        $sort = is_string($query['sort'] ?? null) && in_array($query['sort'], ['highest', 'lowest'], true)
            ? $query['sort'] : 'newest';
        $rows = array_values(array_filter($published, static fn (array $row): bool => $rating === 'all' || $row['rating'] === (int) $rating));
        usort($rows, static function (array $a, array $b) use ($sort): int {
            $byRating = match ($sort) {
                'highest' => $b['rating'] <=> $a['rating'],
                'lowest' => $a['rating'] <=> $b['rating'],
                default => 0,
            };
            return $byRating ?: strcmp($b['date'] . $b['id'], $a['date'] . $a['id']);
        });
        $total = count($rows);
        $pageSize = 6;
        $pages = max(1, (int) ceil($total / $pageSize));
        $page = is_string($query['page'] ?? null) && preg_match('/^[1-9][0-9]{0,5}$/D', $query['page'])
            ? min((int) $query['page'], $pages) : 1;
        $rows = array_slice($rows, ($page - 1) * $pageSize, $pageSize);

        return compact('count', 'buckets', 'average', 'positive', 'rating', 'sort', 'rows', 'total', 'pageSize', 'pages', 'page');
    }
}
