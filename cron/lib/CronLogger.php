<?php
/**
 * Cron Logger
 * Centralized logging system for cron tasks
 */

class CronLogger {
    private $logFile;
    private $dbConnection;
    private $taskName;
    
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_SUCCESS = 'SUCCESS';
    
    public function __construct($taskName, $dbConnection = null) {
        $this->taskName = $taskName;
        $this->dbConnection = $dbConnection;
        
        // Set log file path
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $this->logFile = $logDir . '/cron.log';
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = []) {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = []) {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log success message
     */
    public function success($message, $context = []) {
        $this->log(self::LEVEL_SUCCESS, $message, $context);
    }
    
    /**
     * Main logging method
     */
    private function log($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        
        // Format log entry
        $logEntry = sprintf(
            "[%s] [%s] [%s] %s",
            $timestamp,
            $level,
            $this->taskName,
            $message
        );
        
        // Add context if provided
        if (!empty($context)) {
            $logEntry .= ' | Context: ' . json_encode($context);
        }
        
        $logEntry .= PHP_EOL;
        
        // Write to file
        $this->writeToFile($logEntry);
        
        // Write to database if connection available
        if ($this->dbConnection) {
            $this->writeToDatabase($level, $message, $context);
        }
        
        // Also output to console for CLI visibility
        echo $logEntry;
    }
    
    /**
     * Write log entry to file
     */
    private function writeToFile($logEntry) {
        // Rotate log file if it's too large (> 10MB)
        if (file_exists($this->logFile) && filesize($this->logFile) > 10 * 1024 * 1024) {
            $this->rotateLogFile();
        }
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Write log entry to database
     */
    private function writeToDatabase($level, $message, $context) {
        try {
            $stmt = $this->dbConnection->prepare("
                INSERT INTO cron_logs (task_name, level, message, context, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $contextJson = !empty($context) ? json_encode($context) : null;
            $stmt->execute([$this->taskName, $level, $message, $contextJson]);
            
        } catch (Exception $e) {
            // If database logging fails, just log to file
            $errorEntry = sprintf(
                "[%s] [ERROR] [CronLogger] Failed to write to database: %s%s",
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                PHP_EOL
            );
            file_put_contents($this->logFile, $errorEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Rotate log file
     */
    private function rotateLogFile() {
        $rotatedFile = $this->logFile . '.' . date('Y-m-d_His');
        rename($this->logFile, $rotatedFile);
        
        // Keep only last 5 rotated files
        $this->cleanOldLogFiles();
    }
    
    /**
     * Clean old log files
     */
    private function cleanOldLogFiles() {
        $logDir = dirname($this->logFile);
        $logFiles = glob($logDir . '/cron.log.*');
        
        // Sort by modification time (newest first)
        usort($logFiles, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Keep only 5 most recent, delete the rest
        $filesToDelete = array_slice($logFiles, 5);
        foreach ($filesToDelete as $file) {
            unlink($file);
        }
    }
    
    /**
     * Log task start
     */
    public function logTaskStart() {
        $this->info("Task started", [
            'pid' => getmypid(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time')
        ]);
    }
    
    /**
     * Log task completion
     */
    public function logTaskComplete($executionTime, $memoryUsage) {
        $this->success("Task completed successfully", [
            'execution_time' => round($executionTime, 2) . 's',
            'memory_used' => round($memoryUsage / 1024 / 1024, 2) . 'MB',
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'MB'
        ]);
    }
    
    /**
     * Log task failure
     */
    public function logTaskFailure($exception, $executionTime) {
        $this->error("Task failed", [
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'execution_time' => round($executionTime, 2) . 's'
        ]);
    }
}
