<?php
/**
 * Utility Functions
 * Various helper functions used throughout the application
 */

class Utils {
    
    /**
     * Generate unique slug from string
     * @param string $string
     * @param string $table
     * @param string $column
     * @return string
     */
    public static function generateSlug($string, $table = null, $column = 'slug') {
        $slug = strtolower(trim($string));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // If table is provided, ensure uniqueness
        if ($table) {
            $db = Database::getPlatformConnection();
            $counter = 1;
            $originalSlug = $slug;
            
            while (true) {
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM $table WHERE $column = ?");
                $stmt->execute([$slug]);
                $result = $stmt->fetch();
                
                if ($result['count'] == 0) {
                    break;
                }
                
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
        }
        
        return $slug;
    }
    
    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    public static function generateRandomPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }
    
    /**
     * Validate Nigerian phone number
     * @param string $phone
     * @return bool
     */
    public static function isValidNigerianPhone($phone) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Check if it's a valid Nigerian number
        // Nigerian numbers: 080, 081, 070, 071, 090, 091, etc.
        return preg_match('/^(0|234)(7|8|9)(0|1)\d{8}$/', $phone);
    }
    
    /**
     * Format Nigerian phone number
     * @param string $phone
     * @return string
     */
    public static function formatNigerianPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert to international format if starts with 0
        if (strlen($phone) == 11 && substr($phone, 0, 1) == '0') {
            $phone = '234' . substr($phone, 1);
        }
        
        return $phone;
    }
    
    /**
     * Calculate age from date of birth
     * @param string $dob
     * @return int
     */
    public static function calculateAge($dob) {
        if (empty($dob) || $dob == '0000-00-00') {
            return 0;
        }
        
        $birthDate = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birthDate);
        return $age->y;
    }
    
    /**
     * Format date for display
     * @param string $date
     * @param string $format
     * @return string
     */
    public static function formatDate($date, $format = DISPLAY_DATE_FORMAT) {
        if (empty($date) || $date == '0000-00-00') {
            return 'N/A';
        }
        
        try {
            $dateObj = new DateTime($date);
            return $dateObj->format($format);
        } catch (Exception $e) {
            return $date;
        }
    }
    
    /**
     * Format date and time for display
     * @param string $datetime
     * @return string
     */
    public static function formatDateTime($datetime) {
        return self::formatDate($datetime, DISPLAY_DATETIME_FORMAT);
    }
    
    /**
     * Format currency
     * @param float $amount
     * @param bool $withSymbol
     * @return string
     */
    public static function formatCurrency($amount, $withSymbol = true) {
        if (!is_numeric($amount)) {
            $amount = 0;
        }
        
        $formatted = number_format($amount, 2);
        
        if ($withSymbol) {
            return CURRENCY_SYMBOL . $formatted;
        }
        
        return $formatted;
    }
    
    /**
     * Truncate text
     * @param string $text
     * @param int $length
     * @param string $suffix
     * @return string
     */
    public static function truncate($text, $length = 100, $suffix = '...') {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        
        $truncated = mb_substr($text, 0, $length - mb_strlen($suffix));
        return $truncated . $suffix;
    }
    
    /**
     * Sanitize filename
     * @param string $filename
     * @return string
     */
    public static function sanitizeFilename($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }
    
    /**
     * Get file extension
     * @param string $filename
     * @return string
     */
    public static function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
    
    /**
     * Check if file is an image
     * @param string $filename
     * @return bool
     */
    public static function isImage($filename) {
        $ext = self::getFileExtension($filename);
        return in_array($ext, ALLOWED_IMAGE_TYPES);
    }
    
    /**
     * Check if file is a document
     * @param string $filename
     * @return bool
     */
    public static function isDocument($filename) {
        $ext = self::getFileExtension($filename);
        return in_array($ext, ALLOWED_DOC_TYPES);
    }
    
    /**
     * Generate UUID v4
     * @return string
     */
    public static function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Generate student admission number
     * @param int $schoolId
     * @param int $year
     * @param string $prefix
     * @return string
     */
    public static function generateAdmissionNumber($schoolId, $year, $prefix = 'SCH') {
        $schoolCode = str_pad($schoolId, 3, '0', STR_PAD_LEFT);
        $yearCode = substr($year, -2);
        
        // Get last admission number for this school/year
        $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
        $stmt = $db->prepare("
            SELECT admission_number 
            FROM students 
            WHERE admission_number LIKE ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefix . $schoolCode . $yearCode . '%']);
        $last = $stmt->fetch();
        
        if ($last && preg_match('/\d{4}$/', $last['admission_number'], $matches)) {
            $nextNum = (int)$matches[0] + 1;
        } else {
            $nextNum = 1;
        }
        
        $serial = str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        return $prefix . $schoolCode . $yearCode . $serial;
    }
    
    /**
     * Generate employee ID for teacher/staff
     * @param int $schoolId
     * @param string $type TEACH|STAFF
     * @return string
     */
    public static function generateEmployeeId($schoolId, $type = 'TEACH') {
        $schoolCode = str_pad($schoolId, 3, '0', STR_PAD_LEFT);
        $year = date('y');
        
        $db = Database::getSchoolConnection(DB_SCHOOL_PREFIX . $schoolId);
        $stmt = $db->prepare("
            SELECT employee_id 
            FROM teachers 
            WHERE employee_id LIKE ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$type . $schoolCode . $year . '%']);
        $last = $stmt->fetch();
        
        if ($last && preg_match('/\d{3}$/', $last['employee_id'], $matches)) {
            $nextNum = (int)$matches[0] + 1;
        } else {
            $nextNum = 1;
        }
        
        $serial = str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        return $type . $schoolCode . $year . $serial;
    }
    
    /**
     * Calculate GPA from grades
     * @param array $grades Array of grade points
     * @return float
     */
    public static function calculateGPA($grades) {
        if (empty($grades)) {
            return 0.0;
        }
        
        $totalPoints = 0;
        $totalCredits = 0;
        
        foreach ($grades as $grade) {
            $point = self::gradeToPoint($grade['grade']);
            $credits = $grade['credits'] ?? 1.0;
            
            $totalPoints += $point * $credits;
            $totalCredits += $credits;
        }
        
        if ($totalCredits == 0) {
            return 0.0;
        }
        
        return round($totalPoints / $totalCredits, 2);
    }
    
    /**
     * Convert letter grade to point
     * @param string $grade
     * @return float
     */
    public static function gradeToPoint($grade) {
        $scale = [
            'A+' => 5.0, 'A' => 5.0, 'A-' => 4.5,
            'B+' => 4.0, 'B' => 3.5, 'B-' => 3.0,
            'C+' => 2.5, 'C' => 2.0, 'C-' => 1.5,
            'D' => 1.0, 'E' => 0.5, 'F' => 0.0
        ];
        
        return $scale[strtoupper($grade)] ?? 0.0;
    }
    
    /**
     * Calculate percentage to grade
     * @param float $percentage
     * @return string
     */
    public static function percentageToGrade($percentage) {
        if ($percentage >= 90) return 'A+';
        if ($percentage >= 80) return 'A';
        if ($percentage >= 75) return 'A-';
        if ($percentage >= 70) return 'B+';
        if ($percentage >= 65) return 'B';
        if ($percentage >= 60) return 'B-';
        if ($percentage >= 55) return 'C+';
        if ($percentage >= 50) return 'C';
        if ($percentage >= 45) return 'C-';
        if ($percentage >= 40) return 'D';
        if ($percentage >= 35) return 'E';
        return 'F';
    }
    
    /**
     * Get Nigerian states
     * @return array
     */
    public static function getNigerianStates() {
        return [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa',
            'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo',
            'Ekiti', 'Enugu', 'Gombe', 'Imo', 'Jigawa', 'Kaduna',
            'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos',
            'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo',
            'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
            'FCT Abuja'
        ];
    }
    
    /**
     * Get blood groups
     * @return array
     */
    public static function getBloodGroups() {
        return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    }
    
    /**
     * Get religions
     * @return array
     */
    public static function getReligions() {
        return ['Christianity', 'Islam', 'Traditional', 'Other'];
    }
    
    /**
     * Get academic terms
     * @return array
     */
    public static function getAcademicTerms() {
        return [
            'first' => 'First Term',
            'second' => 'Second Term', 
            'third' => 'Third Term'
        ];
    }
    
    /**
     * Get months
     * @return array
     */
    public static function getMonths() {
        return [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];
    }
    
    /**
     * Get days of week
     * @return array
     */
    public static function getDaysOfWeek() {
        return [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday'
        ];
    }
    
    /**
     * Send JSON response
     * @param mixed $data
     * @param int $statusCode
     */
    public static function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Send error response
     * @param string $message
     * @param int $statusCode
     * @param array $additionalData
     */
    public static function errorResponse($message, $statusCode = 400, $additionalData = []) {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($additionalData)) {
            $response['data'] = $additionalData;
        }
        
        self::jsonResponse($response, $statusCode);
    }
    
    /**
     * Send success response
     * @param mixed $data
     * @param string $message
     * @param int $statusCode
     */
    public static function successResponse($data = null, $message = 'Success', $statusCode = 200) {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        self::jsonResponse($response, $statusCode);
    }
    
    /**
     * Validate Nigerian BVN format
     * @param string $bvn
     * @return bool
     */
    public static function isValidBVN($bvn) {
        return preg_match('/^\d{11}$/', $bvn);
    }
    
    /**
     * Validate Nigerian NIN format
     * @param string $nin
     * @return bool
     */
    public static function isValidNIN($nin) {
        return preg_match('/^\d{11}$/', $nin);
    }
    
    /**
     * Encrypt data (simple encryption for non-sensitive data)
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function encrypt($data, $key = '') {
        if (empty($key)) {
            $key = APP_NAME;
        }
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt data
     * @param string $data
     * @param string $key
     * @return string
     */
    public static function decrypt($data, $key = '') {
        if (empty($key)) {
            $key = APP_NAME;
        }
        
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }
    
    /**
     * Get client IP address
     * @return string
     */
    public static function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        
        return $ip;
    }
    
    /**
     * Check if request is AJAX
     * @return bool
     */
    public static function isAjax() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * Get base URL
     * @return string
     */
    public static function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host;
    }
    
    /**
     * Get current URL
     * @return string
     */
    public static function getCurrentUrl() {
        return self::getBaseUrl() . $_SERVER['REQUEST_URI'];
    }
    
    /**
     * Redirect with message
     * @param string $url
     * @param string $message
     * @param string $type success|error|info|warning
     */
    public static function redirect($url, $message = null, $type = 'info') {
        if ($message) {
            Session::flash($type, $message);
        }
        
        header('Location: ' . $url);
        exit;
    }
    
    /**
     * Validate URL
     * @param string $url
     * @return bool
     */
    public static function isValidUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Get file size in human readable format
     * @param int $bytes
     * @return string
     */
    public static function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>