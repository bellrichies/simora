<?php

declare(strict_types=1);

// Fictional, privacy-safe preview records. Reward amounts are in kobo.
return [
    'code' => 'SIM-DEMO26',
    'link' => 'https://simora.example/join?ref=SIM-DEMO26',
    'as_of' => '2026-11-04',
    'referrals' => [
        ['id' => 'REF-1008', 'name' => 'Tomi A.', 'joined_at' => '2026-11-02', 'plan' => null, 'status' => 'Registered', 'reward_minor' => 0, 'reward_at' => null],
        ['id' => 'REF-1007', 'name' => 'Ibrahim S.', 'joined_at' => '2026-10-30', 'plan' => null, 'status' => 'Registered', 'reward_minor' => 0, 'reward_at' => null],
        ['id' => 'REF-1006', 'name' => 'Chika O.', 'joined_at' => '2026-10-24', 'plan' => 'Standard', 'status' => 'Pending', 'reward_minor' => 50000, 'reward_at' => '2026-10-28'],
        ['id' => 'REF-1005', 'name' => 'Dami K.', 'joined_at' => '2026-10-18', 'plan' => null, 'status' => 'Registered', 'reward_minor' => 0, 'reward_at' => null],
        ['id' => 'REF-1004', 'name' => 'Zainab M.', 'joined_at' => '2026-10-08', 'plan' => 'Pro', 'status' => 'Credited', 'reward_minor' => 100000, 'reward_at' => '2026-10-20'],
        ['id' => 'REF-1003', 'name' => 'Femi B.', 'joined_at' => '2026-09-26', 'plan' => 'Standard', 'status' => 'Ineligible', 'reward_minor' => 0, 'reward_at' => null],
        ['id' => 'REF-1002', 'name' => 'Ada E.', 'joined_at' => '2026-09-20', 'plan' => 'Pro', 'status' => 'Credited', 'reward_minor' => 100000, 'reward_at' => '2026-10-02'],
        ['id' => 'REF-1001', 'name' => 'Seyi T.', 'joined_at' => '2026-09-12', 'plan' => null, 'status' => 'Registered', 'reward_minor' => 0, 'reward_at' => null],
    ],
];
