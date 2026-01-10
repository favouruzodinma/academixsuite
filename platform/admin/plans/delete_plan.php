<?php
// delete_plan.php
require_once __DIR__ . '/../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$planId = $input['plan_id'] ?? null;

if (!$planId) {
    echo json_encode(['success' => false, 'message' => 'Plan ID is required']);
    exit;
}

// Get database connection
$db = Database::getPlatformConnection();

try {
    // Check if any schools are using this plan
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM schools WHERE plan_id = ? AND status IN ('active', 'trial')");
    $stmt->execute([$planId]);
    $result = $stmt->fetch();
    
    if ($result['count'] > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete plan. There are schools using this plan.'
        ]);
        exit;
    }
    
    // Get plan details for audit log
    $stmt = $db->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    
    if (!$plan) {
        echo json_encode([
            'success' => false,
            'message' => 'Plan not found'
        ]);
        exit;
    }
    
    // Delete the plan
    $stmt = $db->prepare("DELETE FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    
    // Log the action
    logAudit(
        $_SESSION['super_admin']['id'],
        'plan_deleted',
        'plans',
        $planId,
        [
            'name' => $plan['name'],
            'slug' => $plan['slug'],
            'price_monthly' => $plan['price_monthly']
        ]
    );
    
    echo json_encode([
        'success' => true,
        'message' => 'Plan deleted successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error deleting plan: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete plan. Please try again.'
    ]);
}

/**
 * Log audit trail
 */
function logAudit($userId, $event, $auditableType, $auditableId, $data = []) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            INSERT INTO audit_logs (
                user_id, user_type, event, auditable_type, auditable_id,
                new_values, url, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            'super_admin',
            $event,
            $auditableType,
            $auditableId,
            json_encode($data),
            $_SERVER['REQUEST_URI'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        error_log("Error logging audit: " . $e->getMessage());
    }
}