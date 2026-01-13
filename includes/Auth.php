<?php

/**
 * Authentication and Authorization System
 * Handles user login, session management, and permissions
 */

class Auth
{
    private $db;
    private $loginAttempts = [];

    public function __construct()
    {
        $this->db = Database::getPlatformConnection();
        $this->startSession();
    }

    /**
     * Start or resume session
     */
    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('AcademixSuite_session');
            session_set_cookie_params([
                'lifetime' => SESSION_TIMEOUT,
                'path' => '/',
                'domain' => '',
                'secure' => !IS_LOCAL,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();

            // Regenerate session ID periodically for security
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
    }

    /**
     * Login super admin
     * @param string $email
     * @param string $password
     * @return array [success, message, data]
     */
    public function loginSuperAdmin($email, $password)
    {
        // Check login attempts
        if ($this->isLoginLocked($email, 'super_admin')) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please try again in ' .
                    round((LOGIN_LOCKOUT_TIME - (time() - $this->loginAttempts[$email]['last_attempt'])) / 60) . ' minutes.'
            ];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM platform_users 
                WHERE email = ? AND role = 'super_admin' AND is_active = 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->recordFailedAttempt($email, 'super_admin');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            if (!password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($email, 'super_admin');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            // Check if password needs rehash
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $updateStmt = $this->db->prepare("UPDATE platform_users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);
            }

            // Set session data
            $_SESSION['super_admin'] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'login_time' => time(),
                'last_activity' => time()
            ];

            // Clear failed attempts
            $this->clearFailedAttempts($email);

            // Update last login
            $this->updateLastLogin($user['id'], 'platform_users');

            // Log the login
            $this->logActivity($user['id'], 'super_admin', 'login', 'Super admin logged in');

            return [
                'success' => true,
                'message' => 'Login successful',
                'redirect' => '../platform/admin/dashboard.php'
            ];
        } catch (Exception $e) {
            error_log("Super admin login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }

    /**
     * Login school user (admin, teacher, student, parent)
     * @param string $email
     * @param string $password
     * @param string $schoolSlug
     * @return array [success, message, data]
     */
    public function loginSchoolUser($email, $password, $schoolSlug)
    {
        // Get school info
        $school = $this->getSchoolBySlug($schoolSlug);
        if (!$school) {
            return ['success' => false, 'message' => 'School not found'];
        }

        // Check if school is active
        if ($school['status'] !== 'active' && $school['status'] !== 'trial') {
            return ['success' => false, 'message' => 'School account is ' . $school['status']];
        }

        // Check login attempts for this school user
        $attemptKey = $school['id'] . '_' . $email;
        if ($this->isLoginLocked($attemptKey, 'school_user')) {
            return [
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.'
            ];
        }

        try {
            // Connect to school's database
            $schoolDb = Database::getSchoolConnection($school['database_name']);

            // Find user
            $stmt = $schoolDb->prepare("
                SELECT u.*, GROUP_CONCAT(r.permissions) as role_permissions
                FROM users u 
                LEFT JOIN user_roles ur ON u.id = ur.user_id 
                LEFT JOIN roles r ON ur.role_id = r.id 
                WHERE u.email = ? AND u.is_active = 1
                GROUP BY u.id
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->recordFailedAttempt($attemptKey, 'school_user');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            if (!password_verify($password, $user['password'])) {
                $this->recordFailedAttempt($attemptKey, 'school_user');
                return ['success' => false, 'message' => 'Invalid credentials'];
            }

            // Check if password needs rehash
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT)) {
                $newHash = password_hash($password, PASSWORD_BCRYPT);
                $updateStmt = $schoolDb->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updateStmt->execute([$newHash, $user['id']]);
            }

            // Parse permissions
            $permissions = [];
            if (!empty($user['role_permissions'])) {
                $rolePermissions = explode(',', $user['role_permissions']);
                foreach ($rolePermissions as $rolePerm) {
                    $decoded = json_decode($rolePerm, true);
                    if (is_array($decoded)) {
                        $permissions = array_merge($permissions, $decoded);
                    }
                }
            }

            // Set session data
            $_SESSION['school_user'] = [
                'id' => $user['id'],
                'school_id' => $school['id'],
                'school_slug' => $school['slug'],
                'school_name' => $school['name'],
                'school_db' => $school['database_name'],
                'name' => $user['name'],
                'email' => $user['email'],
                'user_type' => $user['user_type'],
                'permissions' => array_unique($permissions),
                'login_time' => time(),
                'last_activity' => time()
            ];

            // Clear failed attempts
            $this->clearFailedAttempts($attemptKey);

            // Update last login
            $this->updateLastLogin($user['id'], 'users', $schoolDb);

            // Log the login
            $this->logActivity(
                $user['id'],
                $user['user_type'],
                'login',
                'User logged in',
                $school['id'],
                $schoolDb
            );

            // Determine redirect URL based on user type
            $redirect = $this->getSchoolUserRedirect($user['user_type'], $school['slug']);

            return [
                'success' => true,
                'message' => 'Login successful',
                'user_type' => $user['user_type'],
                'redirect' => $redirect
            ];
        } catch (Exception $e) {
            error_log("School user login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }

    /**
     * Get redirect URL for school user
     * @param string $userType
     * @param string $schoolSlug
     * @return string
     */
    private function getSchoolUserRedirect($userType, $schoolSlug)
    {
        switch ($userType) {
            case ROLE_SCHOOL_ADMIN:
                return "/tenant/$schoolSlug/admin/dashboard.php";
            case ROLE_TEACHER:
                return "/tenant/$schoolSlug/teacher/dashboard.php";
            case ROLE_STUDENT:
                return "/tenant/$schoolSlug/student/dashboard.php";
            case ROLE_PARENT:
                return "/tenant/$schoolSlug/parent/dashboard.php";
            case ROLE_ACCOUNTANT:
                return "/tenant/$schoolSlug/accountant/dashboard.php";
            case ROLE_LIBRARIAN:
                return "/tenant/$schoolSlug/librarian/dashboard.php";
            default:
                return "/tenant/$schoolSlug/dashboard.php";
        }
    }

    /**
     * Check if user is logged in
     * @param string $type super_admin|school_user
     * @param string $userType Specific user type (admin, teacher, etc.)
     * @return bool
     */
    public function isLoggedIn($type = null, $userType = null)
    {
        if ($type === 'super_admin') {
            if (!isset($_SESSION['super_admin'])) {
                return false;
            }

            // Check session timeout
            if (time() - $_SESSION['super_admin']['last_activity'] > SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }

            // Update last activity
            $_SESSION['super_admin']['last_activity'] = time();
            return true;
        }

        if ($type === 'school_user' || $type === null) {
            if (!isset($_SESSION['school_user'])) {
                return false;
            }

            // Check session timeout
            if (time() - $_SESSION['school_user']['last_activity'] > SESSION_TIMEOUT) {
                $this->logout();
                return false;
            }

            // Check specific user type if requested
            if ($userType && $_SESSION['school_user']['user_type'] !== $userType) {
                return false;
            }

            // Update last activity
            $_SESSION['school_user']['last_activity'] = time();
            return true;
        }

        return false;
    }

    /**
     * Require login for specific user type
     * @param string $type
     * @param string $userType
     */
    public function requireLogin($type = null, $userType = null)
    {
        if (!$this->isLoggedIn($type, $userType)) {
            if ($type === 'super_admin') {
                header("Location: ../login.php");
            } else {
                $schoolSlug = $_SESSION['school_user']['school_slug'] ??
                    $_GET['school'] ?? '';
                header("Location: /login.php?school=" . urlencode($schoolSlug));
            }
            exit;
        }
    }

    /**
     * Check if user has permission
     * @param string $permission
     * @return bool
     */
    public function hasPermission($permission)
    {
        if (!isset($_SESSION['school_user']['permissions'])) {
            return false;
        }

        $userPermissions = $_SESSION['school_user']['permissions'];

        // Check for wildcard or specific permission
        foreach ($userPermissions as $userPerm) {
            if ($userPerm === '*' || $userPerm === $permission) {
                return true;
            }

            // Check for pattern matching (e.g., 'student.*')
            if (strpos($userPerm, '*') !== false) {
                $pattern = '/^' . str_replace('*', '.*', $userPerm) . '$/';
                if (preg_match($pattern, $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Require specific permission
     * @param string $permission
     */
    public function requirePermission($permission)
    {
        if (!$this->hasPermission($permission)) {
            http_response_code(403);
            die("Access denied. You don't have permission to access this resource.");
        }
    }

    /**
     * Logout user
     * @param bool $redirect
     */
    public function logout($redirect = true)
    {
        // Log logout activity
        if (isset($_SESSION['super_admin'])) {
            $this->logActivity($_SESSION['super_admin']['id'], 'super_admin', 'logout', 'Super admin logged out');
        } elseif (isset($_SESSION['school_user'])) {
            $schoolDb = Database::getSchoolConnection($_SESSION['school_user']['school_db']);
            $this->logActivity(
                $_SESSION['school_user']['id'],
                $_SESSION['school_user']['user_type'],
                '',
                'User logged out',
                $_SESSION['school_user']['school_id'],
                $schoolDb
            );
        }

        // Destroy session
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();

        if ($redirect) {
            header("Location: ./login.php");
            exit;
        }
    }

    /**
     * Change user password
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @param string $userType super_admin|school_user
     * @param PDO $db (for school users)
     * @return array [success, message]
     */
    public function changePassword(
        $userId,
        $currentPassword,
        $newPassword,
        $userType = 'school_user',
        $db = null
    ) {
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }

        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                $table = 'users';
                if (!$db) {
                    throw new Exception('Database connection required for school users');
                }
            }

            // Get current password hash
            $stmt = $db->prepare("SELECT password FROM $table WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['success' => false, 'message' => 'User not found'];
            }

            // Verify current password
            if (!password_verify($currentPassword, $user['password'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }

            // Hash new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

            // Update password
            $updateStmt = $db->prepare("UPDATE $table SET password = ?, reset_token = NULL WHERE id = ?");
            $updateStmt->execute([$newHash, $userId]);

            // Log password change
            $this->logActivity(
                $userId,
                $userType,
                'password_change',
                'Password changed',
                isset($_SESSION['school_user']) ? $_SESSION['school_user']['school_id'] : null,
                $db
            );

            return ['success' => true, 'message' => 'Password changed successfully'];
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }

    /**
     * Reset password (forgot password)
     * @param string $email
     * @param string $userType super_admin|school_user
     * @param string $schoolSlug (for school users)
     * @return array [success, message, token]
     */
    public function resetPassword($email, $userType = 'school_user', $schoolSlug = null)
    {
        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
                $where = "email = ? AND role = 'super_admin'";
            } else {
                if (!$schoolSlug) {
                    return ['success' => false, 'message' => 'School slug required'];
                }

                $school = $this->getSchoolBySlug($schoolSlug);
                if (!$school) {
                    return ['success' => false, 'message' => 'School not found'];
                }

                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
                $where = "email = ? AND is_active = 1";
            }

            // Check if user exists
            $stmt = $db->prepare("SELECT id, name FROM $table WHERE $where");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                // Don't reveal that user doesn't exist (security)
                return ['success' => true, 'message' => 'If the email exists, reset instructions will be sent'];
            }

            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            // Store token
            $updateStmt = $db->prepare("UPDATE $table SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            $updateStmt->execute([$token, $expiry, $user['id']]);

            // Send reset email
            $resetUrl = APP_URL . "/reset-password.php?token=$token&type=$userType" .
                ($schoolSlug ? "&school=$schoolSlug" : "");

            $subject = "Password Reset Request - " . APP_NAME;
            $body = "
                <h2>Password Reset Request</h2>
                <p>Hello {$user['name']},</p>
                <p>You have requested to reset your password. Click the link below to proceed:</p>
                <p><a href='$resetUrl'>Reset Password</a></p>
                <p>This link will expire in 1 hour.</p>
                <p>If you didn't request this, please ignore this email.</p>
            ";

            // In production, use proper email service
            if (!IS_LOCAL) {
                // sendEmail($email, $subject, $body);
            }

            // Log password reset request
            $this->logActivity(
                $user['id'],
                $userType,
                'password_reset_request',
                'Password reset requested',
                $userType === 'school_user' ? $school['id'] : null,
                $db
            );

            return [
                'success' => true,
                'message' => 'Password reset instructions sent to your email',
                'token' => $token // For testing only
            ];
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to process reset request'];
        }
    }

    /**
     * Validate reset token
     * @param string $token
     * @param string $userType
     * @param string $schoolSlug
     * @return array [success, message, userId]
     */
    public function validateResetToken($token, $userType = 'school_user', $schoolSlug = null)
    {
        try {
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                if (!$schoolSlug) {
                    return ['success' => false, 'message' => 'School slug required'];
                }

                $school = $this->getSchoolBySlug($schoolSlug);
                if (!$school) {
                    return ['success' => false, 'message' => 'School not found'];
                }

                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
            }

            $stmt = $db->prepare("
                SELECT id FROM $table 
                WHERE reset_token = ? AND reset_token_expires > NOW() AND is_active = 1
            ");
            $stmt->execute([$token]);
            $user = $stmt->fetch();

            if (!$user) {
                return ['success' => false, 'message' => 'Invalid or expired reset token'];
            }

            return [
                'success' => true,
                'message' => 'Token is valid',
                'userId' => $user['id']
            ];
        } catch (Exception $e) {
            error_log("Token validation error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to validate token'];
        }
    }

    /**
     * Complete password reset
     * @param string $token
     * @param string $newPassword
     * @param string $userType
     * @param string $schoolSlug
     * @return array [success, message]
     */
    public function completePasswordReset($token, $newPassword, $userType = 'school_user', $schoolSlug = null)
    {
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }

        try {
            // Validate token first
            $validation = $this->validateResetToken($token, $userType, $schoolSlug);
            if (!$validation['success']) {
                return $validation;
            }

            $userId = $validation['userId'];

            // Get database connection
            if ($userType === 'super_admin') {
                $table = 'platform_users';
                $db = $this->db;
            } else {
                $school = $this->getSchoolBySlug($schoolSlug);
                $table = 'users';
                $db = Database::getSchoolConnection($school['database_name']);
            }

            // Hash new password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

            // Update password and clear token
            $stmt = $db->prepare("
                UPDATE $table 
                SET password = ?, reset_token = NULL, reset_token_expires = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$newHash, $userId]);

            // Log password reset completion
            $this->logActivity(
                $userId,
                $userType,
                'password_reset_complete',
                'Password reset completed',
                $userType === 'school_user' ? $school['id'] : null,
                $db
            );

            return ['success' => true, 'message' => 'Password reset successfully'];
        } catch (Exception $e) {
            error_log("Password reset completion error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reset password'];
        }
    }

    /**
     * Get school by slug
     * @param string $slug
     * @return array|null
     */
    private function getSchoolBySlug($slug)
    {
        $stmt = $this->db->prepare("SELECT * FROM schools WHERE slug = ?");
        $stmt->execute([$slug]);
        return $stmt->fetch();
    }

    /**
     * Check if login is locked due to too many attempts
     * @param string $key
     * @param string $type
     * @return bool
     */
    private function isLoginLocked($key, $type)
    {
        if (!isset($this->loginAttempts[$key])) {
            return false;
        }

        $attempts = $this->loginAttempts[$key];

        // Check if locked out
        if (
            $attempts['count'] >= MAX_LOGIN_ATTEMPTS &&
            (time() - $attempts['last_attempt']) < LOGIN_LOCKOUT_TIME
        ) {
            return true;
        }

        // Reset count if lockout time has passed
        if ((time() - $attempts['last_attempt']) >= LOGIN_LOCKOUT_TIME) {
            unset($this->loginAttempts[$key]);
            return false;
        }

        return false;
    }

    /**
     * Record failed login attempt
     * @param string $key
     * @param string $type
     */
    private function recordFailedAttempt($key, $type)
    {
        if (!isset($this->loginAttempts[$key])) {
            $this->loginAttempts[$key] = [
                'count' => 0,
                'last_attempt' => time(),
                'type' => $type
            ];
        }

        $this->loginAttempts[$key]['count']++;
        $this->loginAttempts[$key]['last_attempt'] = time();

        // Log failed attempt
        error_log("Failed login attempt for $key ($type). Attempt: " .
            $this->loginAttempts[$key]['count']);
    }

    /**
     * Clear failed attempts for a key
     * @param string $key
     */
    private function clearFailedAttempts($key)
    {
        if (isset($this->loginAttempts[$key])) {
            unset($this->loginAttempts[$key]);
        }
    }

    /**
     * Update last login time
     * @param int $userId
     * @param string $table
     * @param PDO $db
     */
    private function updateLastLogin($userId, $table, $db = null)
    {
        if ($db === null) {
            $db = $this->db;
        }

        $stmt = $db->prepare("
            UPDATE $table 
            SET last_login_at = NOW(), last_login_ip = ? 
            WHERE id = ?
        ");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $userId]);
    }

    /**
     * Log user activity (make public so other classes can use it)
     * @param int $userId
     * @param string $userType
     * @param string $event
     * @param string $description
     * @param int|null $schoolId
     * @param PDO|null $db
     */
    public function logActivity(
        $userId,
        $userType,
        $event,
        $description,
        $schoolId = null,
        $db = null
    ) {
        try {
            $logData = [
                'school_id' => $schoolId,
                'user_id' => $userId,
                'user_type' => $userType,
                'event' => $event,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'url' => $_SERVER['REQUEST_URI'] ?? ''
            ];

            if ($userType === 'super_admin') {
                // Log to platform audit_logs
                $stmt = $this->db->prepare("
                INSERT INTO audit_logs 
                (user_id, user_type, event, description, ip_address, user_agent, url, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
                $stmt->execute([
                    $logData['user_id'],
                    $logData['user_type'],
                    $logData['event'],
                    $logData['description'],
                    $logData['ip_address'],
                    $logData['user_agent'],
                    $logData['url']
                ]);
            } elseif ($db) {
                // Log to school's audit_logs table
                $stmt = $db->prepare("
                INSERT INTO audit_logs 
                (school_id, user_id, user_type, event, description, ip_address, user_agent, url, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
                $stmt->execute([
                    $logData['school_id'],
                    $logData['user_id'],
                    $logData['user_type'],
                    $logData['event'],
                    $logData['description'],
                    $logData['ip_address'],
                    $logData['user_agent'],
                    $logData['url']
                ]);
            }
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }

    /**
     * Get current user data
     * @return array|null
     */
    public function getCurrentUser()
    {
        if (isset($_SESSION['super_admin'])) {
            return $_SESSION['super_admin'];
        } elseif (isset($_SESSION['school_user'])) {
            return $_SESSION['school_user'];
        }
        return null;
    }

    /**
     * Get current user ID
     * @return int|null
     */
    public function getCurrentUserId()
    {
        $user = $this->getCurrentUser();
        return $user ? $user['id'] : null;
    }

    /**
     * Get current school ID (for school users)
     * @return int|null
     */
    public function getCurrentSchoolId()
    {
        return $_SESSION['school_user']['school_id'] ?? null;
    }

    /**
     * Get current user type
     * @return string|null
     */
    public function getCurrentUserType()
    {
        $user = $this->getCurrentUser();
        return $user ? ($user['role'] ?? $user['user_type']) : null;
    }
}
