<?php
/**
 * ============================================================
 * CRON TASK: Retry Failed Emails
 * ============================================================
 * 
 * Retries emails that failed to send but haven't exceeded max attempts.
 * Implements exponential backoff for retry timing.
 * 
 * SCHEDULE: Every 6 hours
 * CRON: 0 */6 * * *
 * 
 * OPTIONS:
 *   --limit=N     : Process N emails (default: 100)
 *   --dry-run     : Simulate without actually retrying
 * 
 * ============================================================
 */

function executeTask($options, $logger) {
    $limit = isset($options['limit']) ? (int)$options['limit'] : 100;
    $dryRun = isset($options['dry-run']);
    
    $logger->info("Starting failed email retry processing", [
        'limit' => $limit,
        'dry_run' => $dryRun
    ]);
    
    $db = Database::getPlatformConnection();
    
    // Get failed emails that can be retried
    // Use exponential backoff: wait longer between retries
    $stmt = $db->prepare("
        SELECT * FROM email_queue 
        WHERE status = 'failed' 
        AND attempts < max_attempts
        AND (
            attempts = 0 OR
            updated_at < DATE_SUB(NOW(), INTERVAL POW(2, attempts) HOUR)
        )
        ORDER BY priority ASC, created_at ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logger->info("Found {count} failed emails to retry", ['count' => count($emails)]);
    
    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    
    if (!$dryRun) {
        require_once dirname(__DIR__, 2) . '/includes/Services/EmailService.php';
        $emailService = new EmailService();
    }
    
    foreach ($emails as $email) {
        $processed++;
        
        $logger->info("Retrying email", [
            'email_id' => $email['id'],
            'to' => $email['to_email'],
            'attempt' => $email['attempts'] + 1,
            'max_attempts' => $email['max_attempts']
        ]);
        
        // Reset status to pending for retry
        $stmt = $db->prepare("
            UPDATE email_queue 
            SET status = 'pending',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$email['id']]);
        
        if ($dryRun) {
            $logger->info("DRY RUN: Would retry email", [
                'email_id' => $email['id'],
                'to' => $email['to_email'],
                'previous_error' => $email['error_message']
            ]);
            $succeeded++;
            continue;
        }
        
        // Try to send immediately
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
                        attempts = attempts + 1,
                        error_message = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$email['id']]);
                
                $succeeded++;
                $logger->success("Email retry successful", [
                    'email_id' => $email['id'],
                    'to' => $email['to_email']
                ]);
                
            } else {
                throw new Exception($sendResult['error'] ?? 'Unknown error');
            }
            
        } catch (Exception $e) {
            $failed++;
            $errorMsg = $e->getMessage();
            
            $logger->error("Email retry failed", [
                'email_id' => $email['id'],
                'to' => $email['to_email'],
                'error' => $errorMsg
            ]);
            
            // Increment attempts and update error
            $newAttempts = $email['attempts'] + 1;
            
            if ($newAttempts >= $email['max_attempts']) {
                // Max attempts reached, mark as permanently failed
                $stmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'failed', 
                        attempts = ?,
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $errorMsg, $email['id']]);
                
                $logger->warning("Email permanently failed (max attempts reached)", [
                    'email_id' => $email['id'],
                    'attempts' => $newAttempts
                ]);
                
            } else {
                // Still can retry, update error and attempts
                $stmt = $db->prepare("
                    UPDATE email_queue 
                    SET status = 'failed', 
                        attempts = ?,
                        error_message = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$newAttempts, $errorMsg, $email['id']]);
            }
        }
        
        // Small delay between retries
        if (!$dryRun) {
            usleep(200000); // 200ms delay
        }
    }
    
    $logger->success("Failed email retry processing completed", [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed
    ]);
    
    return [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed
    ];
}
