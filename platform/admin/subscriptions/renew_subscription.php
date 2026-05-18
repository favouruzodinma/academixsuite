<?php
require_once __DIR__ . '/actions-helper.php';
requireSubscriptionAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscriptionJson(['success' => false, 'message' => 'Method not allowed'], 405);
}

$schoolId = (int)($_POST['school_id'] ?? 0);
$subscriptionId = (int)($_POST['subscription_id'] ?? 0);
$billingCycle = in_array($_POST['billing_cycle'] ?? 'monthly', ['monthly', 'yearly'], true) ? $_POST['billing_cycle'] : 'monthly';
$years = max(1, min(3, (int)($_POST['period_years'] ?? 1)));

if ($schoolId <= 0) {
    subscriptionJson(['success' => false, 'message' => 'Invalid school'], 400);
}

try {
    $db = Database::getPlatformConnection();
    $stmt = $db->prepare("SELECT s.plan_id, p.price_monthly, p.price_yearly FROM schools s LEFT JOIN plans p ON p.id = s.plan_id WHERE s.id = ?");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$school) {
        subscriptionJson(['success' => false, 'message' => 'School not found'], 404);
    }

    $amount = $billingCycle === 'yearly'
        ? (float)($school['price_yearly'] ?: ((float)$school['price_monthly'] * 12 * 0.8)) * $years
        : (float)$school['price_monthly'];

    if ($subscriptionId > 0) {
        $stmt = $db->prepare("
            UPDATE subscriptions
            SET status = 'active', billing_cycle = ?, amount = ?, current_period_end = DATE_ADD(COALESCE(current_period_end, NOW()), INTERVAL ? YEAR), updated_at = NOW()
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$billingCycle, $amount, $years, $subscriptionId, $schoolId]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO subscriptions (school_id, plan_id, status, billing_cycle, amount, currency, current_period_start, current_period_end, created_at)
            VALUES (?, ?, 'active', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL ? YEAR), NOW())
        ");
        $stmt->execute([$schoolId, $school['plan_id'], $billingCycle, $amount, $years]);
    }

    $db->prepare("UPDATE schools SET status = 'active', subscription_ends_at = DATE_ADD(COALESCE(subscription_ends_at, NOW()), INTERVAL ? YEAR), updated_at = NOW() WHERE id = ?")->execute([$years, $schoolId]);
    subscriptionAudit($db, $schoolId, 'subscription_renewed', "Subscription renewed for {$years} year(s).");
    subscriptionJson(['success' => true, 'message' => 'Subscription renewed successfully']);
} catch (Exception $e) {
    error_log("renew_subscription failed: " . $e->getMessage());
    subscriptionJson(['success' => false, 'message' => 'Unable to renew subscription'], 500);
}
