<?php

declare(strict_types=1);

namespace App\Domain;

final class MarketCatalog
{
    /** @return array<string, array{label: string, category: string, side: string, threshold: ?string, complement: string}> */
    public static function all(): array
    {
        return [
            'over_15' => ['label' => 'Over 1.5', 'category' => 'total_goals', 'side' => 'over', 'threshold' => '1.5', 'complement' => 'under_15'],
            'under_15' => ['label' => 'Under 1.5', 'category' => 'total_goals', 'side' => 'under', 'threshold' => '1.5', 'complement' => 'over_15'],
            'over_25' => ['label' => 'Over 2.5', 'category' => 'total_goals', 'side' => 'over', 'threshold' => '2.5', 'complement' => 'under_25'],
            'under_25' => ['label' => 'Under 2.5', 'category' => 'total_goals', 'side' => 'under', 'threshold' => '2.5', 'complement' => 'over_25'],
            'over_35' => ['label' => 'Over 3.5', 'category' => 'total_goals', 'side' => 'over', 'threshold' => '3.5', 'complement' => 'under_35'],
            'under_35' => ['label' => 'Under 3.5', 'category' => 'total_goals', 'side' => 'under', 'threshold' => '3.5', 'complement' => 'over_35'],
            'btts_yes' => ['label' => 'BTTS Yes', 'category' => 'btts', 'side' => 'yes', 'threshold' => null, 'complement' => 'btts_no'],
            'btts_no' => ['label' => 'BTTS No', 'category' => 'btts', 'side' => 'no', 'threshold' => null, 'complement' => 'btts_yes'],
        ];
    }
}
