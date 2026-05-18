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
$schoolId = (int) ($data['school_id'] ?? 0);
$databaseName = $data['database_name'] ?? '';
$message = $data['message'] ?? '';
$subject = $data['subject'] ?? 'Platform Announcement';
$userTypes = $data['user_types'] ?? ['admin', 'teacher', 'student', 'parent'];

if ($schoolId <= 0 || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

$allowedUserTypes = ['admin', 'teacher', 'student', 'parent', 'accountant', 'librarian'];
$userTypes = array_values(array_intersect((array) $userTypes, $allowedUserTypes));
if (!$userTypes) {
    echo json_encode(['success' => false, 'message' => 'Select at least one valid user type']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name, database_name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school || empty($school['database_name'])) {
        echo json_encode(['success' => false, 'message' => 'School not found or database missing']);
        exit;
    }

    $databaseName = $school['database_name'];
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    $columns = $schoolDb->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    
    // Build user type condition
    $userTypePlaceholders = implode(',', array_fill(0, count($userTypes), '?'));
    
    // Get active users based on selected user types
    $userStmt = $schoolDb->prepare("
        SELECT id, email, name, user_type 
        FROM users 
        WHERE is_active = 1 
        AND user_type IN ($userTypePlaceholders)
        AND email IS NOT NULL
        AND email != ''
    ");
    $userStmt->execute($userTypes);
    $users = array_values(array_filter($userStmt->fetchAll(), function ($user) {
        return filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL);
    }));
    
    $totalUsers = count($users);
    $emailsSent = 0;
    $usersByType = [];
    
    // Group users by type for statistics
    foreach ($users as $user) {
        if (!isset($usersByType[$user['user_type']])) {
            $usersByType[$user['user_type']] = 0;
        }
        $usersByType[$user['user_type']]++;
    }
    
    $emailService = new EmailService();
    $failed = 0;
    $errors = [];

    // Send broadcast to each user
    foreach ($users as $user) {
        $recipientName = trim($user['name'] ?? '') ?: ucfirst($user['user_type']);
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8');
        $safeSchool = htmlspecialchars($school['name'], ENT_QUOTES, 'UTF-8');
        $safeUserType = htmlspecialchars(ucfirst($user['user_type']), ENT_QUOTES, 'UTF-8');

        // Prepare personalized message
        $personalizedMessage = "
            <h2>{$safeSubject}</h2>
            <p>Dear {$safeName},</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            
            <p><strong>School:</strong> {$safeSchool}</p>
            <p><strong>User Type:</strong> {$safeUserType}</p>
            
            <p>This is an automated message from the platform administration.</p>
            <p>Please do not reply to this email.</p>
            <p>Thank you,<br>Platform Administration</p>
        ";
        
        $result = $emailService->sendEmail($user['email'], $subject, $personalizedMessage);
        if (!empty($result['success'])) {
            $emailsSent++;
        } else {
            $failed++;
            if (count($errors) < 5) {
                $errors[] = $user['email'] . ': ' . ($result['error'] ?? 'send failed');
            }
        }
    }
    
    // Store broadcast in database for record
    $broadcastStmt = $db->prepare("
        INSERT INTO platform_broadcasts 
        (school_id, subject, message, user_types, total_recipients, emails_sent, sent_by, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, 'super_admin', NOW())
    ");
    $broadcastStmt->execute([
        $schoolId,
        $subject,
        $message,
        json_encode($userTypes),
        $totalUsers,
        $emailsSent
    ]);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'broadcast_sent', ?, 'super_admin', NOW())
    ");
    $logDescription = "Broadcast sent to $emailsSent/$totalUsers users. Subject: '$subject'. User types: " . implode(', ', $userTypes);
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Broadcast sent successfully',
        'statistics' => [
            'total_recipients' => $totalUsers,
            'emails_sent' => $emailsSent,
            'failed' => $failed,
            'success_rate' => $totalUsers > 0 ? round(($emailsSent / $totalUsers) * 100, 2) : 0,
            'users_by_type' => $usersByType,
            'sample_errors' => $errors
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error sending broadcast: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error sending broadcast: ' . $e->getMessage()]);
}
?>
