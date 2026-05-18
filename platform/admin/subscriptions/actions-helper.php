<?php

require_once __DIR__ . '/../../../includes/autoload.php';

function subscriptionJson(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function requireSubscriptionAdmin(): void
{
    $auth = new Auth();
    if (!$auth->isLoggedIn('super_admin')) {
        subscriptionJson(['success' => false, 'message' => 'Unauthorized'], 401);
    }
}

function subscriptionAudit(PDO $db, int $schoolId, string $event, string $description): void
{
    try {
        $stmt = $db->prepare("
            INSERT INTO platform_audit_logs (school_id, event, description, user_type, created_at)
            VALUES (?, ?, ?, 'super_admin', NOW())
        ");
        $stmt->execute([$schoolId, $event, $description]);
    } catch (Exception $e) {
        error_log("Subscription audit failed: " . $e->getMessage());
    }
}
