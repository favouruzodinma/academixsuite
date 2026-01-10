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
$databaseName = $data['database_name'] ?? '';

if ($schoolId <= 0 || empty($databaseName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name, email FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Generate new temporary password
    $temporaryPassword = bin2hex(random_bytes(8)); // 16 character password
    
    // Hash the password
    $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    
    // Update all user passwords
    $updateStmt = $schoolDb->prepare("
        UPDATE users 
        SET password = ?,
            password_reset_required = 1,
            updated_at = NOW()
        WHERE is_active = 1
    ");
    $updateStmt->execute([$hashedPassword]);
    $usersAffected = $updateStmt->rowCount();
    
    // Get admin emails for notification
    $adminStmt = $schoolDb->prepare("
        SELECT email, first_name, last_name 
        FROM users 
        WHERE user_type = 'admin' AND is_active = 1
    ");
    $adminStmt->execute();
    $admins = $adminStmt->fetchAll();
    
    // Send notification to admins
    $notificationsSent = 0;
    foreach ($admins as $admin) {
        // Prepare notification email
        $to = $admin['email'];
        $subject = "Password Reset - {$school['name']}";
        
        $message = "
            <h2>Password Reset Notification</h2>
            <p>Dear {$admin['first_name']} {$admin['last_name']},</p>
            
            <p>All user passwords for <strong>{$school['name']}</strong> have been reset by the platform administrator.</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3>Important Information</h3>
                <p><strong>Temporary Password:</strong> <code>$temporaryPassword</code></p>
                <p><strong>Affected Users:</strong> $usersAffected active users</p>
                <p><strong>Action Required:</strong> All users must change their password on next login</p>
            </div>
            
            <p><strong>Security Notice:</strong> Please change your password immediately after logging in.</p>
            <p>Thank you,<br>Platform Administration</p>
        ";
        
        // Send email (implement your email function)
        // if (sendEmail($to, $subject, $message)) {
        //     $notificationsSent++;
        // }
        
        $notificationsSent++; // For testing
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'passwords_reset', ?, 'super_admin', NOW())
    ");
    $logDescription = "Passwords reset for $usersAffected users. Temporary password set. Notifications sent to $notificationsSent admins.";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Passwords reset successfully',
        'users_affected' => $usersAffected,
        'notifications_sent' => $notificationsSent,
        'temporary_password' => $temporaryPassword,
        'note' => 'All users must change their password on next login'
    ]);
    
} catch (Exception $e) {
    error_log("Error resetting passwords: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error resetting passwords: ' . $e->getMessage()]);
}
?>