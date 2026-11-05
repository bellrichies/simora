<?php

declare(strict_types=1);

return [
    'name' => 'Simora', 'subtitle' => 'Virtual Football Analytics',
    'timezone' => 'Africa/Lagos', 'currency' => 'NGN', 'currency_symbol' => '₦',
    'history_page_size' => 6,
    'stake' => ['default_minor' => 50000, 'min_minor' => 100, 'max_minor' => 100000000,
        'increments_minor' => [10000, 50000, 100000]],
    'default_market' => 'under_25',
    'markets' => [
        ['id' => 'over_15', 'label' => 'Over 1.5'],
        ['id' => 'over_25', 'label' => 'Over 2.5'],
        ['id' => 'under_25', 'label' => 'Under 2.5'],
        ['id' => 'under_35', 'label' => 'Under 3.5'],
        ['id' => 'btts_yes', 'label' => 'BTTS Yes'],
        ['id' => 'btts_no', 'label' => 'BTTS No'],
    ],
];
