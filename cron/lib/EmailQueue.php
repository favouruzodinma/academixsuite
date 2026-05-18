<?php
/**
 * Email Queue Manager
 * Helper class for managing email queue operations
 */

class EmailQueue {
    private $db;
    private $logger;
    
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    
    const MAX_ATTEMPTS = 3;
    const BATCH_SIZE = 50;
    
    public function __construct($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Add email to queue
     */
    public function enqueue($to, $subject, $body, $schoolId = null, $scheduledAt = null, $template = null, $templateData = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_queue 
                (school_id, `to`, subject, body, template, template_data, status, scheduled_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $templateDataJson = $templateData ? json_encode($templateData) : null;
            
            $stmt->execute([
                $schoolId,
                $to,
                $subject,
                $body,
                $template,
                $templateDataJson,
                self::STATUS_PENDING,
                $scheduledAt
            ]);
            
            $emailId = $this->db->lastInsertId();
            
            if ($this->logger) {
                $this->logger->info("Email queued", [
                    'id' => $emailId,
                    'to' => $to,
                    'scheduled_at' => $scheduledAt
                ]);
            }
            
            return $emailId;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to queue email", [
                    'to' => $to,
                    'error' => $e->getMessage()
                ]);
            }
            throw $e;
        }
    }
    
    /**
     * Get pending emails for bulk processing
     */
    public function getPendingBulkEmails($limit = self::BATCH_SIZE) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM email_queue
                WHERE status = ?
                AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                AND attempts < ?
                ORDER BY created_at ASC
                LIMIT ?
            ");
            
            $stmt->execute([self::STATUS_PENDING, self::MAX_ATTEMPTS, $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to fetch pending emails", [
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }
    
    /**
     * Get scheduled emails that are ready to send
     */
    public function getScheduledEmails($limit = self::BATCH_SIZE) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM email_queue
                WHERE status = ?
                AND scheduled_at IS NOT NULL
                AND scheduled_at <= NOW()
                AND attempts < ?
                ORDER BY scheduled_at ASC
                LIMIT ?
            ");
            
            $stmt->execute([self::STATUS_PENDING, self::MAX_ATTEMPTS, $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to fetch scheduled emails", [
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }
    
    /**
     * Mark email as sent
     */
    public function markAsSent($emailId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue
                SET status = ?, sent_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([self::STATUS_SENT, $emailId]);
            
            if ($this->logger) {
                $this->logger->info("Email marked as sent", ['id' => $emailId]);
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to mark email as sent", [
                    'id' => $emailId,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * Mark email as failed and increment attempts
     */
    public function markAsFailed($emailId, $errorMessage) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue
                SET attempts = attempts + 1,
                    last_attempt_at = NOW(),
                    error_message = ?
                WHERE id = ?
            ");
            
            $stmt->execute([$errorMessage, $emailId]);
            
            // Check if max attempts reached
            $stmt = $this->db->prepare("SELECT attempts FROM email_queue WHERE id = ?");
            $stmt->execute([$emailId]);
            $email = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($email && $email['attempts'] >= self::MAX_ATTEMPTS) {
                // Mark as permanently failed
                $stmt = $this->db->prepare("
                    UPDATE email_queue
                    SET status = ?
                    WHERE id = ?
                ");
                $stmt->execute([self::STATUS_FAILED, $emailId]);
                
                if ($this->logger) {
                    $this->logger->warning("Email permanently failed after max attempts", [
                        'id' => $emailId,
                        'attempts' => $email['attempts']
                    ]);
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to mark email as failed", [
                    'id' => $emailId,
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }
    
    /**
     * Get queue statistics
     */
    public function getStats() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    status,
                    COUNT(*) as count
                FROM email_queue
                GROUP BY status
            ");
            
            $stats = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $stats[$row['status']] = $row['count'];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to get queue stats", [
                    'error' => $e->getMessage()
                ]);
            }
            return [];
        }
    }
    
    /**
     * Clean old sent emails (older than 30 days)
     */
    public function cleanOldEmails($daysToKeep = 30) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM email_queue
                WHERE status = ?
                AND sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $stmt->execute([self::STATUS_SENT, $daysToKeep]);
            
            $deletedCount = $stmt->rowCount();
            
            if ($this->logger && $deletedCount > 0) {
                $this->logger->info("Cleaned old emails", [
                    'deleted_count' => $deletedCount,
                    'days_to_keep' => $daysToKeep
                ]);
            }
            
            return $deletedCount;
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to clean old emails", [
                    'error' => $e->getMessage()
                ]);
            }
            return 0;
        }
    }
}
