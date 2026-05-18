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
    
    $userColumns = getTableColumns($schoolDb, 'users');
    $adminData = [
        'school_id' => $schoolId,
        'name' => 'School Administrator',
        'first_name' => 'School',
        'last_name' => 'Administrator',
        'email' => $adminEmail,
        'username' => $adminEmail,
        'password' => $hashedPassword,
        'user_type' => 'admin',
        'is_active' => 1,
        'email_verified_at' => date('Y-m-d H:i:s'),
        'password_reset_required' => 1,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    $adminData = array_intersect_key($adminData, array_flip($userColumns));

    if (!isset($adminData['email'], $adminData['password'], $adminData['user_type'])) {
        echo json_encode(['success' => false, 'message' => 'Users table is missing required admin account columns']);
        exit;
    }

    $columns = '`' . implode('`, `', array_keys($adminData)) . '`';
    $placeholders = ':' . implode(', :', array_keys($adminData));
    $createStmt = $schoolDb->prepare("INSERT INTO users ($columns) VALUES ($placeholders)");
    $createStmt->execute($adminData);
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
    
    $emailSent = false;
    $emailError = null;
    try {
        $emailService = new EmailService();
        if (!empty($school['email']) && filter_var($school['email'], FILTER_VALIDATE_EMAIL)) {
            $result = $emailService->sendEmail($school['email'], $subject, $message);
            $emailSent = (bool)($result['success'] ?? false);
            $emailError = $result['error'] ?? null;
        }
    } catch (Exception $e) {
        $emailError = $e->getMessage();
    }
    
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
            'name' => 'School Administrator',
            'user_type' => 'admin'
        ],
        'notification' => $emailSent ? 'Credentials have been sent to the school email' : 'Admin created; email notification was not sent',
        'email_error' => $emailError,
        'security_note' => 'User must change password on first login'
    ]);
    
} catch (Exception $e) {
    error_log("Error creating admin: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error creating admin: ' . $e->getMessage()]);
}

function getTableColumns(PDO $db, string $table): array {
    $stmt = $db->query("SHOW COLUMNS FROM `$table`");
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
}
?>
