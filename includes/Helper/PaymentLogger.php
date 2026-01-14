<?php
/**
 * Payment Logger
 * Logs payment-related activities for debugging and auditing
 */
namespace AcademixSuite\Helpers;

class PaymentLogger {
    
    private $logDir;
    private $logLevel;
    
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARN = 'warn';
    const LEVEL_ERROR = 'error';
    
    public function __construct() {
        $this->logDir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../../logs/payments/';
        $this->logLevel = defined('LOG_LEVEL') ? LOG_LEVEL : self::LEVEL_INFO;
        
        // Create log directory if it doesn't exist
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }
    
    /**
     * Log payment initiation
     */
    public function logPaymentInitiation(string $type, int $entityId, string $reference, float $amount): void {
        $this->log(self::LEVEL_INFO, 'payment_initiated', [
            'type' => $type,
            'entity_id' => $entityId,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'NGN',
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    }
    
    /**
     * Log payment success
     */
    public function logPaymentSuccess(string $type, string $reference, float $amount): void {
        $this->log(self::LEVEL_INFO, 'payment_success', [
            'type' => $type,
            'reference' => $reference,
            'amount' => $amount,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log payment failure
     */
    public function logPaymentFailure(string $type, string $reference, string $error): void {
        $this->log(self::LEVEL_ERROR, 'payment_failed', [
            'type' => $type,
            'reference' => $reference,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log refund
     */
    public function logRefund(int $transactionId, float $amount, string $reason): void {
        $this->log(self::LEVEL_INFO, 'refund_processed', [
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'reason' => $reason,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log webhook
     */
    public function logWebhook(string $provider, array $data, array $result): void {
        $this->log(self::LEVEL_DEBUG, 'webhook_received', [
            'provider' => $provider,
            'event' => $data['event'] ?? $data['type'] ?? 'unknown',
            'reference' => $data['data']['reference'] ?? $data['data']['tx_ref'] ?? 'unknown',
            'result' => $result,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log API request
     */
    public function logRequest(string $url, string $method, array $request, $response, int $httpCode): void {
        $this->log(self::LEVEL_DEBUG, 'api_request', [
            'url' => $url,
            'method' => $method,
            'request' => $this->sanitizeData($request),
            'response' => $this->sanitizeData($response),
            'http_code' => $httpCode,
            'duration' => $this->getRequestDuration(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log error
     */
    public function logError(string $context, array $data): void {
        $this->log(self::LEVEL_ERROR, 'error', array_merge(
            ['context' => $context],
            $data,
            ['timestamp' => date('Y-m-d H:i:s')]
        ));
    }
    
    /**
     * Log event
     */
    public function logEvent(string $event, array $data): void {
        $this->log(self::LEVEL_INFO, $event, array_merge(
            $data,
            ['timestamp' => date('Y-m-d H:i:s')]
        ));
    }
    
    /**
     * Generic log method
     */
    private function log(string $level, string $action, array $data): void {
        // Check if this log level should be recorded
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $logEntry = [
            'level' => $level,
            'action' => $action,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s'),
            'pid' => getmypid()
        ];
        
        // Write to daily log file
        $logFile = $this->logDir . date('Y-m-d') . '.log';
        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL;
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // Also write to specific action log file
        $actionLogFile = $this->logDir . $action . '_' . date('Y-m-d') . '.log';
        file_put_contents($actionLogFile, $logLine, FILE_APPEND | LOCK_EX);
        
        // If error level, also log to error file
        if ($level === self::LEVEL_ERROR) {
            $errorLogFile = $this->logDir . 'errors_' . date('Y-m-d') . '.log';
            file_put_contents($errorLogFile, $logLine, FILE_APPEND | LOCK_EX);
        }
        
        // Log to system log if configured
        if (defined('LOG_TO_SYSTEM') && LOG_TO_SYSTEM) {
            error_log("PaymentLogger [$level] $action: " . json_encode($data));
        }
    }
    
    /**
     * Check if should log based on level
     */
    private function shouldLog(string $level): bool {
        $levels = [
            self::LEVEL_DEBUG => 1,
            self::LEVEL_INFO => 2,
            self::LEVEL_WARN => 3,
            self::LEVEL_ERROR => 4
        ];
        
        $currentLevel = $levels[$this->logLevel] ?? 2;
        $messageLevel = $levels[$level] ?? 2;
        
        return $messageLevel >= $currentLevel;
    }
    
    /**
     * Sanitize sensitive data before logging
     */
    private function sanitizeData($data) {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (in_array($key, ['secret_key', 'password', 'token', 'authorization', 'card_number', 'cvv'])) {
                    $sanitized[$key] = '***MASKED***';
                } elseif (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeData($value);
                } elseif (is_string($value) && strlen($value) > 100) {
                    $sanitized[$key] = substr($value, 0, 100) . '...';
                } else {
                    $sanitized[$key] = $value;
                }
            }
            return $sanitized;
        }
        
        if (is_string($data) && strlen($data) > 500) {
            return substr($data, 0, 500) . '...';
        }
        
        return $data;
    }
    
    /**
     * Get request duration for logging
     */
    private function getRequestDuration(): float {
        static $startTime = null;
        
        if ($startTime === null) {
            $startTime = microtime(true);
            return 0;
        }
        
        $duration = microtime(true) - $startTime;
        $startTime = null;
        return round($duration * 1000, 2); // Convert to milliseconds
    }
    
    /**
     * Get recent logs
     */
    public function getRecentLogs(int $limit = 100, string $level = null): array {
        $logFile = $this->logDir . date('Y-m-d') . '.log';
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];
        $count = 0;
        
        for ($i = count($lines) - 1; $i >= 0 && $count < $limit; $i--) {
            $logData = json_decode($lines[$i], true);
            
            if ($level === null || $logData['level'] === $level) {
                $logs[] = $logData;
                $count++;
            }
        }
        
        return array_reverse($logs);
    }
    
    /**
     * Clear old log files
     */
    public function cleanupOldLogs(int $daysToKeep = 30): void {
        $files = glob($this->logDir . '*.log');
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get log statistics
     */
    public function getLogStats(string $date = null): array {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $logFile = $this->logDir . $date . '.log';
        
        if (!file_exists($logFile)) {
            return [
                'total' => 0,
                'by_level' => [],
                'by_action' => []
            ];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        $stats = [
            'total' => count($lines),
            'by_level' => [],
            'by_action' => []
        ];
        
        foreach ($lines as $line) {
            $logData = json_decode($line, true);
            
            if (!$logData) {
                continue;
            }
            
            $level = $logData['level'] ?? 'unknown';
            $action = $logData['action'] ?? 'unknown';
            
            if (!isset($stats['by_level'][$level])) {
                $stats['by_level'][$level] = 0;
            }
            $stats['by_level'][$level]++;
            
            if (!isset($stats['by_action'][$action])) {
                $stats['by_action'][$action] = 0;
            }
            $stats['by_action'][$action]++;
        }
        
        return $stats;
    }
}