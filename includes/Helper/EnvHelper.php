<?php
/**
 * Environment Helper
 * Handles environment variable loading and configuration
 */
namespace AcademixSuite\Helpers;

class EnvHelper {
    
    private static $env = [];
    
    /**
     * Load environment variables from .env file
     */
    public static function load(string $path = null): void {
        if ($path === null) {
            $path = __DIR__ . '/../../.env';
        }
        
        if (!file_exists($path)) {
            return;
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse key=value
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if (($value[0] === '"' && substr($value, -1) === '"') || 
                    ($value[0] === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                self::$env[$key] = $value;
                
                // Also set in $_ENV and $_SERVER for compatibility
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
    
    /**
     * Get environment variable
     */
    public static function get(string $key, $default = null) {
        // Check in loaded env
        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }
        
        // Check in $_ENV
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        
        // Check in $_SERVER
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        
        // Check in getenv
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Set environment variable
     */
    public static function set(string $key, $value): void {
        self::$env[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
    
    /**
     * Check if environment variable exists
     */
    public static function has(string $key): bool {
        return isset(self::$env[$key]) || 
               isset($_ENV[$key]) || 
               isset($_SERVER[$key]) || 
               getenv($key) !== false;
    }
    
    /**
     * Get all environment variables
     */
    public static function all(): array {
        return array_merge(self::$env, $_ENV);
    }
    
    /**
     * Get application environment
     */
    public static function appEnv(): string {
        return self::get('APP_ENV', 'production');
    }
    
    /**
     * Check if in development environment
     */
    public static function isDevelopment(): bool {
        return self::appEnv() === 'development';
    }
    
    /**
     * Check if in production environment
     */
    public static function isProduction(): bool {
        return self::appEnv() === 'production';
    }
    
    /**
     * Get database configuration
     */
    public static function dbConfig(): array {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'port' => self::get('DB_PORT', '3306'),
            'database' => self::get('DB_DATABASE', 'academixsuite'),
            'username' => self::get('DB_USERNAME', 'root'),
            'password' => self::get('DB_PASSWORD', ''),
            'charset' => self::get('DB_CHARSET', 'utf8mb4'),
            'collation' => self::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
        ];
    }
    
    /**
     * Get mail configuration
     */
    public static function mailConfig(): array {
        return [
            'driver' => self::get('MAIL_DRIVER', 'smtp'),
            'host' => self::get('MAIL_HOST', 'smtp.gmail.com'),
            'port' => self::get('MAIL_PORT', 587),
            'username' => self::get('MAIL_USERNAME'),
            'password' => self::get('MAIL_PASSWORD'),
            'encryption' => self::get('MAIL_ENCRYPTION', 'tls'),
            'from' => [
                'address' => self::get('MAIL_FROM_ADDRESS', 'no-reply@academixsuite.com'),
                'name' => self::get('MAIL_FROM_NAME', 'AcademixSuite'),
            ],
        ];
    }
    
    /**
     * Get payment gateway configuration
     */
    public static function paymentConfig(): array {
        return [
            'default_gateway' => self::get('DEFAULT_PAYMENT_GATEWAY', 'paystack'),
            'paystack' => [
                'public_key' => self::get('PAYSTACK_PUBLIC_KEY'),
                'secret_key' => self::get('PAYSTACK_SECRET_KEY'),
                'test_public_key' => self::get('PAYSTACK_TEST_PUBLIC_KEY'),
                'test_secret_key' => self::get('PAYSTACK_TEST_SECRET_KEY'),
            ],
            'flutterwave' => [
                'public_key' => self::get('FLUTTERWAVE_PUBLIC_KEY'),
                'secret_key' => self::get('FLUTTERWAVE_SECRET_KEY'),
                'encryption_key' => self::get('FLUTTERWAVE_ENCRYPTION_KEY'),
            ],
        ];
    }
}

// Alias function for easy access
function env(string $key, $default = null) {
    return \AcademixSuite\Helpers\EnvHelper::get($key, $default);
}