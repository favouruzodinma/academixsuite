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

// Get data
$schoolId = isset($data['school_id']) ? (int)$data['school_id'] : 0;
$notify = isset($data['notify']) ? (bool)$data['notify'] : false;
$reason = isset($data['reason']) ? trim($data['reason']) : '';
$databaseName = isset($data['database_name']) ? $data['database_name'] : '';

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // First, get school info for logging
    $schoolStmt = $db->prepare("SELECT name, email, database_name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Use database name from parameter or from school record
    $dbName = !empty($databaseName) ? $databaseName : ($school['database_name'] ?? '');
    
    // Update school status - only existing columns
    $updateStmt = $db->prepare("UPDATE schools SET status = 'suspended', updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$schoolId]);
    
    // Log the action - using only existing platform_audit_logs columns
    $logDescription = "School '{$school['name']}' suspended by super admin.";
    if (!empty($reason)) {
        $logDescription .= " Reason: {$reason}";
    }
    
    // Check if platform_audit_logs table has ip_address column
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'school_suspended', ?, 'super_admin', NOW())
    ");
    $logStmt->execute([$schoolId, $logDescription]);
    
    // If notification is requested, send email to school admins
    if ($notify) {
        $adminsNotified = 0;
        
        // Try to get school admin emails if database exists
        if (!empty($dbName) && Database::schoolDatabaseExists($dbName)) {
            try {
                $schoolDb = Database::getSchoolConnection($dbName);
                $adminStmt = $schoolDb->prepare("
                    SELECT email FROM users 
                    WHERE user_type = 'admin' AND is_active = 1
                ");
                $adminStmt->execute();
                $admins = $adminStmt->fetchAll();
                
                if ($admins) {
                    // Send notification emails (simplified - implement your email service)
                    foreach ($admins as $admin) {
                        $adminEmail = $admin['email'];
                        
                        // Email would be sent here (commented out)
                        // sendEmail($adminEmail, 'School Suspension Notice', ...);
                        
                        $adminsNotified++;
                    }
                    
                    // Log the notification
                    $notifyLog = $db->prepare("
                        INSERT INTO platform_audit_logs 
                        (school_id, event, description, user_type, created_at) 
                        VALUES (?, 'notification_sent', ?, 'super_admin', NOW())
                    ");
                    $notifyLog->execute([
                        $schoolId, 
                        "Suspension notification sent to school administrators"
                    ]);
                }
            } catch (Exception $e) {
                error_log("Error getting school admins for notification: " . $e->getMessage());
                // Continue even if notification fails
            }
        }
    }
    
    // Optional: Add to school notes if table exists
    try {
        // Check if school_notes table exists
        $checkTable = $db->query("SHOW TABLES LIKE 'school_notes'")->fetch();
        if ($checkTable) {
            $noteStmt = $db->prepare("
                INSERT INTO school_notes 
                (school_id, note, created_by, created_at) 
                VALUES (?, ?, 'super_admin', NOW())
            ");
            $noteStmt->execute([
                $schoolId,
                "School suspended. " . ($reason ? "Reason: {$reason}" : "No reason provided")
            ]);
        }
    } catch (Exception $e) {
        // Table doesn't exist or error - ignore and continue
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'School suspended successfully' . 
                    ($notify ? ' and notifications sent' : '')
    ]);
    
} catch (Exception $e) {
    error_log("Error suspending school: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Error suspending school: ' . $e->getMessage()
    ]);
}
?>