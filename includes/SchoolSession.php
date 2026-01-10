<?php
/**
 * School Session Management
 * Handles school-specific session data and validation
 */

class SchoolSession {
    
    /**
     * Initialize school session
     * @param array $school
     * @param array $user
     */
    public static function init($school, $user) {
        Session::start();
        
        // Set school context
        Session::set('school_id', $school['id']);
        Session::set('school_name', $school['name']);
        Session::set('school_slug', $school['slug']);
        Session::set('school_database', $school['database_name']);
        Session::set('school_logo', $school['logo_path']);
        Session::set('school_primary_color', $school['primary_color']);
        Session::set('school_secondary_color', $school['secondary_color']);
        
        // Set user context
        Session::set('user_id', $user['id']);
        Session::set('user_name', $user['name']);
        Session::set('user_email', $user['email']);
        Session::set('user_type', $user['user_type']);
        Session::set('user_permissions', $user['permissions'] ?? []);
        
        // Set timestamps
        Session::set('login_time', time());
        Session::set('last_activity', time());
        
        // Generate session token for API requests
        $sessionToken = bin2hex(random_bytes(32));
        Session::set('session_token', $sessionToken);
        
        // Store in database for validation
        self::storeSessionToken($school['id'], $user['id'], $sessionToken);
    }
    
    /**
     * Store session token in database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     */
    private static function storeSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            
            // Clear old sessions for this user
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND expires_at < NOW()");
            $stmt->execute([$userId]);
            
            // Insert new session
            $stmt = $db->prepare("
                INSERT INTO user_sessions 
                (user_id, session_token, ip_address, user_agent, expires_at, created_at) 
                VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())
            ");
            
            $stmt->execute([
                $userId,
                $token,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                SESSION_TIMEOUT
            ]);
            
        } catch (Exception $e) {
            error_log("Failed to store session token: " . $e->getMessage());
        }
    }
    
    /**
     * Validate current school session
     * @return bool
     */
    public static function validate() {
        Session::start();
        
        // Check if school session exists
        if (!Session::has('school_id') || !Session::has('user_id')) {
            return false;
        }
        
        // Check session timeout
        if (Session::isTimedOut()) {
            self::destroy();
            return false;
        }
        
        // Update last activity
        Session::updateLastActivity();
        
        // Validate session token (optional, for extra security)
        if (Session::has('session_token')) {
            return self::validateSessionToken(
                Session::get('school_id'),
                Session::get('user_id'),
                Session::get('session_token')
            );
        }
        
        return true;
    }
    
    /**
     * Validate session token against database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     * @return bool
     */
    private static function validateSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            
            $stmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? AND session_token = ? AND expires_at > NOW()
            ");
            
            $stmt->execute([$userId, $token]);
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (Exception $e) {
            error_log("Failed to validate session token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current school ID from session
     * @return int|null
     */
    public static function getSchoolId() {
        return Session::get('school_id');
    }
    
    /**
     * Get current user ID from session
     * @return int|null
     */
    public static function getUserId() {
        return Session::get('user_id');
    }
    
    /**
     * Get current user type from session
     * @return string|null
     */
    public static function getUserType() {
        return Session::get('user_type');
    }
    
    /**
     * Get current user permissions from session
     * @return array
     */
    public static function getUserPermissions() {
        return Session::get('user_permissions', []);
    }
    
    /**
     * Check if user has permission
     * @param string $permission
     * @return bool
     */
    public static function hasPermission($permission) {
        $permissions = self::getUserPermissions();
        
        if (empty($permissions)) {
            return false;
        }
        
        // Check for wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }
        
        // Check for specific permission
        if (in_array($permission, $permissions)) {
            return true;
        }
        
        // Check for pattern permission (e.g., 'student.*')
        foreach ($permissions as $userPerm) {
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
    public static function requirePermission($permission) {
        if (!self::hasPermission($permission)) {
            http_response_code(403);
            die("Access denied. You don't have permission to access this resource.");
        }
    }
    
    /**
     * Get school database connection
     * @return PDO|null
     */
    public static function getSchoolDb() {
        $schoolId = self::getSchoolId();
        if (!$schoolId) {
            return null;
        }
        
        return Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
    }
    
    /**
     * Get school information from session
     * @return array|null
     */
    public static function getSchoolInfo() {
        if (!self::validate()) {
            return null;
        }
        
        return [
            'id' => Session::get('school_id'),
            'name' => Session::get('school_name'),
            'slug' => Session::get('school_slug'),
            'database' => Session::get('school_database'),
            'logo' => Session::get('school_logo'),
            'primary_color' => Session::get('school_primary_color'),
            'secondary_color' => Session::get('school_secondary_color')
        ];
    }
    
    /**
     * Get user information from session
     * @return array|null
     */
    public static function getUserInfo() {
        if (!self::validate()) {
            return null;
        }
        
        return [
            'id' => Session::get('user_id'),
            'name' => Session::get('user_name'),
            'email' => Session::get('user_email'),
            'type' => Session::get('user_type'),
            'permissions' => Session::get('user_permissions')
        ];
    }
    
    /**
     * Update user session data
     * @param array $userData
     */
    public static function updateUserData($userData) {
        if (isset($userData['name'])) {
            Session::set('user_name', $userData['name']);
        }
        
        if (isset($userData['email'])) {
            Session::set('user_email', $userData['email']);
        }
        
        if (isset($userData['permissions'])) {
            Session::set('user_permissions', $userData['permissions']);
        }
    }
    
    /**
     * Update school session data
     * @param array $schoolData
     */
    public static function updateSchoolData($schoolData) {
        if (isset($schoolData['name'])) {
            Session::set('school_name', $schoolData['name']);
        }
        
        if (isset($schoolData['logo_path'])) {
            Session::set('school_logo', $schoolData['logo_path']);
        }
        
        if (isset($schoolData['primary_color'])) {
            Session::set('school_primary_color', $schoolData['primary_color']);
        }
        
        if (isset($schoolData['secondary_color'])) {
            Session::set('school_secondary_color', $schoolData['secondary_color']);
        }
    }
    
    /**
     * Destroy school session
     */
    public static function destroy() {
        // Remove session token from database
        $schoolId = Session::get('school_id');
        $userId = Session::get('user_id');
        $token = Session::get('session_token');
        
        if ($schoolId && $userId && $token) {
            self::removeSessionToken($schoolId, $userId, $token);
        }
        
        // Clear session data
        Session::remove('school_id');
        Session::remove('school_name');
        Session::remove('school_slug');
        Session::remove('school_database');
        Session::remove('school_logo');
        Session::remove('school_primary_color');
        Session::remove('school_secondary_color');
        
        Session::remove('user_id');
        Session::remove('user_name');
        Session::remove('user_email');
        Session::remove('user_type');
        Session::remove('user_permissions');
        
        Session::remove('login_time');
        Session::remove('last_activity');
        Session::remove('session_token');
    }
    
    /**
     * Remove session token from database
     * @param int $schoolId
     * @param int $userId
     * @param string $token
     */
    private static function removeSessionToken($schoolId, $userId, $token) {
        try {
            $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_token = ?");
            $stmt->execute([$userId, $token]);
        } catch (Exception $e) {
            error_log("Failed to remove session token: " . $e->getMessage());
        }
    }
    
    /**
     * Regenerate session ID
     */
    public static function regenerateId() {
        Session::regenerateId(true);
    }
    
    /**
     * Check if user is school admin
     * @return bool
     */
    public static function isSchoolAdmin() {
        return self::getUserType() === 'admin';
    }
    
    /**
     * Check if user is teacher
     * @return bool
     */
    public static function isTeacher() {
        return self::getUserType() === 'teacher';
    }
    
    /**
     * Check if user is student
     * @return bool
     */
    public static function isStudent() {
        return self::getUserType() === 'student';
    }
    
    /**
     * Check if user is parent
     * @return bool
     */
    public static function isParent() {
        return self::getUserType() === 'parent';
    }
    
    /**
     * Get user dashboard URL based on user type
     * @return string
     */
    public static function getDashboardUrl() {
        $schoolSlug = Session::get('school_slug');
        $userType = self::getUserType();
        
        switch ($userType) {
            case 'admin':
                return "/school/$schoolSlug/admin/dashboard.php";
            case 'teacher':
                return "/school/$schoolSlug/teacher/dashboard.php";
            case 'student':
                return "/school/$schoolSlug/student/dashboard.php";
            case 'parent':
                return "/school/$schoolSlug/parent/dashboard.php";
            case 'accountant':
                return "/school/$schoolSlug/accountant/dashboard.php";
            case 'librarian':
                return "/school/$schoolSlug/librarian/dashboard.php";
            default:
                return "/school/$schoolSlug/dashboard.php";
        }
    }
    
    /**
     * Redirect to dashboard if already logged in
     */
    public static function redirectIfLoggedIn() {
        if (self::validate()) {
            header('Location: ' . self::getDashboardUrl());
            exit;
        }
    }
    
    /**
     * Require login
     */
    public static function requireLogin() {
        if (!self::validate()) {
            $schoolSlug = $_GET['school'] ?? '';
            header("Location: /login.php?school=" . urlencode($schoolSlug));
            exit;
        }
    }
    
    /**
     * Require specific user type
     * @param string|array $userTypes
     */
    public static function requireUserType($userTypes) {
        self::requireLogin();
        
        if (!is_array($userTypes)) {
            $userTypes = [$userTypes];
        }
        
        $currentType = self::getUserType();
        
        if (!in_array($currentType, $userTypes)) {
            http_response_code(403);
            die("Access denied. This page is only accessible to: " . implode(', ', $userTypes));
        }
    }
}
?>