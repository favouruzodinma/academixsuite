<?php
/**
 * Process Scheduled Emails Task
 * Sends emails that have reached their scheduled time
 */

class ProcessScheduledEmails extends CronTask {
    
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
        $this->logger->info("Starting scheduled email processing");
        
        // Get scheduled emails that are ready to send
        $batchSize = $this->config['email']['batch_size'];
        $emails = $this->emailQueue->getScheduledEmails($batchSize);
        
        if (empty($emails)) {
            $this->logger->info("No scheduled emails ready to send");
            return true;
        }
        
        $this->logger->info("Processing {count} scheduled emails", [
            'count' => count($emails)
        ]);
        
        // Process each email
        foreach ($emails as $email) {
            $this->processScheduledEmail($email);
            
            // Small delay between emails
            if ($this->config['email']['batch_delay'] > 0) {
                sleep($this->config['email']['batch_delay']);
            }
        }
        
        $this->logger->success("Scheduled email processing completed", [
            'total_processed' => count($emails),
            'sent' => $this->sentCount,
            'failed' => $this->failedCount
        ]);
        
        return true;
    }
    
    /**
     * Process a single scheduled email
     */
    private function processScheduledEmail($email) {
        try {
            $this->logger->info("Processing scheduled email", [
                'id' => $email['id'],
                'to' => $email['to'],
                'subject' => $email['subject'],
                'scheduled_at' => $email['scheduled_at']
            ]);
            
            // Prepare email body
            $body = $email['body'];
            
            // If template is specified, render it
            if (!empty($email['template']) && !empty($email['template_data'])) {
                $body = $this->renderTemplate($email['template'], json_decode($email['template_data'], true));
            }
            
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
                
                $this->logger->info("Scheduled email sent successfully", [
                    'id' => $email['id'],
                    'to' => $email['to']
                ]);
            } else {
                // Mark as failed
                $this->emailQueue->markAsFailed($email['id'], 'Failed to send scheduled email');
                $this->failedCount++;
                
                $this->logger->warning("Scheduled email send failed", [
                    'id' => $email['id'],
                    'to' => $email['to']
                ]);
            }
            
        } catch (Exception $e) {
            $this->emailQueue->markAsFailed($email['id'], $e->getMessage());
            $this->failedCount++;
            
            $this->logger->error("Scheduled email processing error", [
                'id' => $email['id'],
                'to' => $email['to'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Render email template
     */
    private function renderTemplate($templateName, $data) {
        $templatePath = BASE_PATH . "/cron/templates/{$templateName}.php";
        
        if (!file_exists($templatePath)) {
            $this->logger->warning("Email template not found", [
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
}
