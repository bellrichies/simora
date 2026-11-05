<?php

declare(strict_types=1);

return [
    'profile' => ['email' => 'demo@simora.example', 'joined_at' => '2026-09-05', 'account_id' => 'SIM-DEMO-2026'],
    'sections' => [
        'profile' => ['icon' => 'person', 'label' => 'Profile', 'description' => 'Your sample account and local display name.'],
        'security' => ['icon' => 'shield-lock', 'label' => 'Security', 'description' => 'Understand the sign-in features available in this preview.'],
        'preferences' => ['icon' => 'sliders', 'label' => 'Preferences', 'description' => 'Choose a preview timezone and your workspace shortcut.'],
        'notifications' => ['icon' => 'bell', 'label' => 'Notifications', 'description' => 'Try notification choices without sending any messages.'],
        'privacy' => ['icon' => 'lock', 'label' => 'Privacy & account', 'description' => 'Manage the settings stored in this browser.'],
    ],
    'timezones' => ['Africa/Lagos' => 'Lagos (WAT)', 'UTC' => 'Coordinated Universal Time', 'Europe/London' => 'London'],
    'notifications' => [
        ['id' => 'product', 'label' => 'Product updates', 'description' => 'New features and changes to the analytics workspace.', 'enabled' => true],
        ['id' => 'billing', 'label' => 'Billing reminders', 'description' => 'Example plan renewal and invoice reminders.', 'enabled' => true],
        ['id' => 'rewards', 'label' => 'Referral & wallet activity', 'description' => 'Example credit updates and referral milestones.', 'enabled' => true],
        ['id' => 'marketing', 'label' => 'Offers & announcements', 'description' => 'Optional promotional updates.', 'enabled' => false],
    ],
    'security' => [
        ['label' => 'Password & sign-in', 'description' => 'A live identity service is needed to manage passwords.'],
        ['label' => 'Two-step verification', 'description' => 'Enrollment and recovery methods are not connected.'],
        ['label' => 'Active sessions', 'description' => 'This fixture account has no authenticated sign-in sessions.'],
    ],
    'sample_timestamp' => '2026-11-04T10:30:00Z',
];
