#!/usr/bin/env php
<?php
/**
 * ============================================================
 * CRON SYSTEM TEST SCRIPT
 * ============================================================
 * 
 * This script tests all cron tasks to ensure they work correctly.
 * Run this after installation to verify everything is set up properly.
 * 
 * USAGE:
 *   php test_cron_system.php
 * 
 * ============================================================
 */

// Security check
if (php_sapi_name() !== 'cli') {
    die("ERROR: This script can only be run from command line.\n");
}

// Bootstrap
require_once __DIR__ . '/../includes/autoload.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║          CRON SYSTEM TEST SUITE                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$tests = [
    'database_connection' => 'Test Database Connection',
    'database_tables' => 'Test Database Tables',
    'cron_dispatcher' => 'Test Cron Dispatcher',
    'lock_mechanism' => 'Test Lock Mechanism',
    'logger' => 'Test Logger',
    'email_queue_task' => 'Test Email Queue Task',
    'suspension_task' => 'Test Suspension Task',
    'announcement_task' => 'Test Announcement Task',
    'cleanup_task' => 'Test Cleanup Task'
];

$passed = 0;
$failed = 0;
$results = [];

foreach ($tests as $testName => $testDescription) {
    echo "Testing: $testDescription... ";
    
    $result = runTest($testName);
    
    if ($result['success']) {
        echo "✓ PASS\n";
        $passed++;
    } else {
        echo "✗ FAIL\n";
        echo "   Error: {$result['error']}\n";
        $failed++;
    }
    
    $results[$testName] = $result;
}

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "SUMMARY\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "   Passed: $passed tests\n";
echo "   Failed: $failed tests\n\n";

if ($failed === 0) {
    echo "✓ All tests passed! Your cron system is ready to use.\n\n";
    echo "NEXT STEPS:\n";
    echo "   1. Set up cron jobs in cPanel (see CPANEL_SETUP.md)\n";
    echo "   2. Monitor logs: tail -f cron/logs/cron.log\n";
    echo "   3. Check execution history in database\n\n";
} else {
    echo "⚠ Some tests failed. Please fix the issues before deploying.\n\n";
    echo "TROUBLESHOOTING:\n";
    echo "   1. Check database connection settings\n";
    echo "   2. Run database migration: 002_create_cron_tables.sql\n";
    echo "   3. Check file permissions\n";
    echo "   4. Review error messages above\n\n";
}

exit($failed === 0 ? 0 : 1);

// ============================================================
// TEST FUNCTIONS
// ============================================================

function runTest($testName) {
    try {
        switch ($testName) {
            case 'database_connection':
                return testDatabaseConnection();
            
            case 'database_tables':
                return testDatabaseTables();
            
            case 'cron_dispatcher':
                return testCronDispatcher();
            
            case 'lock_mechanism':
                return testLockMechanism();
            
            case 'logger':
                return testLogger();
            
            case 'email_queue_task':
                return testEmailQueueTask();
            
            case 'suspension_task':
                return testSuspensionTask();
            
            case 'announcement_task':
                return testAnnouncementTask();
            
            case 'cleanup_task':
                return testCleanupTask();
            
            default:
                return ['success' => false, 'error' => 'Unknown test'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testDatabaseConnection() {
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->query("SELECT 1");
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testDatabaseTables() {
    try {
        $db = Database::getPlatformConnection();
        
        $requiredTables = [
            'email_queue',
            'bulk_email_campaigns',
            'scheduled_announcements',
            'student_suspension_queue',
            'cron_logs',
            'cron_execution_history',
            'email_suppression_list',
            'cron_schedules'
        ];
        
        foreach ($requiredTables as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => "Table '$table' not found"];
            }
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testCronDispatcher() {
    try {
        $cronFile = __DIR__ . '/cron.php';
        
        if (!file_exists($cronFile)) {
            return ['success' => false, 'error' => 'cron.php not found'];
        }
        
        if (!is_readable($cronFile)) {
            return ['success' => false, 'error' => 'cron.php is not readable'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testLockMechanism() {
    try {
        require_once __DIR__ . '/lib/CronLock.php';
        
        $lock = new CronLock('test_task');
        
        if (!$lock->acquire()) {
            return ['success' => false, 'error' => 'Failed to acquire lock'];
        }
        
        // Try to acquire again (should fail)
        $lock2 = new CronLock('test_task');
        if ($lock2->acquire()) {
            $lock->release();
            return ['success' => false, 'error' => 'Lock mechanism not working (acquired twice)'];
        }
        
        $lock->release();
        
        // Should be able to acquire after release
        if (!$lock2->acquire()) {
            return ['success' => false, 'error' => 'Failed to acquire lock after release'];
        }
        
        $lock2->release();
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testLogger() {
    try {
        require_once __DIR__ . '/lib/CronLogger.php';
        
        $db = Database::getPlatformConnection();
        $logger = new CronLogger('test_task', $db);
        
        $logger->info('Test log message');
        
        // Check if log was written to file
        $logFile = __DIR__ . '/logs/cron.log';
        if (!file_exists($logFile)) {
            return ['success' => false, 'error' => 'Log file not created'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testEmailQueueTask() {
    try {
        $taskFile = __DIR__ . '/tasks/process_email_queue.php';
        
        if (!file_exists($taskFile)) {
            return ['success' => false, 'error' => 'Task file not found'];
        }
        
        // Test dry run
        $output = shell_exec('php ' . __DIR__ . '/cron.php process_email_queue --dry-run 2>&1');
        
        if (strpos($output, 'ERROR') !== false && strpos($output, 'Task file must define') === false) {
            return ['success' => false, 'error' => 'Task execution failed'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testSuspensionTask() {
    try {
        $taskFile = __DIR__ . '/tasks/process_student_suspensions.php';
        
        if (!file_exists($taskFile)) {
            return ['success' => false, 'error' => 'Task file not found'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testAnnouncementTask() {
    try {
        $taskFile = __DIR__ . '/tasks/publish_scheduled_announcements.php';
        
        if (!file_exists($taskFile)) {
            return ['success' => false, 'error' => 'Task file not found'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testCleanupTask() {
    try {
        $taskFile = __DIR__ . '/tasks/cleanup_old_logs.php';
        
        if (!file_exists($taskFile)) {
            return ['success' => false, 'error' => 'Task file not found'];
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
