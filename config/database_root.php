<?php
/**
 * Root Database Configuration
 * NEVER commit this file to version control!
 * Use environment variables in production.
 */

// Development credentials (change these!)
define('ROOT_DB_HOST', 'localhost');
define('ROOT_DB_PORT', 3306);
define('ROOT_DB_USER', 'academixsuite_platfrom');
define('ROOT_DB_PASS', '!@#admin!@#');

// Alternative: Use a limited-privilege user instead of root
// define('ROOT_DB_USER', 'schools_manager');
// define('ROOT_DB_PASS', 'StrongPassword123!');

/**
 * Encryption key for storing database credentials
 * Generate with: openssl rand -base64 32
 */
define('ENCRYPTION_KEY', 'your-32-character-encryption-key-here');

/**
 * Security settings
 */
define('ALLOW_ROOT_DB_CREATION', true); // Set to false to disable
define('MAX_DATABASES_PER_HOUR', 50);   // Rate limiting