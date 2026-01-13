<?php
/**
 * Setup Script for Tenant System
 * Run this once to initialize the system
 */

echo "<h1>Tenant System Setup</h1>";
echo "<p>Checking and creating required directories...</p>";

// Required directories
$directories = [
    __DIR__,
    __DIR__ . '/../logs',
    __DIR__ . '/../cache',
    __DIR__ . '/../uploads'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0755, true)) {
            echo "<p style='color: green;'>✓ Created: {$dir}</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to create: {$dir}</p>";
        }
    } else {
        echo "<p>✓ Directory exists: {$dir}</p>";
    }
    
    // Check permissions
    if (is_writable($dir)) {
        echo "<p style='color: green;'>✓ Writable: {$dir}</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Not writable: {$dir}</p>";
        @chmod($dir, 0755);
    }
}

// Create test school folder
$testSchool = 'test-school-' . time();
$testDir = __DIR__ . '/' . $testSchool;
if (mkdir($testDir, 0755, true)) {
    echo "<p style='color: green;'>✓ Test school folder created: {$testDir}</p>";
    
    // Create test files
    file_put_contents($testDir . '/index.php', '<?php echo "Test School"; ?>');
    file_put_contents($testDir . '/config.php', '<?php // Test config ?>');
    
    // Clean up
    unlink($testDir . '/index.php');
    unlink($testDir . '/config.php');
    rmdir($testDir);
    echo "<p style='color: green;'>✓ Test cleanup successful</p>";
} else {
    echo "<p style='color: red;'>✗ Failed to create test school folder</p>";
}

echo "<hr>";
echo "<h2>System Check Complete</h2>";
echo "<p><a href='login.php'>Go to Login</a></p>";
echo "<p><a href='debug.php'>Go to Debug</a></p>";
?>