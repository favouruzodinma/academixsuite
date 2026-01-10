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