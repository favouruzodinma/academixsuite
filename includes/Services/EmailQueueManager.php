<?php
// includes/Services/EmailQueueManager.php

/**
 * Email Queue Manager
 * Handles queuing, processing, and tracking of all email communications
 * Fully integrated with multi-tenant architecture
 */
class EmailQueueManager {
    private $db;
    private $emailService;
    private $currentSchoolId = null;
    private $currentTenantId = null;
    private $currentUserId = null;
    private $defaultFromEmail;
    private $defaultFromName;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getPlatformConnection();
        $this->emailService = new EmailService();
        $this->defaultFromEmail = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@academixsuite.com';
        $this->defaultFromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'AcademixSuite';
    }
    
    // ==================== CONTEXT SETTERS ====================
    
    /**
     * Set school context for multi-tenancy
     */
    public function setSchoolContext($schoolId, $tenantId = null) {
        $this->currentSchoolId = $schoolId;
        $this->currentTenantId = $tenantId;
        return $this;
    }
    
    /**
     * Set user context for tracking
     */
    public function setUserContext($userId) {
        $this->currentUserId = $userId;
        return $this;
    }
    
    // ==================== QUEUE ADDITION METHODS ====================
    
    /**
     * Add email to queue with full schema support
     */
    public function addToQueue($to, $subject, $htmlContent, $textContent = null, $priority = 5, $type = 'general') {
        try {
            // Validate email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: {$to}");
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO email_queue (
                    tenant_id, school_id, recipient_email, recipient_name, recipient_type,
                    subject, body_html, body_text, template_name, from_email, from_name,
                    priority, status, headers, created_at, scheduled_at, max_attempts
                ) VALUES (
                    :tenant_id, :school_id, :recipient_email, :recipient_name, :recipient_type,
                    :subject, :body_html, :body_text, :template_name, :from_email, :from_name,
                    :priority, 'pending', :headers, NOW(), NOW(), 3
                )
            ");
            
            $recipientName = $this->extractNameFromEmail($to);
            $recipientType = $this->determineRecipientType($to);
            
            $stmt->execute([
                ':tenant_id' => $this->currentTenantId,
                ':school_id' => $this->currentSchoolId,
                ':recipient_email' => $to,
                ':recipient_name' => $recipientName,
                ':recipient_type' => $recipientType,
                ':subject' => $subject,
                ':body_html' => $htmlContent,
                ':body_text' => $textContent,
                ':template_name' => $type,
                ':from_email' => $this->defaultFromEmail,
                ':from_name' => $this->defaultFromName,
                ':priority' => $priority,
                ':headers' => $this->generateDefaultHeaders()
            ]);
            
            $emailId = $this->db->lastInsertId();
            
            error_log("EmailQueueManager: Email queued successfully - ID: {$emailId}, To: {$to}, Type: {$type}");
            
            return [
                'success' => true, 
                'id' => $emailId,
                'message' => 'Email queued successfully'
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to add to queue: " . $e->getMessage());
            return [
                'success' => false, 
                'error' => $e->getMessage(),
                'code' => 'QUEUE_ADD_FAILED'
            ];
        }
    }
    
    /**
     * Add templated email to queue
     */
    public function addToQueueWithTemplate($to, $subject, $templateName, $templateData, $priority = 5, $recipientType = 'school_admin') {
        try {
            // Validate email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address: {$to}");
            }
            
            // Validate template data is valid JSON
            $templateDataJson = json_encode($templateData);
            if ($templateDataJson === false) {
                throw new Exception("Invalid template data - cannot encode to JSON");
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO email_queue (
                    tenant_id, school_id, recipient_email, recipient_name, recipient_type,
                    subject, template_name, template_data, from_email, from_name,
                    priority, status, headers, created_at, scheduled_at, max_attempts
                ) VALUES (
                    :tenant_id, :school_id, :recipient_email, :recipient_name, :recipient_type,
                    :subject, :template_name, :template_data, :from_email, :from_name,
                    :priority, 'pending', :headers, NOW(), NOW(), 3
                )
            ");
            
            $recipientName = $templateData['recipient_name'] ?? $this->extractNameFromEmail($to);
            
            $stmt->execute([
                ':tenant_id' => $this->currentTenantId,
                ':school_id' => $this->currentSchoolId,
                ':recipient_email' => $to,
                ':recipient_name' => $recipientName,
                ':recipient_type' => $recipientType,
                ':subject' => $subject,
                ':template_name' => $templateName,
                ':template_data' => $templateDataJson,
                ':from_email' => $this->defaultFromEmail,
                ':from_name' => $this->defaultFromName,
                ':priority' => $priority,
                ':headers' => $this->generateDefaultHeaders()
            ]);
            
            $emailId = $this->db->lastInsertId();
            
            error_log("EmailQueueManager: Templated email queued - ID: {$emailId}, Template: {$templateName}");
            
            return [
                'success' => true, 
                'id' => $emailId,
                'message' => 'Templated email queued successfully'
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to add templated email: " . $e->getMessage());
            return [
                'success' => false, 
                'error' => $e->getMessage(),
                'code' => 'TEMPLATE_QUEUE_FAILED'
            ];
        }
    }
    
    /**
     * Add bulk emails to queue (for campaigns)
     */
    public function addBulkToQueue($recipients, $subject, $templateName, $templateData, $batchId = null, $priority = 5) {
        $results = [
            'total' => count($recipients),
            'success' => 0,
            'failed' => 0,
            'failed_emails' => [],
            'batch_id' => $batchId ?: uniqid('batch_', true)
        ];
        
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_queue (
                    tenant_id, school_id, recipient_email, recipient_name, recipient_type,
                    subject, template_name, template_data, from_email, from_name,
                    priority, status, headers, created_at, scheduled_at, max_attempts, batch_id
                ) VALUES (
                    :tenant_id, :school_id, :recipient_email, :recipient_name, :recipient_type,
                    :subject, :template_name, :template_data, :from_email, :from_name,
                    :priority, 'pending', :headers, NOW(), NOW(), 3, :batch_id
                )
            ");
            
            foreach ($recipients as $recipient) {
                try {
                    $email = is_array($recipient) ? $recipient['email'] : $recipient;
                    $name = is_array($recipient) ? ($recipient['name'] ?? $this->extractNameFromEmail($email)) : $this->extractNameFromEmail($email);
                    $type = is_array($recipient) ? ($recipient['type'] ?? 'other') : 'other';
                    
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email: {$email}");
                    }
                    
                    $recipientData = array_merge($templateData, [
                        'recipient_name' => $name,
                        'recipient_email' => $email
                    ]);
                    
                    $stmt->execute([
                        ':tenant_id' => $this->currentTenantId,
                        ':school_id' => $this->currentSchoolId,
                        ':recipient_email' => $email,
                        ':recipient_name' => $name,
                        ':recipient_type' => $type,
                        ':subject' => $subject,
                        ':template_name' => $templateName,
                        ':template_data' => json_encode($recipientData),
                        ':from_email' => $this->defaultFromEmail,
                        ':from_name' => $this->defaultFromName,
                        ':priority' => $priority,
                        ':headers' => $this->generateDefaultHeaders(),
                        ':batch_id' => $results['batch_id']
                    ]);
                    
                    $results['success']++;
                    
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['failed_emails'][] = [
                        'email' => $email,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->db->commit();
            error_log("EmailQueueManager: Bulk queue completed - Success: {$results['success']}, Failed: {$results['failed']}");
            
            return array_merge(['success' => true], $results);
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("EmailQueueManager: Bulk queue failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'BULK_QUEUE_FAILED'
            ];
        }
    }
    
    // ==================== QUEUE PROCESSING METHODS ====================
    
    /**
     * Process email queue with full retry logic and exponential backoff
     */
    public function processQueue($limit = 20) {
        try {
            // Get pending emails with retry logic
            $stmt = $this->db->prepare("
                SELECT * FROM email_queue 
                WHERE status = 'pending' 
                AND attempts < max_attempts
                AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                ORDER BY priority ASC, attempts ASC, created_at ASC 
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute([':limit' => $limit]);
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $results = [
                'total_processed' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'retry_count' => 0,
                'permanent_failed' => 0,
                'emails' => []
            ];
            
            foreach ($emails as $email) {
                $results['total_processed']++;
                
                // Mark as processing
                $this->updateStatus($email['id'], 'processing', null, ['processing_at' => date('Y-m-d H:i:s')]);
                
                // Prepare content - either use stored HTML or render template
                $htmlContent = $email['body_html'];
                $textContent = $email['body_text'];
                
                if (empty($htmlContent) && !empty($email['template_name']) && !empty($email['template_data'])) {
                    // Render template
                    $templateData = json_decode($email['template_data'], true);
                    $renderResult = $this->renderTemplate($email['template_name'], $templateData);
                    if ($renderResult['success']) {
                        $htmlContent = $renderResult['html'];
                        $textContent = $renderResult['text'];
                    }
                }
                
                // Send email
                $sendResult = $this->emailService->sendEmail(
                    $email['recipient_email'],
                    $email['subject'],
                    $htmlContent,
                    $textContent,
                    [
                        'from_email' => $email['from_email'],
                        'from_name' => $email['from_name'],
                        'reply_to' => $email['reply_to'],
                        'headers' => json_decode($email['headers'], true) ?? []
                    ]
                );
                
                if ($sendResult['success']) {
                    // Success - mark as sent
                    $this->markAsSent($email['id'], $sendResult);
                    $results['success_count']++;
                    $results['emails'][] = [
                        'id' => $email['id'],
                        'email' => $email['recipient_email'],
                        'status' => 'sent',
                        'message_id' => $sendResult['message_id'] ?? null
                    ];
                    
                } else {
                    // Failed - handle retry logic
                    $newAttempts = $email['attempts'] + 1;
                    
                    if ($newAttempts >= $email['max_attempts']) {
                        // Permanent failure
                        $this->markAsFailed($email['id'], $sendResult['error'], true);
                        $results['permanent_failed']++;
                        $results['emails'][] = [
                            'id' => $email['id'],
                            'email' => $email['recipient_email'],
                            'status' => 'failed_permanent',
                            'error' => $sendResult['error']
                        ];
                    } else {
                        // Schedule retry with exponential backoff
                        $backoffMinutes = pow(5, $newAttempts); // 5, 25, 125 minutes
                        $nextRetryAt = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));
                        
                        $this->scheduleRetry($email['id'], $sendResult['error'], $nextRetryAt);
                        $results['retry_count']++;
                        $results['emails'][] = [
                            'id' => $email['id'],
                            'email' => $email['recipient_email'],
                            'status' => 'retry_scheduled',
                            'next_retry' => $nextRetryAt,
                            'attempt' => $newAttempts
                        ];
                    }
                    
                    $results['failed_count']++;
                }
            }
            
            error_log("EmailQueueManager: Queue processed - {$results['total_processed']} emails, Success: {$results['success_count']}, Failed: {$results['failed_count']}, Retries: {$results['retry_count']}");
            
            return $results;
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Error processing queue: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send a specific email from the queue immediately
     */
    public function sendEmailById($emailId) {
        try {
            // Lock the row to prevent concurrent processing
            $stmt = $this->db->prepare("
                SELECT * FROM email_queue 
                WHERE id = :id 
                AND status IN ('pending', 'failed')
                AND attempts < max_attempts
                FOR UPDATE
            ");
            $stmt->execute([':id' => $emailId]);
            $email = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$email) {
                return [
                    'success' => false, 
                    'error' => 'Email not found, already processed, or max attempts reached',
                    'code' => 'EMAIL_NOT_FOUND'
                ];
            }
            
            // Increment attempts and mark as processing
            $this->db->prepare("
                UPDATE email_queue 
                SET attempts = attempts + 1,
                    status = 'processing',
                    processing_at = NOW()
                WHERE id = ?
            ")->execute([$emailId]);
            
            // Prepare content
            $htmlContent = $email['body_html'];
            $textContent = $email['body_text'];
            
            if (empty($htmlContent) && !empty($email['template_name']) && !empty($email['template_data'])) {
                $templateData = json_decode($email['template_data'], true);
                $renderResult = $this->renderTemplate($email['template_name'], $templateData);
                if ($renderResult['success']) {
                    $htmlContent = $renderResult['html'];
                    $textContent = $renderResult['text'];
                }
            }
            
            // Send email
            $sendResult = $this->emailService->sendEmail(
                $email['recipient_email'],
                $email['subject'],
                $htmlContent,
                $textContent,
                [
                    'from_email' => $email['from_email'],
                    'from_name' => $email['from_name'],
                    'reply_to' => $email['reply_to'],
                    'headers' => json_decode($email['headers'], true) ?? []
                ]
            );
            
            if ($sendResult['success']) {
                $this->markAsSent($emailId, $sendResult);
                return [
                    'success' => true,
                    'message' => 'Email sent successfully',
                    'message_id' => $sendResult['message_id'] ?? null,
                    'provider_message_id' => $sendResult['provider_message_id'] ?? null
                ];
            } else {
                $newAttempts = $email['attempts'] + 1;
                
                if ($newAttempts >= $email['max_attempts']) {
                    $this->markAsFailed($emailId, $sendResult['error'], true);
                    return [
                        'success' => false,
                        'error' => $sendResult['error'],
                        'permanent' => true,
                        'code' => 'PERMANENT_FAILURE'
                    ];
                } else {
                    $backoffMinutes = pow(5, $newAttempts);
                    $nextRetryAt = date('Y-m-d H:i:s', strtotime("+{$backoffMinutes} minutes"));
                    $this->scheduleRetry($emailId, $sendResult['error'], $nextRetryAt);
                    
                    return [
                        'success' => false,
                        'error' => $sendResult['error'],
                        'retry_at' => $nextRetryAt,
                        'attempt' => $newAttempts,
                        'code' => 'TEMPORARY_FAILURE'
                    ];
                }
            }
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Error sending email {$emailId}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'code' => 'SEND_EXCEPTION'
            ];
        }
    }
    
    // ==================== STATUS UPDATE METHODS ====================
    
    /**
     * Mark email as sent
     */
    private function markAsSent($emailId, $sendResult = []) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue 
                SET status = 'sent',
                    sent_at = NOW(),
                    processing_at = NULL,
                    updated_at = NOW(),
                    message_id = :message_id,
                    provider_message_id = :provider_message_id,
                    smtp_response = :smtp_response
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $emailId,
                ':message_id' => $sendResult['message_id'] ?? null,
                ':provider_message_id' => $sendResult['provider_message_id'] ?? null,
                ':smtp_response' => $sendResult['smtp_response'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to mark as sent: " . $e->getMessage());
        }
    }
    
    /**
     * Mark email as failed
     */
    private function markAsFailed($emailId, $error, $permanent = false) {
        try {
            $status = $permanent ? 'failed' : 'pending';
            
            $stmt = $this->db->prepare("
                UPDATE email_queue 
                SET status = :status,
                    error_message = :error,
                    failed_at = CASE WHEN :permanent = 1 THEN NOW() ELSE failed_at END,
                    processing_at = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $emailId,
                ':status' => $status,
                ':error' => $error,
                ':permanent' => $permanent ? 1 : 0
            ]);
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to mark as failed: " . $e->getMessage());
        }
    }
    
    /**
     * Schedule retry with exponential backoff
     */
    private function scheduleRetry($emailId, $error, $nextRetryAt) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue 
                SET status = 'pending',
                    error_message = :error,
                    next_retry_at = :next_retry,
                    processing_at = NULL,
                    updated_at = NOW()
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $emailId,
                ':error' => $error,
                ':next_retry' => $nextRetryAt
            ]);
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to schedule retry: " . $e->getMessage());
        }
    }
    
    /**
     * Update email status
     */
    private function updateStatus($emailId, $status, $error = null, $additionalFields = []) {
        try {
            $fields = [];
            $params = [':id' => $emailId, ':status' => $status];
            
            if ($error !== null) {
                $fields[] = "error_message = :error";
                $params[':error'] = $error;
            }
            
            foreach ($additionalFields as $field => $value) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $value;
            }
            
            $fields[] = "updated_at = NOW()";
            
            $sql = "UPDATE email_queue SET status = :status, " . implode(', ', $fields) . " WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to update status: " . $e->getMessage());
        }
    }
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * Extract name from email address
     */
    private function extractNameFromEmail($email) {
        $parts = explode('@', $email);
        $name = str_replace(['.', '_', '-'], ' ', $parts[0]);
        return ucwords($name);
    }
    
    /**
     * Determine recipient type based on email or context
     */
    private function determineRecipientType($email) {
        // You can enhance this based on your domain logic
        // For now, return a sensible default
        return 'school_admin';
    }
    
    /**
     * Generate default email headers
     */
    private function generateDefaultHeaders() {
        $headers = [
            'X-Mailer' => 'AcademixSuite Email Queue Manager',
            'X-Priority' => '3',
            'List-Unsubscribe' => '<mailto:unsubscribe@academixsuite.com>',
            'Precedence' => 'bulk'
        ];
        
        return json_encode($headers);
    }
    
    /**
     * Render email template
     */
    private function renderTemplate($templateName, $data) {
        try {
            $emailTemplate = new EmailTemplate();
            $html = $emailTemplate->getTemplate($templateName, $data);
            $text = strip_tags($html);
            
            return [
                'success' => true,
                'html' => $html,
                'text' => $text
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Template rendering failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ==================== STATISTICS AND MAINTENANCE ====================
    
    /**
     * Get queue statistics
     */
    public function getQueueStats() {
        try {
            $stmt = $this->db->query("
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) as retry_count,
                    AVG(attempts) as avg_attempts,
                    MIN(created_at) as oldest_email,
                    MAX(created_at) as newest_email
                FROM email_queue 
                GROUP BY status
                UNION ALL
                SELECT 
                    'total' as status,
                    COUNT(*) as count,
                    SUM(CASE WHEN attempts > 0 THEN 1 ELSE 0 END) as retry_count,
                    AVG(attempts) as avg_attempts,
                    MIN(created_at) as oldest_email,
                    MAX(created_at) as newest_email
                FROM email_queue
            ");
            
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $result = [
                'total' => 0,
                'pending' => 0,
                'processing' => 0,
                'sent' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'retry_count' => 0,
                'avg_attempts' => 0,
                'oldest_email' => null,
                'newest_email' => null,
                'by_status' => []
            ];
            
            foreach ($stats as $stat) {
                if ($stat['status'] === 'total') {
                    $result['total'] = $stat['count'];
                    $result['retry_count'] = $stat['retry_count'];
                    $result['avg_attempts'] = round($stat['avg_attempts'] ?? 0, 2);
                    $result['oldest_email'] = $stat['oldest_email'];
                    $result['newest_email'] = $stat['newest_email'];
                } else {
                    $result[$stat['status']] = $stat['count'];
                    $result['by_status'][$stat['status']] = [
                        'count' => $stat['count'],
                        'retry_count' => $stat['retry_count'],
                        'avg_attempts' => round($stat['avg_attempts'] ?? 0, 2)
                    ];
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to get stats: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get detailed queue information for monitoring
     */
    public function getQueueDetails($status = null, $limit = 100) {
        try {
            $sql = "
                SELECT 
                    id, tenant_id, school_id, recipient_email, recipient_name, recipient_type,
                    subject, template_name, status, priority, attempts, max_attempts,
                    created_at, scheduled_at, sent_at, failed_at, next_retry_at,
                    error_message, error_code, batch_id, message_id, provider_message_id
                FROM email_queue
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($status) {
                if (is_array($status)) {
                    $placeholders = implode(',', array_fill(0, count($status), '?'));
                    $sql .= " AND status IN ({$placeholders})";
                    $params = $status;
                } else {
                    $sql .= " AND status = ?";
                    $params[] = $status;
                }
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT " . intval($limit);
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to get queue details: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Clear old emails from queue
     */
    public function clearOldEmails($days = 30, $status = ['sent', 'failed', 'cancelled']) {
        try {
            $placeholders = implode(',', array_fill(0, count($status), '?'));
            
            $stmt = $this->db->prepare("
                DELETE FROM email_queue 
                WHERE status IN ({$placeholders})
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $params = array_merge($status, [$days]);
            $stmt->execute($params);
            
            $deletedCount = $stmt->rowCount();
            
            error_log("EmailQueueManager: Cleared {$deletedCount} old emails older than {$days} days");
            
            return [
                'success' => true,
                'deleted' => $deletedCount,
                'message' => "Cleared {$deletedCount} old emails"
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to clear old emails: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Cancel pending emails by batch ID
     */
    public function cancelBatch($batchId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue 
                SET status = 'cancelled',
                    error_message = 'Cancelled by administrator',
                    updated_at = NOW()
                WHERE batch_id = ? AND status = 'pending'
            ");
            
            $stmt->execute([$batchId]);
            
            return [
                'success' => true,
                'cancelled' => $stmt->rowCount()
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to cancel batch: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Retry failed emails
     */
    public function retryFailedEmails($limit = 50) {
        try {
            $stmt = $this->db->prepare("
                UPDATE email_queue 
                SET status = 'pending',
                    next_retry_at = NOW(),
                    error_message = NULL,
                    updated_at = NOW()
                WHERE status = 'failed'
                AND attempts < max_attempts
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            $updated = $stmt->rowCount();
            
            error_log("EmailQueueManager: Retry triggered for {$updated} failed emails");
            
            return [
                'success' => true,
                'retried' => $updated
            ];
            
        } catch (Exception $e) {
            error_log("EmailQueueManager: Failed to retry emails: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}