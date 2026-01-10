<?php
/**
 * Session Management Wrapper
 * Provides secure session handling with CSRF protection
 */

class Session {
    
    /**
     * Start session if not already started
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session configuration
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', !IS_LOCAL);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_lifetime', SESSION_TIMEOUT);
            
            session_name('AcademixSuite_session');
            session_start();
            
            // Regenerate ID periodically for security
            self::regenerateIfNeeded();
        }
    }
    
    /**
     * Regenerate session ID if needed
     */
    private static function regenerateIfNeeded() {
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } elseif (time() - $_SESSION['created'] > 1800) { // 30 minutes
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
    
    /**
     * Set session value
     * @param string $key
     * @param mixed $value
     */
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get session value
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session key exists
     * @param string $key
     * @return bool
     */
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove session value
     * @param string $key
     */
    public static function remove($key) {
        self::start();
        unset($_SESSION[$key]);
    }
    
    /**
     * Set flash message
     * @param string $key
     * @param mixed $value
     */
    public static function flash($key, $value) {
        self::start();
        $_SESSION['flash'][$key] = $value;
    }
    
    /**
     * Get and remove flash message
     * @param string $key
     * @return mixed
     */
    public static function getFlash($key) {
        self::start();
        if (isset($_SESSION['flash'][$key])) {
            $value = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $value;
        }
        return null;
    }
    
    /**
     * Check if flash message exists
     * @param string $key
     * @return bool
     */
    public static function hasFlash($key) {
        self::start();
        return isset($_SESSION['flash'][$key]);
    }
    
    /**
     * Generate CSRF token
     * @param string $formId
     * @return string
     */
    public static function generateCsrfToken($formId = 'default') {
        self::start();
        
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Clean expired tokens
        self::cleanExpiredCsrfTokens();
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens'][$formId] = [
            'token' => $token,
            'expiry' => time() + CSRF_TOKEN_EXPIRY
        ];
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     * @param string $token
     * @param string $formId
     * @return bool
     */
    public static function validateCsrfToken($token, $formId = 'default') {
        self::start();
        
        if (!isset($_SESSION['csrf_tokens'][$formId])) {
            return false;
        }
        
        $stored = $_SESSION['csrf_tokens'][$formId];
        
        // Check if expired
        if (time() > $stored['expiry']) {
            unset($_SESSION['csrf_tokens'][$formId]);
            return false;
        }
        
        // Validate token
        if (!hash_equals($stored['token'], $token)) {
            unset($_SESSION['csrf_tokens'][$formId]);
            return false;
        }
        
        // Remove token after validation (single use)
        unset($_SESSION['csrf_tokens'][$formId]);
        return true;
    }
    
    /**
     * Clean expired CSRF tokens
     */
    private static function cleanExpiredCsrfTokens() {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        foreach ($_SESSION['csrf_tokens'] as $formId => $data) {
            if (time() > $data['expiry']) {
                unset($_SESSION['csrf_tokens'][$formId]);
            }
        }
    }
    
    /**
     * Get all session data (for debugging)
     * @return array
     */
    public static function getAll() {
        self::start();
        return $_SESSION;
    }
    
    /**
     * Destroy session
     */
    public static function destroy() {
        self::start();
        
        // Clear all session data
        $_SESSION = [];
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
    
    /**
     * Set school context in session
     * @param array $school
     */
    public static function setSchoolContext($school) {
        self::set('school_id', $school['id']);
        self::set('school_name', $school['name']);
        self::set('school_slug', $school['slug']);
        self::set('school_db', $school['database_name']);
    }
    
    /**
     * Get school context from session
     * @return array|null
     */
    public static function getSchoolContext() {
        return [
            'id' => self::get('school_id'),
            'name' => self::get('school_name'),
            'slug' => self::get('school_slug'),
            'database_name' => self::get('school_db')
        ];
    }
    
    /**
     * Clear school context
     */
    public static function clearSchoolContext() {
        self::remove('school_id');
        self::remove('school_name');
        self::remove('school_slug');
        self::remove('school_db');
    }
    
    /**
     * Set user data in session
     * @param string $userType super_admin|school_user
     * @param array $userData
     */
    public static function setUserData($userType, $userData) {
        self::set($userType, $userData);
    }
    
    /**
     * Get user data from session
     * @param string $userType
     * @return array|null
     */
    public static function getUserData($userType) {
        return self::get($userType);
    }
    
    /**
     * Clear user data from session
     * @param string $userType
     */
    public static function clearUserData($userType) {
        self::remove($userType);
    }
    
    /**
     * Check if session has timed out
     * @return bool
     */
    public static function isTimedOut() {
        $lastActivity = self::get('last_activity');
        if (!$lastActivity) {
            return true;
        }
        
        return (time() - $lastActivity) > SESSION_TIMEOUT;
    }
    
    /**
     * Update last activity timestamp
     */
    public static function updateLastActivity() {
        self::set('last_activity', time());
    }
    
    /**
     * Get session ID
     * @return string
     */
    public static function getId() {
        self::start();
        return session_id();
    }
    
    /**
     * Regenerate session ID
     * @param bool $deleteOldSession
     */
    public static function regenerateId($deleteOldSession = true) {
        self::start();
        session_regenerate_id($deleteOldSession);
    }
}
?>