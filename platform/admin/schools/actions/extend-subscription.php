<?php
session_start();
require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if it's a JSON POST request
if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid content type. Expected JSON']);
    exit;
}

// Get JSON data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate CSRF token using your existing function
if (!isset($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF token is required']);
    exit;
}

// Use your existing CSRF validation function
if (!function_exists('validateCSRFToken')) {
    // Define the function if not exists (from your autoload.php)
    function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        if ($_SESSION['csrf_tokens'][$token] < time()) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
}

if (!validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}
$schoolId = $data['school_id'] ?? 0;
$days = intval($data['days'] ?? 30);
$reason = $data['reason'] ?? '';

if ($schoolId <= 0 || $days <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get current subscription
    $stmt = $db->prepare("
        SELECT s.*, sub.current_period_end 
        FROM schools s
        LEFT JOIN subscriptions sub ON s.id = sub.school_id
        WHERE s.id = ?
    ");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Calculate new end date
    $currentEnd = $school['current_period_end'] ? strtotime($school['current_period_end']) : time();
    $newEndDate = date('Y-m-d H:i:s', strtotime("+$days days", $currentEnd));
    
    // Update subscription
    $updateStmt = $db->prepare("
        UPDATE subscriptions 
        SET current_period_end = ?, 
            updated_at = NOW()
        WHERE school_id = ?
    ");
    $updateStmt->execute([$newEndDate, $schoolId]);
    
    // If no subscription exists, create one
    if ($updateStmt->rowCount() === 0) {
        $createStmt = $db->prepare("
            INSERT INTO subscriptions 
            (school_id, plan_id, status, current_period_start, current_period_end, billing_cycle, created_at, updated_at)
            SELECT ?, p.id, 'active', NOW(), ?, 'manual', NOW(), NOW()
            FROM plans p 
            WHERE p.is_default = 1
            LIMIT 1
        ");
        $createStmt->execute([$schoolId, $newEndDate]);
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'subscription_extended', ?, 'super_admin', NOW())
    ");
    $logDescription = "Subscription extended by $days days. New end date: $newEndDate";
    if ($reason) {
        $logDescription .= ". Reason: $reason";
    }
    $logStmt->execute([$schoolId, $logDescription]);
    
    // Generate invoice for extension
    $invoiceStmt = $db->prepare("
        INSERT INTO invoices 
        (school_id, invoice_number, amount, status, due_date, start_date, end_date, created_at)
        SELECT ?, 
               CONCAT('INV-', DATE_FORMAT(NOW(), '%Y%m%d-'), LPAD(FLOOR(RAND() * 10000), 4, '0')),
               p.price_monthly * (? / 30),
               'pending',
               DATE_ADD(NOW(), INTERVAL 7 DAY),
               NOW(),
               ?,
               NOW()
        FROM subscriptions sub
        LEFT JOIN plans p ON sub.plan_id = p.id
        WHERE sub.school_id = ?
    ");
    $invoiceStmt->execute([$schoolId, $days, $newEndDate, $schoolId]);
    
    echo json_encode([
        'success' => true, 
        'message' => "Subscription extended by $days days. New end date: " . date('F j, Y', strtotime($newEndDate))
    ]);
    
} catch (Exception $e) {
    error_log("Error extending subscription: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error extending subscription: ' . $e->getMessage()]);
}
?>