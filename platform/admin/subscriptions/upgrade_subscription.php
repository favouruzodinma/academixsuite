<?php
require_once __DIR__ . '/actions-helper.php';
requireSubscriptionAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscriptionJson(['success' => false, 'message' => 'Method not allowed'], 405);
}

$schoolId = (int)($_POST['school_id'] ?? 0);
$newPlanId = (int)($_POST['new_plan_id'] ?? 0);
if ($schoolId <= 0 || $newPlanId <= 0) {
    subscriptionJson(['success' => false, 'message' => 'Invalid upgrade request'], 400);
}

try {
    $db = Database::getPlatformConnection();
    $planStmt = $db->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1");
    $planStmt->execute([$newPlanId]);
    $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        subscriptionJson(['success' => false, 'message' => 'Plan not found'], 404);
    }

    $db->prepare("UPDATE schools SET plan_id = ?, updated_at = NOW() WHERE id = ?")->execute([$newPlanId, $schoolId]);
    $db->prepare("
        UPDATE subscriptions
        SET plan_id = ?, amount = ?, billing_cycle = COALESCE(billing_cycle, 'monthly'), status = 'active', updated_at = NOW()
        WHERE school_id = ?
        ORDER BY id DESC
        LIMIT 1
    ")->execute([$newPlanId, $plan['price_monthly'], $schoolId]);
    subscriptionAudit($db, $schoolId, 'subscription_upgraded', 'Subscription upgraded to ' . $plan['name'] . '.');
    subscriptionJson(['success' => true, 'message' => 'Subscription upgraded successfully']);
} catch (Exception $e) {
    error_log("upgrade_subscription failed: " . $e->getMessage());
    subscriptionJson(['success' => false, 'message' => 'Unable to upgrade subscription'], 500);
}
