<?php
/**
 * Base Cron Task Class
 * Abstract class that all cron tasks should extend
 */

require_once __DIR__ . '/CronLogger.php';

abstract class CronTask {
    protected $db;
    protected $logger;
    protected $taskName;
    protected $startTime;
    protected $startMemory;
    
    public function __construct($db, $taskName) {
        $this->db = $db;
        $this->taskName = $taskName;
        $this->logger = new CronLogger($taskName, $db);
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }
    
    /**
     * Main execution method - must be implemented by child classes
     */
    abstract public function execute();
    
    /**
     * Get task name
     */
    public function getTaskName() {
        return $this->taskName;
    }
    
    /**
     * Run the task with error handling and logging
     */
    public function run() {
        try {
            $this->logger->logTaskStart();
            
            // Execute the task
            $result = $this->execute();
            
            // Calculate execution metrics
            $executionTime = microtime(true) - $this->startTime;
            $memoryUsed = memory_get_usage(true) - $this->startMemory;
            
            // Log completion
            $this->logger->logTaskComplete($executionTime, $memoryUsed);
            
            // Update cron_logs table with completion status
            $this->updateCronLog('completed', 'Task completed successfully', $executionTime, $memoryUsed);
            
            return true;
            
        } catch (Exception $e) {
            $executionTime = microtime(true) - $this->startTime;
            
            // Log failure
            $this->logger->logTaskFailure($e, $executionTime);
            
            // Update cron_logs table with failure status
            $this->updateCronLog('failed', $e->getMessage(), $executionTime, 0);
            
            return false;
        }
    }
    
    /**
     * Update cron_logs table
     */
    protected function updateCronLog($status, $message, $executionTime, $memoryUsage) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cron_logs (task_name, status, message, execution_time, memory_usage, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->taskName,
                $status,
                $message,
                round($executionTime, 2),
                round($memoryUsage / 1024 / 1024, 2) // Convert to MB
            ]);
            
        } catch (Exception $e) {
            // If this fails, it's already logged by the logger
        }
    }
    
    /**
     * Helper method to get database connection for a specific school
     */
    protected function getSchoolConnection($schoolId) {
        try {
            // Get school database name
            $stmt = $this->db->prepare("SELECT database_name FROM schools WHERE id = ?");
            $stmt->execute([$schoolId]);
            $school = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$school || !$school['database_name']) {
                throw new Exception("School database not found for school ID: $schoolId");
            }
            
            // Create connection to school database
            $schoolDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $school['database_name'] . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            return $schoolDb;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to connect to school database", [
                'school_id' => $schoolId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Helper method to send email
     */
    protected function sendEmail($to, $subject, $body, $schoolId = null) {
        try {
            // Load and use EmailService
            require_once __DIR__ . '/../../includes/Services/EmailService.php';
            require_once __DIR__ . '/../../includes/Services/EmailTemplate.php';
            require_once __DIR__ . '/../../includes/Helper/EnvHelper.php';
            
            // Ensure environment is loaded if not already
            if (!function_exists('env')) {
                \AcademixSuite\Helpers\EnvHelper::load();
            }
            
            $emailService = new EmailService();
            $result = $emailService->sendEmail($to, $subject, $body);
            
            if ($result['success']) {
                $this->logger->info("Email sent successfully", [
                    'to' => $to,
                    'subject' => $subject,
                    'method' => $result['method'] ?? 'unknown'
                ]);
                
                // Log to email_logs table
                $this->logEmail($to, $subject, 'sent', $schoolId);
                return true;
            } else {
                throw new Exception($result['error'] ?? "Failed to send email");
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to send email", [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            
            // Log to email_logs table
            $this->logEmail($to, $subject, 'failed', $schoolId, $e->getMessage());
            
            return false;
        }
    }
    
    /**
     * Log email to database
     */
    protected function logEmail($to, $subject, $status, $schoolId = null, $errorMessage = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_logs (school_id, `to`, subject, status, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([$schoolId, $to, $subject, $status, $errorMessage]);
            
        } catch (Exception $e) {
            // Silently fail - don't want to break the task
        }
    }
    
    /**
     * Helper method to format date/time
     */
    protected function formatDateTime($datetime) {
        return date('Y-m-d H:i:s', strtotime($datetime));
    }
    
    /**
     * Helper method to check if running in CLI mode
     */
    protected function isCli() {
        return php_sapi_name() === 'cli';
    }
}
