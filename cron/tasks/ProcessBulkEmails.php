<?php
/**
 * Process Bulk Emails Task
 * Processes emails from the queue in batches
 */

class ProcessBulkEmails extends CronTask {
    
    private $config;
    private $emailQueue;
    private $sentCount = 0;
    private $failedCount = 0;
    
    public function __construct($db, $taskName) {
        parent::__construct($db, $taskName);
        $this->config = require __DIR__ . '/../config.php';
        $this->emailQueue = new EmailQueue($db, $this->logger);
    }
    
    public function execute() {
        $this->logger->info("Starting bulk email processing");
        
        // Get queue statistics
        $stats = $this->emailQueue->getStats();
        $this->logger->info("Email queue statistics", $stats);
        
        // Get pending emails
        $batchSize = $this->config['email']['batch_size'];
        $emails = $this->emailQueue->getPendingBulkEmails($batchSize);
        
        if (empty($emails)) {
            $this->logger->info("No pending emails to process");
            return true;
        }
        
        $this->logger->info("Processing {count} emails", [
            'count' => count($emails)
        ]);
        
        // Process each email
        foreach ($emails as $email) {
            $this->processEmail($email);
            
            // Small delay between emails to prevent server overload
            if ($this->config['email']['batch_delay'] > 0) {
                sleep($this->config['email']['batch_delay']);
            }
        }
        
        $this->logger->success("Bulk email processing completed", [
            'total_processed' => count($emails),
            'sent' => $this->sentCount,
            'failed' => $this->failedCount
        ]);
        
        // Clean old emails
        $this->cleanOldEmails();
        
        return true;
    }
    
    /**
     * Process a single email
     */
    private function processEmail($email) {
        try {
            $this->logger->info("Processing email", [
                'id' => $email['id'],
                'to' => $email['to'],
                'subject' => $email['subject'],
                'attempt' => $email['attempts'] + 1
            ]);
            
            // Prepare email body
            $body = $this->prepareEmailBody($email);
            
            // Send email
            $success = $this->sendEmail(
                $email['to'],
                $email['subject'],
                $body,
                $email['school_id']
            );
            
            if ($success) {
                // Mark as sent
                $this->emailQueue->markAsSent($email['id']);
                $this->sentCount++;
                
                $this->logger->info("Email sent successfully", [
                    'id' => $email['id'],
                    'to' => $email['to']
                ]);
            } else {
                // Mark as failed
                $this->emailQueue->markAsFailed($email['id'], 'Failed to send email');
                $this->failedCount++;
                
                $this->logger->warning("Email send failed", [
                    'id' => $email['id'],
                    'to' => $email['to'],
                    'attempts' => $email['attempts'] + 1
                ]);
            }
            
        } catch (Exception $e) {
            $this->emailQueue->markAsFailed($email['id'], $e->getMessage());
            $this->failedCount++;
            
            $this->logger->error("Email processing error", [
                'id' => $email['id'],
                'to' => $email['to'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Prepare email body (handle templates if needed)
     */
    private function prepareEmailBody($email) {
        // If template is specified, load and populate it
        if (!empty($email['template']) && !empty($email['template_data'])) {
            return $this->renderTemplate($email['template'], json_decode($email['template_data'], true));
        }
        
        // Otherwise, use the body as-is
        return $email['body'];
    }
    
    /**
     * Render email template
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = BASE_PATH . "/cron/templates/{$templateName}.php";
        
        if (!file_exists($templatePath)) {
            $this->logger->warning("Email template not found, using body as-is", [
                'template' => $templateName
            ]);
            return $data['body'] ?? '';
        }
        
        // Extract data variables
        extract($data);
        
        // Capture template output
        ob_start();
        include $templatePath;
        $body = ob_get_clean();
        
        return $body;
    }
    
    /**
     * Clean old sent emails
     */
    private function cleanOldEmails() {
        $daysToKeep = $this->config['email']['cleanup_days'];
        $deletedCount = $this->emailQueue->cleanOldEmails($daysToKeep);
        
        if ($deletedCount > 0) {
            $this->logger->info("Cleaned old emails", [
                'deleted_count' => $deletedCount,
                'days_to_keep' => $daysToKeep
            ]);
        }
    }
}
