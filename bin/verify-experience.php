<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

try {
    $db = App\Infrastructure\DatabaseConnection::connect();
    $config = require dirname(__DIR__) . '/config/experience.php';
    $counts = [];
    $tables = ['referral_codes', 'referral_attributions', 'referral_rewards', 'reviews', 'review_moderation',
        'support_tickets', 'support_messages', 'faq_articles', 'chat_rooms', 'chat_memberships', 'chat_messages'];
    foreach ($tables as $table) {
        $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=?');
        $exists->execute([$table]);
        $counts[$table] = (int) $exists->fetchColumn() === 0 ? null
            : (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    $result = ['verified_at' => gmdate('Y-m-d\TH:i:s\Z'), 'configured_features' => $config['features'],
        'counts' => $counts, 'referral_attribution_enabled' => false, 'referral_rewards_enabled' => false,
        'payouts_enabled' => false, 'live_widget_integrated' => false];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Experience storage verification unavailable.\n");
    exit(1);
}
