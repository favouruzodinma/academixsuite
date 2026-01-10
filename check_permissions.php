<?php
// test_direct_database.php
require_once __DIR__ . '/includes/autoload.php';

echo "<h2>Direct Database Creation Test</h2>";
echo "<pre>";

try {
    $dbName = 'school_test_' . time();
    echo "1. Creating database: $dbName\n";
    
    // Create database directly
    $platformDb = Database::getPlatformConnection();
    $platformDb->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✓ Database created\n";
    
    // Connect to new database
    $config = require __DIR__ . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname=$dbName;charset={$config['charset']}";
    $schoolDb = new PDO($dsn, $config['username'], $config['password']);
    $schoolDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "\n2. Creating tables...\n";
    
    // Create users table
    $schoolDb->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT(10) UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            user_type ENUM('admin','teacher','student','parent') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "✓ Created users table\n";
    
    // Create roles table
    $schoolDb->exec("
        CREATE TABLE IF NOT EXISTS roles (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            school_id INT(10) UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            permissions TEXT
        )
    ");
    echo "✓ Created roles table\n";
    
    // Create 5 more tables
    for ($i = 1; $i <= 5; $i++) {
        $tableName = "test_table_$i";
        $schoolDb->exec("
            CREATE TABLE IF NOT EXISTS $tableName (
                id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "✓ Created $tableName\n";
    }
    
    // Check tables
    echo "\n3. Checking tables...\n";
    $stmt = $schoolDb->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Total tables: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
    // Clean up
    echo "\n4. Cleaning up...\n";
    $platformDb->exec("DROP DATABASE IF EXISTS `$dbName`");
    echo "✓ Test database cleaned up\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>