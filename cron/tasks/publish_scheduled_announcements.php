<?php
/**
 * ============================================================
 * CRON TASK: Publish Scheduled Announcements
 * ============================================================
 * 
 * Publishes announcements that are scheduled for the current time.
 * Optionally sends email/SMS notifications.
 * 
 * SCHEDULE: Every 15 minutes
 * CRON: */15 * * * *
 * 
 * OPTIONS:
 *   --dry-run     : Simulate without actually publishing
 *   --limit=N     : Process N announcements (default: 50)
 * 
 * ============================================================
 */

function executeTask($options, $logger) {
    $dryRun = isset($options['dry-run']);
    $limit = isset($options['limit']) ? (int)$options['limit'] : 50;
    
    $logger->info("Starting scheduled announcement publishing", [
        'dry_run' => $dryRun,
        'limit' => $limit
    ]);
    
    $platformDb = Database::getPlatformConnection();
    
    // Get scheduled announcements that are due
    $stmt = $platformDb->prepare("
        SELECT * FROM scheduled_announcements 
        WHERE status = 'scheduled' 
        AND scheduled_for <= NOW()
        ORDER BY scheduled_for ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logger->info("Found {count} announcements to publish", ['count' => count($announcements)]);
    
    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    
    foreach ($announcements as $announcement) {
        $processed++;
        
        try {
            $logger->info("Processing announcement", [
                'announcement_id' => $announcement['id'],
                'title' => $announcement['title'],
                'school_id' => $announcement['school_id']
            ]);
            
            if (!$dryRun) {
                // Determine target database
                if ($announcement['school_id']) {
                    // School-specific announcement
                    $stmt = $platformDb->prepare("SELECT subdomain FROM schools WHERE id = ?");
                    $stmt->execute([$announcement['school_id']]);
                    $school = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$school) {
                        throw new Exception("School not found: ID " . $announcement['school_id']);
                    }
                    
                    $schoolDbName = 'school_' . $school['subdomain'];
                    $targetDb = Database::getSchoolConnection($schoolDbName);
                    
                } else {
                    // Platform-wide announcement
                    $targetDb = $platformDb;
                }
                
                // Insert announcement into announcements table
                $stmt = $targetDb->prepare("
                    INSERT INTO announcements 
                    (title, content, type, target_audience, status, published_at, expires_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 'published', NOW(), ?, NOW(), NOW())
                ");
                
                $stmt->execute([
                    $announcement['title'],
                    $announcement['content'],
                    $announcement['type'],
                    $announcement['target_audience'],
                    $announcement['expires_at']
                ]);
                
                $announcementId = $targetDb->lastInsertId();
                
                $logger->info("Announcement published", [
                    'announcement_id' => $announcementId,
                    'title' => $announcement['title']
                ]);
                
                // Send email notifications if requested
                if ($announcement['send_email']) {
                    $emailsSent = sendAnnouncementEmails(
                        $announcement,
                        $targetDb,
                        $platformDb,
                        $logger
                    );
                    
                    $logger->info("Announcement emails queued", [
                        'count' => $emailsSent
                    ]);
                }
                
                // Send SMS notifications if requested
                if ($announcement['send_sms']) {
                    $smsSent = sendAnnouncementSMS(
                        $announcement,
                        $targetDb,
                        $logger
                    );
                    
                    $logger->info("Announcement SMS queued", [
                        'count' => $smsSent
                    ]);
                }
                
                // Mark as published in scheduled_announcements
                $stmt = $platformDb->prepare("
                    UPDATE scheduled_announcements 
                    SET status = 'published',
                        published_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$announcement['id']]);
                
            } else {
                $logger->info("DRY RUN: Would publish announcement", [
                    'title' => $announcement['title'],
                    'type' => $announcement['type'],
                    'target_audience' => $announcement['target_audience'],
                    'send_email' => $announcement['send_email'],
                    'send_sms' => $announcement['send_sms']
                ]);
            }
            
            $succeeded++;
            
        } catch (Exception $e) {
            $failed++;
            $logger->error("Failed to publish announcement", [
                'announcement_id' => $announcement['id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    $logger->success("Scheduled announcement publishing completed", [
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

/**
 * Send announcement emails to target audience
 */
function sendAnnouncementEmails($announcement, $targetDb, $platformDb, $logger) {
    try {
        // Get recipients based on target audience
        $recipients = getAnnouncementRecipients($announcement, $targetDb);
        
        $emailsQueued = 0;
        
        foreach ($recipients as $recipient) {
            if (!$recipient['email']) {
                continue;
            }
            
            $subject = "New Announcement: " . $announcement['title'];
            
            $htmlContent = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #007bff; color: white; padding: 20px; text-align: center; }
                    .content { background: #f8f9fa; padding: 30px; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
                    .badge { display: inline-block; padding: 4px 8px; background: #ffc107; color: #000; border-radius: 4px; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>{$announcement['title']}</h1>
                        <span class='badge'>" . strtoupper($announcement['type']) . "</span>
                    </div>
                    <div class='content'>
                        " . nl2br(htmlspecialchars($announcement['content'])) . "
                    </div>
                    <div class='footer'>
                        <p>Posted on " . date('F j, Y \a\t g:i A') . "</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $textContent = $announcement['title'] . "\n\n" . $announcement['content'];
            
            // Add to email queue
            $stmt = $platformDb->prepare("
                INSERT INTO email_queue 
                (to_email, to_name, subject, html_content, text_content, priority, type, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 3, 'announcement', 'pending', NOW(), NOW())
            ");
            
            $stmt->execute([
                $recipient['email'],
                $recipient['name'],
                $subject,
                $htmlContent,
                $textContent
            ]);
            
            $emailsQueued++;
        }
        
        return $emailsQueued;
        
    } catch (Exception $e) {
        $logger->error("Failed to queue announcement emails", [
            'error' => $e->getMessage()
        ]);
        return 0;
    }
}

/**
 * Send announcement SMS to target audience
 */
function sendAnnouncementSMS($announcement, $targetDb, $logger) {
    // TODO: Implement SMS sending logic
    // This would integrate with your SMS service provider
    $logger->info("SMS sending not yet implemented");
    return 0;
}

/**
 * Get recipients for announcement based on target audience
 */
function getAnnouncementRecipients($announcement, $db) {
    $recipients = [];
    $targetAudience = $announcement['target_audience'];
    
    try {
        if ($targetAudience === 'all' || $targetAudience === 'students') {
            $stmt = $db->query("
                SELECT CONCAT(first_name, ' ', last_name) as name, email 
                FROM students 
                WHERE status = 'active' AND email IS NOT NULL
            ");
            $recipients = array_merge($recipients, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        if ($targetAudience === 'all' || $targetAudience === 'teachers') {
            $stmt = $db->query("
                SELECT CONCAT(first_name, ' ', last_name) as name, email 
                FROM teachers 
                WHERE status = 'active' AND email IS NOT NULL
            ");
            $recipients = array_merge($recipients, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        if ($targetAudience === 'all' || $targetAudience === 'parents') {
            $stmt = $db->query("
                SELECT CONCAT(first_name, ' ', last_name) as name, email 
                FROM parents 
                WHERE email IS NOT NULL
            ");
            $recipients = array_merge($recipients, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
        if ($targetAudience === 'all' || $targetAudience === 'admins') {
            $stmt = $db->query("
                SELECT name, email 
                FROM users 
                WHERE role = 'admin' AND email IS NOT NULL
            ");
            $recipients = array_merge($recipients, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        
    } catch (Exception $e) {
        // Table might not exist in platform database
        // This is expected for platform-wide announcements
    }
    
    return $recipients;
}
