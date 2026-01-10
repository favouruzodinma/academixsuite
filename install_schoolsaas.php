<?php
// install_schoolsaas.php
// Run this script to install SchoolSaaS on XAMPP

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>SchoolSaaS Installation for XAMPP</h2>";

$host = 'localhost';
$user = 'root';
$pass = '';
$platform_db = 'school_platform';

try {
    // Connect to MySQL
    $pdo = new PDO("mysql:host=$host", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL successfully!<br>";
    
    // Create platform database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$platform_db` 
                CHARACTER SET utf8mb4 
                COLLATE utf8mb4_unicode_ci");
    
    echo "Database '$platform_db' created or already exists<br>";
    
    // Select platform database
    $pdo->exec("USE `$platform_db`");
    
    // Read and execute platform SQL
    $platform_sql = file_get_contents('school_platform_fixed.sql');
    
    // Remove the CREATE DATABASE and USE statements from the SQL file
    $platform_sql = preg_replace('/CREATE DATABASE.*?;/i', '', $platform_sql);
    $platform_sql = preg_replace('/USE.*?;/i', '', $platform_sql);
    
    // Execute SQL in chunks
    $queries = explode(';', $platform_sql);
    $success_count = 0;
    $error_count = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            try {
                $pdo->exec($query);
                $success_count++;
            } catch (PDOException $e) {
                $error_count++;
                echo "Error executing query: " . $e->getMessage() . "<br>";
            }
        }
    }
    
    echo "Platform database installation complete!<br>";
    echo "Successfully executed: $success_count queries<br>";
    echo "Errors: $error_count<br><br>";
    
    // Create a test school database
    $school_db = 'school_1';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$school_db` 
                CHARACTER SET utf8mb4 
                COLLATE utf8mb4_unicode_ci");
    
    echo "Test school database '$school_db' created<br>";
    
    // Select school database
    $pdo->exec("USE `$school_db`");
    
    // Read and execute school template SQL
    $template_sql = file_get_contents('school_template_fixed.sql');
    $queries = explode(';', $template_sql);
    $success_count = 0;
    
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            try {
                $pdo->exec($query);
                $success_count++;
            } catch (PDOException $e) {
                // Skip duplicate key errors
                if (strpos($e->getMessage(), 'Duplicate key') === false) {
                    echo "Error: " . $e->getMessage() . "<br>";
                }
            }
        }
    }
    
    echo "School template installed with $success_count queries<br>";
    
    // Insert test data
    $pdo->exec("
        INSERT INTO `users` (`school_id`, `name`, `email`, `password`, `user_type`, `is_active`) 
        VALUES (1, 'Test Admin', 'admin@testschool.com', 
                '\$2y\$12\$ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456', 
                'admin', 1)
    ");
    
    // Back to platform database
    $pdo->exec("USE `$platform_db`");
    
    // Insert test school
    $pdo->exec("
        INSERT INTO `schools` (`uuid`, `name`, `slug`, `email`, `database_name`, `status`) 
        VALUES ('test_uuid_123', 'Test School', 'testschool', 
                'admin@testschool.com', 'school_1', 'active')
    ");
    
    echo "<h3 style='color:green;'>Installation Successful!</h3>";
    echo "<p>Login credentials:</p>";
    echo "<ul>";
    echo "<li><strong>Super Admin:</strong> admin@schoolsaas.com / admin123</li>";
    echo "<li><strong>School Admin:</strong> admin@testschool.com / admin123</li>";
    echo "</ul>";
    echo "<p>Change these passwords immediately!</p>";
    
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>