<?php

declare(strict_types=1);

// Illustrative subscription-credit activity, in kobo. This is not account data.
return [
    ['date' => '2026-11-03', 'reference' => 'HLD-1103', 'description' => 'Subscription credit reservation', 'type' => 'Subscription', 'status' => 'Reserved', 'amount_minor' => -25000],
    ['date' => '2026-10-28', 'reference' => 'RWD-1028', 'description' => 'Referral reward', 'type' => 'Reward', 'status' => 'Pending', 'amount_minor' => 50000],
    ['date' => '2026-10-20', 'reference' => 'RWD-1020', 'description' => 'Referral reward', 'type' => 'Reward', 'status' => 'Completed', 'amount_minor' => 100000],
    ['date' => '2026-10-15', 'reference' => 'SUB-1015', 'description' => 'Standard plan credit', 'type' => 'Subscription', 'status' => 'Completed', 'amount_minor' => -50000],
    ['date' => '2026-10-02', 'reference' => 'RWD-1002', 'description' => 'Referral reward', 'type' => 'Reward', 'status' => 'Completed', 'amount_minor' => 100000],
    ['date' => '2026-09-18', 'reference' => 'SUB-0918', 'description' => 'Standard plan credit', 'type' => 'Subscription', 'status' => 'Completed', 'amount_minor' => -50000],
    ['date' => '2026-09-05', 'reference' => 'RWD-0905', 'description' => 'Welcome credit', 'type' => 'Reward', 'status' => 'Completed', 'amount_minor' => 50000],
];
