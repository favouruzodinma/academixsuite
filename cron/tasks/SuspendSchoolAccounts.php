<?php
/**
 * Suspend School Accounts Task
 * Automatically suspends school accounts based on various criteria
 */

class SuspendAccounts extends CronTask {
    
    private $config;
    private $suspendedCount = 0;
    
    public function __construct($db, $taskName) {
        parent::__construct($db, $taskName);
        $this->config = require __DIR__ . '/../config.php';
    }
    
    public function execute() {
        $this->logger->info("Starting school account suspension check");
        
        // Get schools that need to be suspended
        $schoolsToSuspend = $this->getSchoolsToSuspend();
        
        if (empty($schoolsToSuspend)) {
            $this->logger->info("No schools found requiring suspension");
            return true;
        }
        
        $this->logger->info("Found {count} schools to suspend", [
            'count' => count($schoolsToSuspend)
        ]);
        
        // Process each school
        foreach ($schoolsToSuspend as $school) {
            $this->suspendSchool($school);
        }
        
        $this->logger->success("Suspension check completed", [
            'total_checked' => count($schoolsToSuspend),
            'suspended' => $this->suspendedCount
        ]);
        
        return true;
    }
    
    /**
     * Get schools that need to be suspended
     */
    private function getSchoolsToSuspend() {
        $conditions = [];
        $params = [];
        
        // Check for expired trial accounts
        if ($this->config['suspension']['check_trial_expiry']) {
            $conditions[] = "(status = 'trial' AND trial_ends_at < NOW())";
        }
        
        // Check for expired subscriptions with grace period
        if ($this->config['suspension']['check_subscription_expiry']) {
            $gracePeriodDays = $this->config['suspension']['grace_period_days'];
            $conditions[] = "(status = 'active' AND subscription_ends_at IS NOT NULL AND subscription_ends_at < DATE_SUB(NOW(), INTERVAL {$gracePeriodDays} DAY))";
        }
        
        if (empty($conditions)) {
            return [];
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        $sql = "
            SELECT 
                id, name, email, status, trial_ends_at, subscription_ends_at,
                database_name
            FROM schools
            WHERE ({$whereClause})
            AND status IN ('trial', 'active')
        ";
        
        try {
            $stmt = $this->db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->logger->error("Failed to fetch schools for suspension", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Suspend a school account
     */
    private function suspendSchool($school) {
        try {
            // Check if already suspended (idempotent check)
            if ($school['status'] === 'suspended') {
                $this->logger->info("School already suspended, skipping", [
                    'school_id' => $school['id'],
                    'school_name' => $school['name']
                ]);
                return;
            }
            
            // Update school status to suspended
            $stmt = $this->db->prepare("
                UPDATE schools
                SET status = 'suspended',
                    suspended_at = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$school['id']]);
            
            $this->logger->info("School suspended", [
                'school_id' => $school['id'],
                'school_name' => $school['name'],
                'previous_status' => $school['status']
            ]);
            
            $this->suspendedCount++;
            
            // Send notification email
            if ($this->config['suspension']['send_notification']) {
                $this->sendSuspensionNotification($school);
            }
            
            // Log to audit trail
            $this->logAudit($school['id'], 'school_suspended', [
                'reason' => $this->getSuspensionReason($school),
                'previous_status' => $school['status']
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Failed to suspend school", [
                'school_id' => $school['id'],
                'school_name' => $school['name'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get suspension reason
     */
    private function getSuspensionReason($school) {
        if ($school['status'] === 'trial' && strtotime($school['trial_ends_at']) < time()) {
            return 'Trial period expired';
        }
        
        if ($school['subscription_ends_at'] && strtotime($school['subscription_ends_at']) < time()) {
            $gracePeriodDays = $this->config['suspension']['grace_period_days'];
            return "Subscription expired (grace period of {$gracePeriodDays} days exceeded)";
        }
        
        return 'Suspension criteria met';
    }
    
    /**
     * Send suspension notification email
     */
    private function sendSuspensionNotification($school) {
        $reason = $this->getSuspensionReason($school);
        
        $subject = "Account Suspended - " . $school['name'];
        
        $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #dc2626; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9fafb; }
                    .button { display: inline-block; padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                    .footer { padding: 20px; text-align: center; font-size: 12px; color: #6b7280; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Account Suspended</h1>
                    </div>
                    <div class='content'>
                        <p>Dear {$school['name']} Administrator,</p>
                        
                        <p>Your AcademixSuite account has been suspended.</p>
                        
                        <p><strong>Reason:</strong> {$reason}</p>
                        
                        <p>To reactivate your account, please:</p>
                        <ol>
                            <li>Log in to your account</li>
                            <li>Update your subscription</li>
                            <li>Complete the payment process</li>
                        </ol>
                        
                        <p style='text-align: center;'>
                            <a href='https://academixsuite.com/platform/billing' class='button'>Reactivate Account</a>
                        </p>
                        
                        <p>If you have any questions or believe this is an error, please contact our support team.</p>
                        
                        <p>Best regards,<br>The AcademixSuite Team</p>
                    </div>
                    <div class='footer'>
                        <p>© " . date('Y') . " AcademixSuite. All rights reserved.</p>
                        <p>This is an automated message, please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $this->sendEmail($school['email'], $subject, $body, $school['id']);
    }
    
    /**
     * Log to audit trail
     */
    private function logAudit($schoolId, $event, $data) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (school_id, event, new_values, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $schoolId,
                $event,
                json_encode($data)
            ]);
            
        } catch (Exception $e) {
            // Silently fail - don't want to break the suspension process
            $this->logger->warning("Failed to log audit entry", [
                'school_id' => $schoolId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
