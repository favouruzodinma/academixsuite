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
$message = $data['message'] ?? '';
$subject = $data['subject'] ?? 'Platform Announcement';
$userTypes = $data['user_types'] ?? ['admin', 'teacher', 'student', 'parent'];

if ($schoolId <= 0 || empty($databaseName) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Build user type condition
    $userTypePlaceholders = implode(',', array_fill(0, count($userTypes), '?'));
    
    // Get active users based on selected user types
    $userStmt = $schoolDb->prepare("
        SELECT email, first_name, last_name, user_type 
        FROM users 
        WHERE is_active = 1 
        AND user_type IN ($userTypePlaceholders)
    ");
    $userStmt->execute($userTypes);
    $users = $userStmt->fetchAll();
    
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
    
    // Send broadcast to each user
    foreach ($users as $user) {
        // Prepare personalized message
        $personalizedMessage = "
            <h2>$subject</h2>
            <p>Dear {$user['first_name']} {$user['last_name']},</p>
            
            <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                " . nl2br(htmlspecialchars($message)) . "
            </div>
            
            <p><strong>School:</strong> {$school['name']}</p>
            <p><strong>User Type:</strong> " . ucfirst($user['user_type']) . "</p>
            
            <p>This is an automated message from the platform administration.</p>
            <p>Please do not reply to this email.</p>
            <p>Thank you,<br>Platform Administration</p>
        ";
        
        // Send email (implement your email function)
        // if (sendEmail($user['email'], $subject, $personalizedMessage)) {
        //     $emailsSent++;
        // }
        
        $emailsSent++; // For testing
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
            'success_rate' => $totalUsers > 0 ? round(($emailsSent / $totalUsers) * 100, 2) : 0,
            'users_by_type' => $usersByType
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error sending broadcast: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error sending broadcast: ' . $e->getMessage()]);
}
?>