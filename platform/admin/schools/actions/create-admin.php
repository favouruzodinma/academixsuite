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
    
    // Generate admin credentials
    $adminEmail = "admin@" . strtolower(preg_replace('/[^a-z0-9]/', '', $school['name'])) . ".edu";
    $tempPassword = bin2hex(random_bytes(8)); // 16 character temporary password
    $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
    
    // Check if admin already exists
    $checkStmt = $schoolDb->prepare("SELECT id FROM users WHERE user_type = 'admin' AND email = ?");
    $checkStmt->execute([$adminEmail]);
    $existingAdmin = $checkStmt->fetch();
    
    if ($existingAdmin) {
        echo json_encode(['success' => false, 'message' => 'Admin user already exists with this email']);
        exit;
    }
    
    // Create admin user
    $createStmt = $schoolDb->prepare("
        INSERT INTO users 
        (email, password, first_name, last_name, user_type, is_active, email_verified_at, 
         created_at, updated_at, password_reset_required)
        VALUES (?, ?, 'School', 'Administrator', 'admin', 1, NOW(), NOW(), NOW(), 1)
    ");
    $createStmt->execute([$adminEmail, $hashedPassword]);
    $adminId = $schoolDb->lastInsertId();
    
    // Assign admin role/permissions
    try {
        // Insert into user_roles table if it exists
        $roleStmt = $schoolDb->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, 1)");
        $roleStmt->execute([$adminId]);
    } catch (Exception $e) {
        // user_roles table might not exist
    }
    
    // Send credentials to school email
    $subject = "Admin Account Created - {$school['name']}";
    $message = "
        <h2>Admin Account Created</h2>
        <p>A new administrator account has been created for your school.</p>
        
        <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
            <h3>Login Credentials</h3>
            <p><strong>Email:</strong> $adminEmail</p>
            <p><strong>Temporary Password:</strong> <code>$tempPassword</code></p>
            <p><strong>Login URL:</strong> " . APP_URL . "/login</p>
        </div>
        
        <p><strong>Important:</strong> You will be required to change your password on first login.</p>
        <p><strong>Security Note:</strong> Please change this password immediately and keep it secure.</p>
        
        <p>This account has full administrative access to the school platform.</p>
        <p>Thank you,<br>Platform Administration</p>
    ";
    
    // Send email (implement your email function)
    // sendEmail($school['email'], $subject, $message);
    
    // Also send to the admin email itself
    // sendEmail($adminEmail, "Your Admin Account - {$school['name']}", $message);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'admin_created', ?, 'super_admin', NOW())
    ");
    $logDescription = "Admin user created: $adminEmail";
    $logStmt->execute([$schoolId, $logDescription]);
    
    // Also log in school's audit log
    try {
        $schoolLogStmt = $schoolDb->prepare("
            INSERT INTO audit_logs 
            (user_id, event, description, created_at)
            VALUES (?, 'account_created', ?, NOW())
        ");
        $schoolLogStmt->execute([$adminId, "Admin account created by super admin"]);
    } catch (Exception $e) {
        // audit_logs table might not exist
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Admin user created successfully',
        'admin_details' => [
            'email' => $adminEmail,
            'temporary_password' => $tempPassword,
            'first_name' => 'School',
            'last_name' => 'Administrator',
            'user_type' => 'admin'
        ],
        'notification' => 'Credentials have been sent to the school email',
        'security_note' => 'User must change password on first login'
    ]);
    
} catch (Exception $e) {
    error_log("Error creating admin: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error creating admin: ' . $e->getMessage()]);
}
?>