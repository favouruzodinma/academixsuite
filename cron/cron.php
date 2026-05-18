#!/usr/bin/env php
<?php
/**
 * ============================================================
 * CENTRAL CRON JOB DISPATCHER
 * ============================================================
 * 
 * This is the main entry point for all cron jobs.
 * It handles task routing, locking, logging, and error handling.
 * 
 * SECURITY:
 * - This file should ONLY be accessible via CLI (not web browser)
 * - Place .htaccess rules to block web access to /cron directory
 * 
 * USAGE:
 *   php /path/to/cron.php <task_name> [options]
 * 
 * EXAMPLES:
 *   php cron.php process_email_queue
 *   php cron.php process_email_queue --limit=50
 *   php cron.php process_school_trials --dry-run
 *   php cron.php process_student_suspensions --dry-run
 * 
 * CPANEL CRON EXAMPLES:
 *   Every 5 minutes: /usr/bin/php /home/username/public_html/cron/cron.php process_email_queue
 *   Hourly:          /usr/bin/php /home/username/public_html/cron/cron.php process_school_trials
 *   Hourly:          /usr/bin/php /home/username/public_html/cron/cron.php process_student_suspensions
 * 
 * ============================================================
 */

// ============================================================
// SECURITY CHECK: CLI Only
// ============================================================
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("ERROR: This script can only be run from command line.\n");
}

// ============================================================
// BOOTSTRAP
// ============================================================
define('CRON_START_TIME', microtime(true));
define('CRON_START_MEMORY', memory_get_usage());
define('IS_CRON_CONTEXT', true);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Set timezone
date_default_timezone_set('Africa/Lagos'); // Adjust to your timezone

// Get absolute path to project root
$projectRoot = dirname(__DIR__);

// Load dependencies
require_once $projectRoot . '/includes/autoload.php';
require_once __DIR__ . '/lib/CronLock.php';
require_once __DIR__ . '/lib/CronLogger.php';

// ============================================================
// PARSE COMMAND LINE ARGUMENTS
// ============================================================
$taskName = $argv[1] ?? null;
$options = [];

// Parse additional arguments
for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key = $parts[0];
        $value = $parts[1] ?? true;
        $options[$key] = $value;
    }
}

// ============================================================
// VALIDATE TASK NAME
// ============================================================
if (!$taskName) {
    echo "ERROR: No task specified.\n\n";
    echo "Usage: php cron.php <task_name> [options]\n\n";
    echo "Available tasks:\n";
    echo "  - process_email_queue\n";
    echo "  - process_scheduled_emails\n";
    echo "  - process_school_trials\n";
    echo "  - process_student_suspensions\n";
    echo "  - publish_scheduled_announcements\n";
    echo "  - retry_failed_emails\n";
    echo "  - cleanup_old_logs\n";
    exit(1);
}

// ============================================================
// TASK FILE MAPPING
// ============================================================
$taskFile = __DIR__ . '/tasks/' . $taskName . '.php';

if (!file_exists($taskFile)) {
    echo "ERROR: Task '$taskName' not found.\n";
    echo "Expected file: $taskFile\n";
    exit(1);
}

// ============================================================
// INITIALIZE LOCK AND LOGGER
// ============================================================
$lock = new CronLock($taskName);
$logger = null;
$db = null;

try {
    // Get database connection for logging
    $db = Database::getPlatformConnection();
    $logger = new CronLogger($taskName, $db);
    
} catch (Exception $e) {
    // Fallback to file-only logging if database fails
    $logger = new CronLogger($taskName);
    $logger->warning("Database connection failed, using file-only logging: " . $e->getMessage());
}

// ============================================================
// ACQUIRE LOCK
// ============================================================
if (!$lock->acquire()) {
    $lockInfo = $lock->getLockInfo();
    $message = "Task is already running. Lock info: " . json_encode($lockInfo);
    
    if ($logger) {
        $logger->warning($message);
    } else {
        echo "[WARNING] $message\n";
    }
    
    exit(0); // Exit gracefully (not an error)
}

// ============================================================
// LOG TASK START
// ============================================================
$logger->logTaskStart();
$logger->info("Executing task: $taskName", ['options' => $options]);

// Track execution in database
$executionId = null;
if ($db instanceof PDO) {
    try {
        $stmt = $db->prepare("
            INSERT INTO cron_execution_history 
            (task_name, status, started_at) 
            VALUES (?, 'started', NOW())
        ");
        $stmt->execute([$taskName]);
        $executionId = $db->lastInsertId();
    } catch (Exception $e) {
        $logger->warning("Failed to log execution start: " . $e->getMessage());
    }
} else {
    $logger->warning("Skipping database execution history because platform database is unavailable");
}

// ============================================================
// EXECUTE TASK
// ============================================================
$taskSuccess = false;
$taskError = null;
$taskResult = null;

try {
    // Include and execute the task file
    // The task file should define a function: executeTask($options, $logger)
    require_once $taskFile;
    
    if (!function_exists('executeTask')) {
        throw new Exception("Task file must define an executeTask() function");
    }
    
    // Execute the task
    $taskResult = executeTask($options, $logger);
    $taskSuccess = true;
    
} catch (Throwable $e) {
    $taskSuccess = false;
    $taskError = $e->getMessage();
    $logger->error("Task execution failed: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

// ============================================================
// CALCULATE EXECUTION METRICS
// ============================================================
$executionTime = microtime(true) - CRON_START_TIME;
$memoryUsed = memory_get_usage() - CRON_START_MEMORY;
$memoryPeak = memory_get_peak_usage(true);

// ============================================================
// LOG TASK COMPLETION
// ============================================================
if ($taskSuccess) {
    $logger->logTaskComplete($executionTime, $memoryUsed);
    
    // Update execution history
    if ($executionId && $db instanceof PDO) {
        try {
            $stmt = $db->prepare("
                UPDATE cron_execution_history 
                SET status = 'completed',
                    completed_at = NOW(),
                    execution_time = ?,
                    memory_peak = ?,
                    items_processed = ?,
                    items_succeeded = ?,
                    items_failed = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $executionTime,
                $memoryPeak,
                $taskResult['processed'] ?? 0,
                $taskResult['succeeded'] ?? 0,
                $taskResult['failed'] ?? 0,
                $executionId
            ]);
        } catch (Exception $e) {
            $logger->warning("Failed to update execution history: " . $e->getMessage());
        }
    }
    
} else {
    $logger->logTaskFailure(new Exception($taskError ?? 'Unknown error'), $executionTime);
    
    // Update execution history
    if ($executionId && $db instanceof PDO) {
        try {
            $stmt = $db->prepare("
                UPDATE cron_execution_history 
                SET status = 'failed',
                    completed_at = NOW(),
                    execution_time = ?,
                    memory_peak = ?,
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $executionTime,
                $memoryPeak,
                $taskError,
                $executionId
            ]);
        } catch (Exception $e) {
            $logger->warning("Failed to update execution history: " . $e->getMessage());
        }
    }
}

// ============================================================
// RELEASE LOCK
// ============================================================
$lock->release();

// ============================================================
// EXIT
// ============================================================
exit($taskSuccess ? 0 : 1);
