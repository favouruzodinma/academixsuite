<?php
/**
 * Database Configuration
 * This file contains database connection settings for both local (XAMPP) and production (AWS)
 */

// Development environment detection
define('IS_LOCAL', $_SERVER['SERVER_NAME'] == 'localhost' || $_SERVER['SERVER_NAME'] == '127.0.0.1');

// Database Configuration for Platform (Shared Database)
if (IS_LOCAL) {
    // XAMPP Local Development
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PLATFORM_NAME', 'school_platform');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATION', 'utf8mb4_unicode_ci');
} else {
    // AWS RDS Production
    define('DB_HOST', 'your-rds-endpoint.cluster-xxx.us-east-1.rds.amazonaws.com');
    define('DB_PORT', '3306');
    define('DB_USER', 'saas_admin');
    define('DB_PASS', 'StrongPassword123!');
    define('DB_PLATFORM_NAME', 'school_platform');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATION', 'utf8mb4_unicode_ci');
}

// School Database Template
// Each school will have its own database like: school_1, school_2, etc.
define('DB_SCHOOL_PREFIX', 'school_');

// Connection Pool Settings
define('DB_MAX_CONNECTIONS', 20);
define('DB_IDLE_TIMEOUT', 300); // 5 minutes

// Enable/Disable Query Logging
define('DB_LOG_QUERIES', IS_LOCAL);
define('DB_LOG_FILE', __DIR__ . '/../logs/database.log');

// Database Driver (MySQLi or PDO)
define('DB_DRIVER', 'PDO'); // Options: PDO, MySQLi

// SSL/TLS for Production (AWS RDS)
define('DB_SSL_ENABLED', !IS_LOCAL);
define('DB_SSL_CA', __DIR__ . '/../certs/rds-combined-ca-bundle.pem');

// Connection retry settings
define('DB_CONNECT_RETRIES', 3);
define('DB_CONNECT_TIMEOUT', 5); // seconds

// Table Prefix (if needed)
define('DB_TABLE_PREFIX', '');

// Default timezone for database
date_default_timezone_set('Africa/Lagos');

// Error reporting
if (IS_LOCAL) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_WARNING | E_PARSE);
    ini_set('display_errors', 0);
}

// Database backup settings
define('BACKUP_ENABLED', true);
define('BACKUP_DIR', __DIR__ . '/../backups');
define('BACKUP_RETENTION_DAYS', 7);
// Add this at the end:
// Global database functions (legacy support)

// function getDBConnection($database = null) {
//     return $database ? Database::getSchoolConnection($database) : Database::getPlatformConnection();
// }

?>