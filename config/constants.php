<?php
/**
 * Application Constants
 */


// Application Information
define('APP_NAME', 'AcademixSuite');
define('APP_VERSION', '1.0.0');
define('APP_ENV', IS_LOCAL ? 'development' : 'production');
define('APP_DEBUG', IS_LOCAL);
define('APP_URL', IS_LOCAL ? 'http://localhost/academixsuite' : 'https://yoursaas.com');

// File Upload Constants
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv']);
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');

// User Roles Constants
define('ROLE_SUPER_ADMIN', 'super_admin');
define('ROLE_SCHOOL_ADMIN', 'admin');
define('ROLE_TEACHER', 'teacher');
define('ROLE_STUDENT', 'student');
define('ROLE_PARENT', 'parent');
define('ROLE_ACCOUNTANT', 'accountant');
define('ROLE_LIBRARIAN', 'librarian');

// School Status Constants
define('SCHOOL_STATUS_PENDING', 'pending');
define('SCHOOL_STATUS_TRIAL', 'trial');
define('SCHOOL_STATUS_ACTIVE', 'active');
define('SCHOOL_STATUS_SUSPENDED', 'suspended');
define('SCHOOL_STATUS_CANCELLED', 'cancelled');

// Subscription Status
define('SUBSCRIPTION_ACTIVE', 'active');
define('SUBSCRIPTION_PENDING', 'pending');
define('SUBSCRIPTION_CANCELLED', 'cancelled');
define('SUBSCRIPTION_PAST_DUE', 'past_due');

// Attendance Status
define('ATTENDANCE_PRESENT', 'present');
define('ATTENDANCE_ABSENT', 'absent');
define('ATTENDANCE_LATE', 'late');
define('ATTENDANCE_HALF_DAY', 'half_day');

// Fee Status
define('FEE_PENDING', 'pending');
define('FEE_PARTIAL', 'partial');
define('FEE_PAID', 'paid');
define('FEE_OVERDUE', 'overdue');

// Exam Grade Scales
define('GRADE_A_PLUS', 'A+');
define('GRADE_A', 'A');
define('GRADE_B_PLUS', 'B+');
define('GRADE_B', 'B');
define('GRADE_C_PLUS', 'C+');
define('GRADE_C', 'C');
define('GRADE_D', 'D');
define('GRADE_F', 'F');

// Gender Options
define('GENDER_MALE', 'male');
define('GENDER_FEMALE', 'female');
define('GENDER_OTHER', 'other');

// Academic Term Types
define('TERM_FIRST', 'first');
define('TERM_SECOND', 'second');
define('TERM_THIRD', 'third');

// Notification Types
define('NOTIFICATION_EMAIL', 'email');
define('NOTIFICATION_SMS', 'sms');
define('NOTIFICATION_PUSH', 'push');

// Payment Methods
define('PAYMENT_CASH', 'cash');
define('PAYMENT_CHEQUE', 'cheque');
define('PAYMENT_BANK_TRANSFER', 'bank_transfer');
define('PAYMENT_CARD', 'card');
define('PAYMENT_ONLINE', 'online');

// Date Formats
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd M, Y');
define('DISPLAY_DATETIME_FORMAT', 'd M, Y h:i A');

// Currency
define('CURRENCY', 'NGN');
define('CURRENCY_SYMBOL', '₦');

// Pagination
define('ITEMS_PER_PAGE', 20);
define('MAX_PAGE_LINKS', 5);

// Security
define('PASSWORD_MIN_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 15 * 60); // 15 minutes in seconds
define('SESSION_TIMEOUT', 24 * 60 * 60); // 24 hours
define('CSRF_TOKEN_EXPIRY', 3600); // 1 hour

// File Paths
define('SCHOOL_LOGOS_PATH', 'assets/uploads/schools/logos/');
define('STUDENT_PHOTOS_PATH', 'assets/uploads/schools/students/photos/');
define('TEACHER_PHOTOS_PATH', 'assets/uploads/schools/teachers/photos/');
define('ASSIGNMENTS_PATH', 'assets/uploads/schools/assignments/');
define('REPORTS_PATH', 'assets/uploads/schools/reports/');

// API Keys (Should be in environment variables in production)
if (IS_LOCAL) {
    define('SMS_API_KEY', 'test_key');
    define('EMAIL_API_KEY', 'test_key');
    define('PAYMENT_PUBLIC_KEY', 'test_key');
    define('PAYMENT_SECRET_KEY', 'test_key');
} else {
    // Try to get from environment, fallback to empty
    define('SMS_API_KEY', getenv('SMS_API_KEY') ?: '');
    define('EMAIL_API_KEY', getenv('EMAIL_API_KEY') ?: '');
    define('PAYMENT_PUBLIC_KEY', getenv('PAYMENT_PUBLIC_KEY') ?: '');
    define('PAYMENT_SECRET_KEY', getenv('PAYMENT_SECRET_KEY') ?: '');
}

// Cache Settings
define('CACHE_ENABLED', !IS_LOCAL);
define('CACHE_EXPIRY', 3600); // 1 hour
define('CACHE_DIR', __DIR__ . '/../cache/');

// Logging
define('LOG_ERRORS', true);
define('LOG_DIR', __DIR__ . '/../logs/');
define('LOG_LEVEL', IS_LOCAL ? 'DEBUG' : 'ERROR');

// Demo Mode
define('DEMO_MODE', false);
define('DEMO_SCHOOL_ID', 1);