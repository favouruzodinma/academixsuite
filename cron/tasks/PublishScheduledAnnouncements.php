<?php
/**
 * Publish Scheduled Announcements Task
 * Publishes announcements at their scheduled time
 */

class PublishAnnouncements extends CronTask {
    
    private $config;
    private $publishedCount = 0;
    
    public function __construct($db, $taskName) {
        parent::__construct($db, $taskName);
        $this->config = require __DIR__ . '/../config.php';
    }
    
    public function execute() {
        $this->logger->info("Starting scheduled announcement publishing");
        
        // Get announcements ready to publish
        $announcements = $this->getAnnouncementsToPublish();
        
        if (empty($announcements)) {
            $this->logger->info("No announcements ready to publish");
            return true;
        }
        
        $this->logger->info("Publishing {count} announcements", [
            'count' => count($announcements)
        ]);
        
        // Process each announcement
        foreach ($announcements as $announcement) {
            $this->publishAnnouncement($announcement);
        }
        
        $this->logger->success("Announcement publishing completed", [
            'total_processed' => count($announcements),
            'published' => $this->publishedCount
        ]);
        
        return true;
    }
    
    /**
     * Get announcements ready to publish
     */
    private function getAnnouncementsToPublish() {
        try {
            $batchSize = $this->config['announcements']['batch_size'];
            
            $stmt = $this->db->prepare("
                SELECT *
                FROM scheduled_announcements
                WHERE status = 'scheduled'
                AND publish_at <= NOW()
                ORDER BY publish_at ASC
                LIMIT ?
            ");
            
            $stmt->execute([$batchSize]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to fetch announcements", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Publish a single announcement
     */
    private function publishAnnouncement($announcement) {
        try {
            // Check if already published (idempotent check)
            if ($announcement['status'] === 'published') {
                $this->logger->info("Announcement already published, skipping", [
                    'id' => $announcement['id'],
                    'title' => $announcement['title']
                ]);
                return;
            }
            
            $this->logger->info("Publishing announcement", [
                'id' => $announcement['id'],
                'school_id' => $announcement['school_id'],
                'title' => $announcement['title'],
                'scheduled_at' => $announcement['publish_at']
            ]);
            
            // Get school database connection
            $schoolDb = $this->getSchoolConnection($announcement['school_id']);
            
            // Insert announcement into school's announcements table
            $stmt = $schoolDb->prepare("
                INSERT INTO announcements (title, content, status, published_at, created_by, created_at)
                VALUES (?, ?, 'published', NOW(), ?, NOW())
            ");
            
            $stmt->execute([
                $announcement['title'],
                $announcement['content'],
                $announcement['created_by']
            ]);
            
            $announcementId = $schoolDb->lastInsertId();
            
            // Update scheduled_announcements status
            $stmt = $this->db->prepare("
                UPDATE scheduled_announcements
                SET status = 'published',
                    published_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$announcement['id']]);
            
            $this->publishedCount++;
            
            $this->logger->success("Announcement published successfully", [
                'id' => $announcement['id'],
                'school_id' => $announcement['school_id'],
                'announcement_id' => $announcementId,
                'title' => $announcement['title']
            ]);
            
            // Optionally send notification to users
            if (isset($announcement['notify_users']) && $announcement['notify_users']) {
                $this->notifyUsers($announcement, $schoolDb);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Failed to publish announcement", [
                'id' => $announcement['id'],
                'title' => $announcement['title'],
                'error' => $e->getMessage()
            ]);
            
            // Mark as failed
            try {
                $stmt = $this->db->prepare("
                    UPDATE scheduled_announcements
                    SET status = 'failed',
                        error_message = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([$e->getMessage(), $announcement['id']]);
            } catch (Exception $updateError) {
                // Silently fail
            }
        }
    }
    
    /**
     * Notify users about new announcement
     */
    private function notifyUsers($announcement, $schoolDb) {
        try {
            // Get school info
            $stmt = $this->db->prepare("SELECT name, email FROM schools WHERE id = ?");
            $stmt->execute([$announcement['school_id']]);
            $school = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$school) {
                return;
            }
            
            // Get users to notify (e.g., all students, teachers, parents)
            // This is a simplified version - you can customize based on your needs
            $stmt = $schoolDb->query("
                SELECT email FROM students WHERE status = 'active'
                UNION
                SELECT email FROM teachers WHERE status = 'active'
            ");
            
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($users)) {
                return;
            }
            
            // Queue notification emails
            $emailQueue = new EmailQueue($this->db, $this->logger);
            
            foreach ($users as $email) {
                $subject = "New Announcement: " . $announcement['title'];
                $body = $this->createNotificationEmail($announcement, $school);
                
                $emailQueue->enqueue(
                    $email,
                    $subject,
                    $body,
                    $announcement['school_id']
                );
            }
            
            $this->logger->info("Queued announcement notifications", [
                'announcement_id' => $announcement['id'],
                'recipient_count' => count($users)
            ]);
            
        } catch (Exception $e) {
            $this->logger->warning("Failed to notify users about announcement", [
                'announcement_id' => $announcement['id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create notification email
     */
    private function createNotificationEmail($announcement, $school) {
        return "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #3b82f6; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9fafb; }
                    .announcement { background: white; padding: 20px; border-left: 4px solid #3b82f6; margin: 20px 0; }
                    .footer { padding: 20px; text-align: center; font-size: 12px; color: #6b7280; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>New Announcement</h1>
                    </div>
                    <div class='content'>
                        <p>Hello,</p>
                        
                        <p>A new announcement has been posted by {$school['name']}:</p>
                        
                        <div class='announcement'>
                            <h2>{$announcement['title']}</h2>
                            <div>" . nl2br(htmlspecialchars($announcement['content'])) . "</div>
                        </div>
                        
                        <p>Log in to your account to view more details.</p>
                        
                        <p>Best regards,<br>{$school['name']}</p>
                    </div>
                    <div class='footer'>
                        <p>© " . date('Y') . " {$school['name']}. All rights reserved.</p>
                        <p>This is an automated message from your school management system.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }
}
