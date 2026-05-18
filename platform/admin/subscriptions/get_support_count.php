<?php
require_once __DIR__ . '/actions-helper.php';
requireSubscriptionAdmin();

try {
    $db = Database::getPlatformConnection();
    $exists = $db->query("SHOW TABLES LIKE 'support_tickets'")->fetchColumn();
    $count = 0;
    if ($exists) {
        $count = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open', 'pending')")->fetchColumn();
    }
    subscriptionJson(['success' => true, 'count' => $count]);
} catch (Exception $e) {
    subscriptionJson(['success' => true, 'count' => 0]);
}
