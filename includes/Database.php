<?php
/**
 * Database Connection Manager
 * Handles connections to platform and school databases
 */

class Database {
    private static $platformConnection = null;
    private static $schoolConnections = [];
    private static $queryLog = [];
    
    /**
     * Get connection to platform database
     * @return PDO
     */
    public static function getPlatformConnection() {
        if (self::$platformConnection === null) {
            try {
                require_once __DIR__ . '/../config/database.php';
                require_once __DIR__ . '/../config/constants.php';
                
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                       ";dbname=" . DB_PLATFORM_NAME . ";charset=" . DB_CHARSET;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . 
                                                   " COLLATE " . DB_COLLATION
                ];
                
                if (DB_SSL_ENABLED && file_exists(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                
                self::$platformConnection = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Set timezone
                self::$platformConnection->exec("SET time_zone = '+01:00'");
                
            } catch (PDOException $e) {
                self::logQuery('Connection Error', $e->getMessage(), 0);
                throw new Exception("Platform database connection failed: " . $e->getMessage());
            }
        }
        
        return self::$platformConnection;
    }

    
    

    
    /**
     * Get connection to a specific school's database
     * @param string $schoolDb Database name
     * @return PDO
     */
    public static function getSchoolConnection($schoolDb) {
        if (!isset(self::$schoolConnections[$schoolDb])) {
            try {
                require_once __DIR__ . '/../config/database.php';
                
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                       ";dbname=" . $schoolDb . ";charset=" . DB_CHARSET;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . 
                                                   " COLLATE " . DB_COLLATION
                ];
                
                if (DB_SSL_ENABLED && file_exists(DB_SSL_CA)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                }
                
                self::$schoolConnections[$schoolDb] = new PDO($dsn, DB_USER, DB_PASS, $options);
                
                // Set timezone
                self::$schoolConnections[$schoolDb]->exec("SET time_zone = '+01:00'");
                
            } catch (PDOException $e) {
                self::logQuery('Connection Error', "School DB: $schoolDb - " . $e->getMessage(), 0);
                throw new Exception("School database connection failed: " . $e->getMessage());
            }
        }
        
        return self::$schoolConnections[$schoolDb];
    }
    
    /**
     * Create a new school database
     * @param string $schoolDb Database name
     * @param string $templateSql SQL template
     * @return bool
     */
    public static function createSchoolDatabase($schoolDb, $templateSql = '') {
        try {
            $platformDb = self::getPlatformConnection();
            
            // Create database
            $platformDb->exec("CREATE DATABASE IF NOT EXISTS `$schoolDb` 
                              CHARACTER SET " . DB_CHARSET . " 
                              COLLATE " . DB_COLLATION);
            
            // Import template if provided
            if (!empty($templateSql)) {
                $schoolDbConn = self::getSchoolConnection($schoolDb);
                
                // Split SQL by semicolon, but preserve within quotes
                $queries = self::splitSQL($templateSql);
                
                foreach ($queries as $query) {
                    if (trim($query) !== '') {
                        $schoolDbConn->exec($query);
                    }
                }
            }
            
            self::logQuery('CREATE DATABASE', $schoolDb, 0);
            return true;
            
        } catch (PDOException $e) {
            self::logQuery('CREATE DATABASE Error', $schoolDb . " - " . $e->getMessage(), 0);
            throw new Exception("Failed to create school database: " . $e->getMessage());
        }
    }
    
    /**
     * Drop a school database
     * @param string $schoolDb Database name
     * @return bool
     */
    public static function dropSchoolDatabase($schoolDb) {
        try {
            $platformDb = self::getPlatformConnection();
            $platformDb->exec("DROP DATABASE IF EXISTS `$schoolDb`");
            
            // Remove from connections cache
            if (isset(self::$schoolConnections[$schoolDb])) {
                unset(self::$schoolConnections[$schoolDb]);
            }
            
            self::logQuery('DROP DATABASE', $schoolDb, 0);
            return true;
            
        } catch (PDOException $e) {
            self::logQuery('DROP DATABASE Error', $schoolDb . " - " . $e->getMessage(), 0);
            return false;
        }
    }
    
    /**
     * Check if school database exists
     * @param string $schoolDb Database name
     * @return bool
     */
    public static function schoolDatabaseExists($schoolDb) {
        try {
            $platformDb = self::getPlatformConnection();
            $stmt = $platformDb->query("SHOW DATABASES LIKE '$schoolDb'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get list of all school databases
     * @return array
     */
    public static function getAllSchoolDatabases() {
        try {
            $platformDb = self::getPlatformConnection();
            $stmt = $platformDb->query("SHOW DATABASES LIKE '" . DB_SCHOOL_PREFIX . "%'");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }

    
    
    /**
     * Execute query with logging
     * @param PDO $connection
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public static function executeQuery($connection, $sql, $params = []) {
        $startTime = microtime(true);
        
        try {
            $stmt = $connection->prepare($sql);
            $stmt->execute($params);
            
            $executionTime = microtime(true) - $startTime;
            self::logQuery($sql, $params, $executionTime);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $executionTime = microtime(true) - $startTime;
            self::logQuery($sql . " [ERROR]", $params . " - " . $e->getMessage(), $executionTime);
            throw $e;
        }
    }
    
     /**
     * Create school database with optimized root access (for VPS with cPanel)
     * Creates database AND dedicated user with proper privileges
     * @param string $dbName Database name
     * @param array $options Additional options
     * @return array [success, message, credentials]
     */
    public static function createSchoolDatabaseOptimized($dbName, $options = []) {
        $response = [
            'success' => false,
            'message' => '',
            'database' => $dbName,
            'username' => '',
            'password' => ''
        ];
        
        try {
            self::logQuery('OPTIMIZED_DB_CREATE_START', $dbName, 0);
            
            // Load root credentials (store these securely!)
            $rootCredentials = self::getRootDBCredentials();
            
            if (!$rootCredentials) {
                throw new Exception("Root database credentials not configured");
            }
            
            // Generate database user credentials
            $dbUser = self::generateDbUsername($dbName);
            $dbPass = self::generateStrongPassword();
            
            // Connect with root privileges (no database selected yet)
            $rootDsn = "mysql:host=" . $rootCredentials['host'] . 
                      ";port=" . ($rootCredentials['port'] ?? 3306) . 
                      ";charset=" . DB_CHARSET;
            
            $rootOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $rootConn = new PDO($rootDsn, 
                $rootCredentials['username'], 
                $rootCredentials['password'], 
                $rootOptions
            );
            
            // Start transaction for atomic operations
            $rootConn->beginTransaction();
            
            try {
                // 1. Create database with proper charset
                $charset = $options['charset'] ?? DB_CHARSET;
                $collation = $options['collation'] ?? DB_COLLATION;
                
                $sql = "CREATE DATABASE IF NOT EXISTS `$dbName` 
                        CHARACTER SET $charset 
                        COLLATE $collation";
                
                $rootConn->exec($sql);
                self::logQuery('CREATE_DATABASE', $dbName, 0);
                
                // 2. Create dedicated database user
                $createUserSql = "CREATE USER IF NOT EXISTS ?@'localhost' 
                                 IDENTIFIED BY ?";
                $userStmt = $rootConn->prepare($createUserSql);
                $userStmt->execute([$dbUser, $dbPass]);
                self::logQuery('CREATE_USER', $dbUser, 0);
                
                // 3. Grant ALL privileges on this specific database
                $grantSql = "GRANT ALL PRIVILEGES ON `$dbName`.* 
                             TO ?@'localhost' 
                             WITH GRANT OPTION";
                $grantStmt = $rootConn->prepare($grantSql);
                $grantStmt->execute([$dbUser]);
                self::logQuery('GRANT_PRIVILEGES', "User: $dbUser, DB: $dbName", 0);
                
                // 4. Flush privileges to apply changes
                $rootConn->exec("FLUSH PRIVILEGES");
                self::logQuery('FLUSH_PRIVILEGES', '', 0);
                
                // Commit transaction
                $rootConn->commit();
                
                // Close root connection
                $rootConn = null;
                
                // 5. Test the new user connection
                $testResult = self::testSchoolConnection($dbName, $dbUser, $dbPass);
                
                if (!$testResult['success']) {
                    throw new Exception("Failed to verify database creation: " . $testResult['message']);
                }
                
                // 6. Store credentials securely
                $stored = self::storeSchoolCredentials($dbName, $dbUser, $dbPass);
                
                if (!$stored) {
                    // Log warning but continue (credentials are still usable)
                    self::logQuery('CREDENTIAL_STORE_WARNING', "Failed to store credentials for $dbName", 0);
                }
                
                // 7. Optionally import template schema
                if (!empty($options['template_sql'])) {
                    $imported = self::importSchemaToDatabase($dbName, $dbUser, $dbPass, $options['template_sql']);
                    if (!$imported) {
                        throw new Exception("Failed to import template schema");
                    }
                }
                
                $response['success'] = true;
                $response['message'] = 'Database created successfully with dedicated user';
                $response['username'] = $dbUser;
                $response['password'] = $dbPass;
                $response['table_count'] = $testResult['table_count'] ?? 0;
                
                self::logQuery('OPTIMIZED_DB_CREATE_SUCCESS', $dbName, 0);
                
            } catch (Exception $e) {
                // Rollback on error
                if ($rootConn && $rootConn->inTransaction()) {
                    $rootConn->rollBack();
                }
                
                // Cleanup: Drop user and database if created
                self::cleanupFailedDatabase($dbName, $dbUser);
                
                throw $e;
            }
            
        } catch (Exception $e) {
            $response['message'] = "Failed to create database: " . $e->getMessage();
            self::logQuery('OPTIMIZED_DB_CREATE_ERROR', $dbName . " - " . $e->getMessage(), 0);
        }
        
        return $response;
    }
    
    /**
     * Get root database credentials from secure config
     * @return array|null
     */
    private static function getRootDBCredentials() {
        // Method 1: Check for dedicated config file
        $rootConfigFile = __DIR__ . '/../config/database_root.php';
        if (file_exists($rootConfigFile)) {
            require_once $rootConfigFile;
            
            if (defined('ROOT_DB_HOST') && defined('ROOT_DB_USER') && defined('ROOT_DB_PASS')) {
                return [
                    'host' => ROOT_DB_HOST,
                    'port' => defined('ROOT_DB_PORT') ? ROOT_DB_PORT : 3306,
                    'username' => ROOT_DB_USER,
                    'password' => ROOT_DB_PASS
                ];
            }
        }
        
        // Method 2: Check environment variables
        $host = getenv('MYSQL_ROOT_HOST') ?: getenv('MYSQL_HOST') ?: DB_HOST;
        $user = getenv('MYSQL_ROOT_USER') ?: getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_ROOT_PASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';
        
        if (!empty($host) && !empty($user)) {
            return [
                'host' => $host,
                'port' => 3306,
                'username' => $user,
                'password' => $pass
            ];
        }
        
        // Method 3: Fallback to regular credentials (may not have CREATE DATABASE privileges)
        return [
            'host' => DB_HOST,
            'port' => DB_PORT,
            'username' => DB_USER,
            'password' => DB_PASS
        ];
    }
    
    /**
     * Generate database username from database name
     * @param string $dbName
     * @return string
     */
    private static function generateDbUsername($dbName) {
        // Convert school_123 to school_123_user
        $username = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbName) . '_user';
        
        // MySQL username max length is 32 characters
        if (strlen($username) > 32) {
            $username = substr($username, 0, 32);
        }
        
        return $username;
    }
    
    /**
     * Generate strong password
     * @param int $length
     * @return string
     */
    private static function generateStrongPassword($length = 16) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+';
        $password = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, $max)];
        }
        
        return $password;
    }
    
    /**
     * Test connection to school database with given credentials
     * @param string $dbName
     * @param string $username
     * @param string $password
     * @return array
     */
    private static function testSchoolConnection($dbName, $username, $password) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                   ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $testConn = new PDO($dsn, $username, $password, $options);
            
            // Test query: get table count
            $stmt = $testConn->query("SELECT COUNT(*) as table_count 
                                     FROM information_schema.tables 
                                     WHERE table_schema = DATABASE()");
            $result = $stmt->fetch();
            
            $testConn = null;
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'table_count' => (int)$result['table_count']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Store school database credentials securely
     * @param string $dbName
     * @param string $username
     * @param string $password
     * @return bool
     */
    private static function storeSchoolCredentials($dbName, $username, $password) {
        try {
            // Get encryption key (should be defined in your config)
            $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 
                           (getenv('ENCRYPTION_KEY') ?: 'default-encryption-key-change-me');
            
            // Generate IV
            $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
            
            // Encrypt password
            $encryptedPassword = openssl_encrypt($password, 'aes-256-cbc', $encryptionKey, 0, $iv);
            $ivHex = bin2hex($iv);
            
            // Store in platform database
            $platformDb = self::getPlatformConnection();
            
            // Create credentials table if it doesn't exist
            $platformDb->exec("
                CREATE TABLE IF NOT EXISTS school_database_credentials (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    database_name VARCHAR(100) NOT NULL,
                    username VARCHAR(100) NOT NULL,
                    encrypted_password TEXT NOT NULL,
                    encryption_iv VARCHAR(64) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (database_name),
                    KEY idx_database (database_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Insert or update credentials
            $stmt = $platformDb->prepare("
                INSERT INTO school_database_credentials 
                (database_name, username, encrypted_password, encryption_iv) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                username = VALUES(username),
                encrypted_password = VALUES(encrypted_password),
                encryption_iv = VALUES(encryption_iv),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([$dbName, $username, $encryptedPassword, $ivHex]);
            
            self::logQuery('STORE_CREDENTIALS', "Stored credentials for $dbName", 0);
            return true;
            
        } catch (Exception $e) {
            self::logQuery('STORE_CREDENTIALS_ERROR', $e->getMessage(), 0);
            return false;
        }
    }
    
    /**
     * Get stored credentials for a school database
     * @param string $dbName
     * @return array|null
     */
    public static function getSchoolCredentials($dbName) {
        try {
            $platformDb = self::getPlatformConnection();
            
            $stmt = $platformDb->prepare("
                SELECT username, encrypted_password, encryption_iv 
                FROM school_database_credentials 
                WHERE database_name = ? 
                LIMIT 1
            ");
            
            $stmt->execute([$dbName]);
            $creds = $stmt->fetch();
            
            if (!$creds) {
                return null;
            }
            
            // Decrypt password
            $encryptionKey = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : 
                           (getenv('ENCRYPTION_KEY') ?: 'default-encryption-key-change-me');
            
            $iv = hex2bin($creds['encryption_iv']);
            $password = openssl_decrypt(
                $creds['encrypted_password'], 
                'aes-256-cbc', 
                $encryptionKey, 
                0, 
                $iv
            );
            
            return [
                'username' => $creds['username'],
                'password' => $password
            ];
            
        } catch (Exception $e) {
            self::logQuery('GET_CREDENTIALS_ERROR', $e->getMessage(), 0);
            return null;
        }
    }
    
    /**
     * Import schema to newly created database
     * @param string $dbName
     * @param string $username
     * @param string $password
     * @param string $templateSql
     * @return bool
     */
    private static function importSchemaToDatabase($dbName, $username, $password, $templateSql) {
        try {
            // Connect to the new database with the dedicated user
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                   ";dbname=" . $dbName . ";charset=" . DB_CHARSET;
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            
            $schoolDb = new PDO($dsn, $username, $password, $options);
            
            // Import schema
            $queries = self::splitSQL($templateSql);
            foreach ($queries as $query) {
                if (trim($query) !== '') {
                    $schoolDb->exec($query);
                }
            }
            
            $schoolDb = null;
            
            self::logQuery('IMPORT_SCHEMA', "Imported schema to $dbName", 0);
            return true;
            
        } catch (Exception $e) {
            self::logQuery('IMPORT_SCHEMA_ERROR', $dbName . " - " . $e->getMessage(), 0);
            return false;
        }
    }
    
    /**
     * Cleanup failed database creation
     * @param string $dbName
     * @param string $dbUser
     */
    private static function cleanupFailedDatabase($dbName, $dbUser) {
        try {
            $rootCredentials = self::getRootDBCredentials();
            if (!$rootCredentials) {
                return;
            }
            
            $rootDsn = "mysql:host=" . $rootCredentials['host'] . 
                      ";port=" . ($rootCredentials['port'] ?? 3306) . 
                      ";charset=" . DB_CHARSET;
            
            $rootOptions = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];
            
            $cleanupConn = new PDO($rootDsn, 
                $rootCredentials['username'], 
                $rootCredentials['password'], 
                $rootOptions
            );
            
            // Drop database if exists
            $cleanupConn->exec("DROP DATABASE IF EXISTS `$dbName`");
            
            // Drop user if exists
            $cleanupConn->exec("DROP USER IF EXISTS '$dbUser'@'localhost'");
            
            // Flush privileges
            $cleanupConn->exec("FLUSH PRIVILEGES");
            
            $cleanupConn = null;
            
            self::logQuery('CLEANUP_DATABASE', "Cleaned up $dbName and user $dbUser", 0);
            
        } catch (Exception $e) {
            // Silently fail cleanup - it's just cleanup
            self::logQuery('CLEANUP_ERROR', $e->getMessage(), 0);
        }
    }
    
    /**
     * Enhanced school connection with stored credentials
     * @param string $schoolDb
     * @return PDO
     */
    public static function getSchoolConnectionEnhanced($schoolDb) {
        // First try to use stored credentials
        $creds = self::getSchoolCredentials($schoolDb);
        
        if ($creds) {
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . 
                       ";dbname=" . $schoolDb . ";charset=" . DB_CHARSET;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ];
                
                $connection = new PDO($dsn, $creds['username'], $creds['password'], $options);
                $connection->exec("SET time_zone = '+01:00'");
                
                self::logQuery('CONNECTION_ENHANCED', "Connected to $schoolDb with stored credentials", 0);
                
                // Cache the connection
                self::$schoolConnections[$schoolDb] = $connection;
                
                return $connection;
                
            } catch (Exception $e) {
                self::logQuery('CONNECTION_ENHANCED_ERROR', 
                    "Failed with stored creds: " . $e->getMessage(), 0);
                // Fall through to regular connection
            }
        }
        
        // Fallback to regular connection
        return self::getSchoolConnection($schoolDb);
    }
    
    /**
     * Get database size in MB
     * @param string $database
     * @return float
     */
    public static function getDatabaseSize($database) {
        try {
            $platformDb = self::getPlatformConnection();
            
            $sql = "SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                    FROM information_schema.tables 
                    WHERE table_schema = ?";
            
            $stmt = $platformDb->prepare($sql);
            $stmt->execute([$database]);
            $result = $stmt->fetch();
            
            return (float)($result['size_mb'] ?? 0);
            
        } catch (Exception $e) {
            self::logQuery('GET_DB_SIZE_ERROR', $e->getMessage(), 0);
            return 0;
        }
    }
    
    /**
     * Optimize all tables in a database
     * @param string $database
     * @return bool
     */
    public static function optimizeDatabase($database) {
        try {
            $dbConn = self::getSchoolConnection($database);
            
            // Get all tables
            $tables = $dbConn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $dbConn->exec("OPTIMIZE TABLE `$table`");
            }
            
            self::logQuery('OPTIMIZE_DATABASE', "Optimized $database with " . count($tables) . " tables", 0);
            return true;
            
        } catch (Exception $e) {
            self::logQuery('OPTIMIZE_DATABASE_ERROR', $e->getMessage(), 0);
            return false;
        }
    }
    
    /**
     * Create a limited-privilege database user (alternative to root)
     * @param string $username
     * @param string $password
     * @param array $privileges
     * @return bool
     */
    public static function createLimitedUser($username, $password, $privileges = []) {
        try {
            $rootCredentials = self::getRootDBCredentials();
            if (!$rootCredentials) {
                return false;
            }
            
            $rootDsn = "mysql:host=" . $rootCredentials['host'] . 
                      ";port=" . ($rootCredentials['port'] ?? 3306) . 
                      ";charset=" . DB_CHARSET;
            
            $rootConn = new PDO($rootDsn, 
                $rootCredentials['username'], 
                $rootCredentials['password']
            );
            
            // Create user
            $rootConn->exec("CREATE USER IF NOT EXISTS '$username'@'localhost' 
                            IDENTIFIED BY '$password'");
            
            // Grant privileges
            $privList = !empty($privileges) ? implode(', ', $privileges) : 'ALL PRIVILEGES';
            $rootConn->exec("GRANT $privList ON *.* TO '$username'@'localhost'");
            
            $rootConn->exec("FLUSH PRIVILEGES");
            $rootConn = null;
            
            return true;
            
        } catch (Exception $e) {
            self::logQuery('CREATE_LIMITED_USER_ERROR', $e->getMessage(), 0);
            return false;
        }
    }

    /**
     * Insert record and return last insert ID
     * @param PDO $connection
     * @param string $table
     * @param array $data
     * @return int
     */
    public static function insert($connection, $table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        $stmt = self::executeQuery($connection, $sql, $values);
        
        return $connection->lastInsertId();
    }
    
    /**
     * Update record
     * @param PDO $connection
     * @param string $table
     * @param array $data
     * @param string $where
     * @param array $whereParams
     * @return int Affected rows
     */
    public static function update($connection, $table, $data, $where, $whereParams = []) {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "$column = ?";
            $values[] = $value;
        }
        
        $setClause = implode(', ', $setParts);
        $sql = "UPDATE $table SET $setClause WHERE $where";
        
        // Merge values with where params
        $allParams = array_merge($values, $whereParams);
        
        $stmt = self::executeQuery($connection, $sql, $allParams);
        return $stmt->rowCount();
    }
    
    /**
     * Delete record
     * @param PDO $connection
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int Affected rows
     */
    public static function delete($connection, $table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = self::executeQuery($connection, $sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Select records
     * @param PDO $connection
     * @param string $table
     * @param string $columns
     * @param string $where
     * @param array $params
     * @param string $orderBy
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function select($connection, $table, $columns = '*', $where = '1', 
                                 $params = [], $orderBy = '', $limit = 0, $offset = 0) {
        $sql = "SELECT $columns FROM $table WHERE $where";
        
        if (!empty($orderBy)) {
            $sql .= " ORDER BY $orderBy";
        }
        
        if ($limit > 0) {
            $sql .= " LIMIT $limit";
            if ($offset > 0) {
                $sql .= " OFFSET $offset";
            }
        }
        
        $stmt = self::executeQuery($connection, $sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Count records
     * @param PDO $connection
     * @param string $table
     * @param string $where
     * @param array $params
     * @return int
     */
    public static function count($connection, $table, $where = '1', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM $table WHERE $where";
        $stmt = self::executeQuery($connection, $sql, $params);
        $result = $stmt->fetch();
        return (int)$result['count'];
    }
    
    /**
     * Backup database
     * @param string $database
     * @param string $backupPath
     * @return bool
     */
    public static function backupDatabase($database, $backupPath = null) {
        if ($backupPath === null) {
            $backupPath = BACKUP_DIR . '/' . $database . '_' . date('Y-m-d_H-i-s') . '.sql';
        }
        
        // Ensure backup directory exists
        if (!is_dir(dirname($backupPath))) {
            mkdir(dirname($backupPath), 0755, true);
        }
        
        $command = "mysqldump --host=" . DB_HOST . 
                   " --user=" . DB_USER . 
                   " --password=" . DB_PASS . 
                   " $database > $backupPath 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            self::logQuery('BACKUP', "Database $database backed up to $backupPath", 0);
            return true;
        } else {
            self::logQuery('BACKUP ERROR', "Failed to backup $database: " . implode("\n", $output), 0);
            return false;
        }
    }
    
    /**
     * Restore database from backup
     * @param string $database
     * @param string $backupFile
     * @return bool
     */
    public static function restoreDatabase($database, $backupFile) {
        if (!file_exists($backupFile)) {
            throw new Exception("Backup file not found: $backupFile");
        }
        
        $command = "mysql --host=" . DB_HOST . 
                   " --user=" . DB_USER . 
                   " --password=" . DB_PASS . 
                   " $database < $backupFile 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            self::logQuery('RESTORE', "Database $database restored from $backupFile", 0);
            return true;
        } else {
            self::logQuery('RESTORE ERROR', "Failed to restore $database: " . implode("\n", $output), 0);
            return false;
        }
    }
    
    /**
     * Split SQL string into individual queries
     * @param string $sql
     * @return array
     */
    private static function splitSQL($sql) {
        $queries = [];
        $currentQuery = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($sql); $i++) {
            $char = $sql[$i];
            
            // Handle string literals
            if (($char == "'" || $char == '"') && ($i == 0 || $sql[$i-1] != '\\')) {
                if (!$inString) {
                    $inString = true;
                    $stringChar = $char;
                } elseif ($char == $stringChar) {
                    $inString = false;
                }
            }
            
            // Add character to current query
            $currentQuery .= $char;
            
            // Check for end of query (semicolon not in string)
            if ($char == ';' && !$inString) {
                $queries[] = $currentQuery;
                $currentQuery = '';
            }
        }
        
        // Add any remaining query
        if (trim($currentQuery) !== '') {
            $queries[] = $currentQuery;
        }
        
        return $queries;
    }
    
    /**
     * Log query for debugging
     * @param string $sql
     * @param mixed $params
     * @param float $executionTime
     */
    private static function logQuery($sql, $params, $executionTime) {
        if (!DB_LOG_QUERIES) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'sql' => $sql,
            'params' => is_array($params) ? json_encode($params) : $params,
            'time' => round($executionTime * 1000, 2) . 'ms'
        ];
        
        self::$queryLog[] = $logEntry;
        
        // Write to log file if enabled
        if (defined('DB_LOG_FILE')) {
            $logMessage = "[" . $logEntry['timestamp'] . "] " .
                         $logEntry['sql'] . " | " .
                         $logEntry['params'] . " | " .
                         $logEntry['time'] . PHP_EOL;
            
            file_put_contents(DB_LOG_FILE, $logMessage, FILE_APPEND);
        }
    }
    
    /**
     * Get query log
     * @return array
     */
    public static function getQueryLog() {
        return self::$queryLog;
    }
    
    /**
     * Clear query log
     */
    public static function clearQueryLog() {
        self::$queryLog = [];
    }
    
    /**
     * Close all database connections
     */
    public static function closeAllConnections() {
        self::$platformConnection = null;
        self::$schoolConnections = [];
    }
}
?>