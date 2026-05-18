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
        
        return true;
    }
}

if (!validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}
$schoolId = $data['school_id'] ?? 0;

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name, email, database_name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school || empty($school['database_name'])) {
        echo json_encode(['success' => false, 'message' => 'School not found or database not created']);
        exit;
    }
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($school['database_name']);
    $userColumns = getTableColumns($schoolDb, 'users');
    
    // Generate new temporary password
    $temporaryPassword = bin2hex(random_bytes(8)); // 16 character password
    
    // Hash the password
    $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    
    $setParts = ['password = ?'];
    if (in_array('password_reset_required', $userColumns, true)) {
        $setParts[] = 'password_reset_required = 1';
    }
    if (in_array('updated_at', $userColumns, true)) {
        $setParts[] = 'updated_at = NOW()';
    }
    $where = in_array('is_active', $userColumns, true) ? 'WHERE is_active = 1' : '';
    $updateStmt = $schoolDb->prepare("UPDATE users SET " . implode(', ', $setParts) . " $where");
    $updateStmt->execute([$hashedPassword]);
    $usersAffected = $updateStmt->rowCount();
    
    // Get admin emails for notification
    $nameSelect = in_array('name', $userColumns, true)
        ? 'name'
        : "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name";
    $adminWhere = ["user_type = 'admin'"];
    if (in_array('is_active', $userColumns, true)) {
        $adminWhere[] = 'is_active = 1';
    }
    $adminStmt = $schoolDb->prepare("SELECT email, $nameSelect FROM users WHERE " . implode(' AND ', $adminWhere));
    $adminStmt->execute();
    $admins = $adminStmt->fetchAll();
    
    // Send notification to admins
    $notificationsSent = 0;
    $emailService = new EmailService();
    foreach ($admins as $admin) {
        if (empty($admin['email']) || !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        // Prepare notification email
        $to = $admin['email'];
        $subject = "Password Reset - {$school['name']}";
        $adminName = htmlspecialchars($admin['name'] ?: 'School Administrator', ENT_QUOTES, 'UTF-8');
        $schoolName = htmlspecialchars($school['name'], ENT_QUOTES, 'UTF-8');
        
        $message = "
            <h2>Password Reset Notification</h2>
            <p>Dear {$adminName},</p>
            
            <p>All user passwords for <strong>{$schoolName}</strong> have been reset by the platform administrator.</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3>Important Information</h3>
                <p><strong>Temporary Password:</strong> <code>$temporaryPassword</code></p>
                <p><strong>Affected Users:</strong> $usersAffected active users</p>
                <p><strong>Action Required:</strong> All users must change their password on next login</p>
            </div>
            
            <p><strong>Security Notice:</strong> Please change your password immediately after logging in.</p>
            <p>Thank you,<br>Platform Administration</p>
        ";
        
        $result = $emailService->sendEmail($to, $subject, $message);
        if (!empty($result['success'])) {
            $notificationsSent++;
        }
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

function getTableColumns(PDO $db, string $table): array {
    $stmt = $db->query("SHOW COLUMNS FROM `$table`");
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
?>
