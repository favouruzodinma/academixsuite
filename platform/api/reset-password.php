<?php
// platform/api/reset-password.php
require_once __DIR__ . '/../../includes/autoload.php';
require_once __DIR__ . '/../../includes/ErrorHandler.php';

ErrorHandler::register();

// Set JSON header
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$userType = $input['user_type'] ?? 'super_admin';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Email is required']);
    exit;
}

try {
    $auth = new Auth();
    
    if ($userType === 'super_admin') {
        // Check if super admin exists
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("SELECT id, name FROM platform_users WHERE email = ? AND role = 'super_admin'");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Don't reveal if user exists (security)
            echo json_encode(['success' => true, 'message' => 'If the email exists, reset instructions will be sent']);
            exit;
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        
        // Store token in database
        $updateStmt = $db->prepare("UPDATE platform_users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
        $updateStmt->execute([$token, $expiry, $user['id']]);
        
        // Create reset link
        $resetUrl = APP_URL . "/platform/reset-password.php?token=" . urlencode($token);
        
        // Send email (in production)
        $subject = "Password Reset Request - " . APP_NAME . " Super Admin";
        $body = "
            <h2>Password Reset Request</h2>
            <p>Hello " . htmlspecialchars($user['name']) . ",</p>
            <p>You have requested to reset your super admin password. Click the link below to proceed:</p>
            <p><a href='$resetUrl' style='display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>Reset Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you didn't request this, please ignore this email and ensure your account is secure.</p>
            <hr>
            <p style='font-size: 12px; color: #666;'>This is an automated message from " . APP_NAME . " Super Admin Portal.</p>
        ";
        
        // Log the request (in development, just log it)
        error_log("Password reset requested for super admin: $email");
        error_log("Reset token: $token");
        error_log("Reset URL: $resetUrl");
        
        // In production, uncomment this:
        // if (!IS_LOCAL) {
        //     sendEmail($email, $subject, $body);
        // }
        
        echo json_encode([
            'success' => true,
            'message' => 'Password reset instructions sent to your email',
            'debug_token' => IS_LOCAL ? $token : null // Only in development
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid user type']);
    }
} catch (Exception $e) {
    error_log("Password reset error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process reset request']);
}
?>