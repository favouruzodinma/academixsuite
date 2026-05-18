<?php
/**
 * ============================================================
 * CRON TASK: Process Email Queue
 * ============================================================
 * 
 * Processes pending emails from the email_queue table.
 * Sends emails in batches to avoid server overload.
 * 
 * SCHEDULE: Every 5 minutes
 * CRON: */5 * * * *
 * 
 * OPTIONS:
 *   --limit=N     : Process N emails (default: 50)
 *   --dry-run     : Simulate without actually sending
 * 
 * ============================================================
 */

function executeTask($options, $logger) {
    $limit = isset($options['limit']) ? (int)$options['limit'] : 50;
    $dryRun = isset($options['dry-run']);
    
    $logger->info("Starting email queue processing", [
        'limit' => $limit,
        'dry_run' => $dryRun
    ]);
    
    $db = Database::getPlatformConnection();
    
    // Check for suppressed emails
    $suppressedEmails = [];
    try {
        $stmt = $db->query("SELECT email FROM email_suppression_list");
        $suppressedEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $logger->warning("Could not load suppression list: " . $e->getMessage());
    }
    
    // Get pending emails (not scheduled or scheduled for now/past)
    $stmt = $db->prepare("
        SELECT * FROM email_queue 
        WHERE status = 'pending' 
        AND attempts < max_attempts
        AND (scheduled_for IS NULL OR scheduled_for <= NOW())
        ORDER BY priority ASC, created_at ASC 
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logger->info("Found {count} emails to process", ['count' => count($emails)]);
    
    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    $skipped = 0;
    
    // Initialize email service
    if (!$dryRun) {
        require_once dirname(__DIR__, 2) . '/includes/Services/EmailService.php';
        $emailService = new EmailService();
    }
    
    foreach ($emails as $email) {
        $processed++;
        
        // Check if email is suppressed
        if (in_array($email['to_email'], $suppressedEmails)) {
            $logger->warning("Skipping suppressed email", [
                'email_id' => $email['id'],
                'to' => $email['to_email']
            ]);
            
            // Mark as cancelled
            $stmt = $db->prepare("
                UPDATE email_queue 
                SET status = 'cancelled', 
                    error_message = 'Email is in suppression list',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$email['id']]);
            $skipped++;
            continue;
        }
        
        // Mark as processing
        $stmt = $db->prepare("
            UPDATE email_queue 
            SET status = 'processing', 
                attempts = attempts + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$email['id']]);
        
        if ($dryRun) {
            $logger->info("DRY RUN: Would send email", [
                'email_id' => $email['id'],
                'to' => $email['to_email'],
                'subject' => $email['subject']
            ]);
            
            // Mark as sent in dry run
            $stmt = $db->prepare("
                UPDATE email_queue 
                SET status = 'pending',
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$email['id']]);
            $succeeded++;
            continue;
        }
        
        // Send email
        try {
            $sendResult = $emailService->sendEmail(
                $email['to_email'],
                $email['subject'],
                $email['html_content'],
                $email['text_content'],
                $email['from_email'] ?? null,
                $email['from_name'] ?? null
            );
            
            if ($sendResult['success']) {
                // Mark as sent
                $stmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'sent', 
                        sent_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$email['id']]);
                
                $succeeded++;
                $logger->info("Email sent successfully", [
                    'email_id' => $email['id'],
                    'to' => $email['to_email']
                ]);
                
            } else {
                throw new Exception($sendResult['error'] ?? 'Unknown error');
            }
            
        } catch (Exception $e) {
            $failed++;
            $errorMsg = $e->getMessage();
            
            $logger->error("Failed to send email", [
                'email_id' => $email['id'],
                'to' => $email['to_email'],
                'error' => $errorMsg
            ]);
            
            // Check if max attempts reached
            if ($email['attempts'] + 1 >= $email['max_attempts']) {
                // Mark as failed permanently
                $stmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'failed', 
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$errorMsg, $email['id']]);
                
                $logger->warning("Email marked as failed (max attempts reached)", [
                    'email_id' => $email['id']
                ]);
                
            } else {
                // Mark as pending for retry
                $stmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'pending', 
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$errorMsg, $email['id']]);
            }
        }
        
        // Small delay to avoid overwhelming the mail server
        if (!$dryRun) {
            usleep(100000); // 100ms delay
        }
    }
    
    $logger->success("Email queue processing completed", [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed,
        'skipped' => $skipped
    ]);
    
    return [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed,
        'skipped' => $skipped
    ];
}
