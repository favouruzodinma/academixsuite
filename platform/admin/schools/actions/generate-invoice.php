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

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school subscription details
    $stmt = $db->prepare("
        SELECT s.*, sub.*, p.price_monthly, p.name as plan_name
        FROM schools s
        LEFT JOIN subscriptions sub ON s.id = sub.school_id
        LEFT JOIN plans p ON sub.plan_id = p.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    if (!$school['plan_id']) {
        echo json_encode(['success' => false, 'message' => 'School has no active plan']);
        exit;
    }
    
    // Calculate billing period (default: next 30 days from today)
    $startDate = date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+30 days'));
    $dueDate = date('Y-m-d', strtotime('+7 days'));
    
    // Generate invoice number
    $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
    
    // Calculate amount based on billing cycle
    $amount = $school['price_monthly'];
    if ($school['billing_cycle'] === 'yearly') {
        $amount = $school['price_monthly'] * 12;
        $endDate = date('Y-m-d', strtotime('+1 year'));
    } elseif ($school['billing_cycle'] === 'quarterly') {
        $amount = $school['price_monthly'] * 3;
        $endDate = date('Y-m-d', strtotime('+3 months'));
    }
    
    // Create invoice
    $invoiceStmt = $db->prepare("
        INSERT INTO invoices 
        (school_id, invoice_number, amount, status, due_date, start_date, end_date, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $invoiceStmt->execute([
        $schoolId,
        $invoiceNumber,
        $amount,
        $status = 'draft',
        $dueDate,
        $startDate,
        $endDate
    ]);
    
    $invoiceId = $db->lastInsertId();
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'invoice_generated', ?, 'super_admin', NOW())
    ");
    $logDescription = "Invoice #$invoiceNumber generated. Amount: $amount, Due: $dueDate";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => "Invoice #$invoiceNumber generated successfully",
        'invoice_number' => $invoiceNumber,
        'amount' => $amount,
        'due_date' => $dueDate
    ]);
    
} catch (Exception $e) {
    error_log("Error generating invoice: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error generating invoice: ' . $e->getMessage()]);
}
?>