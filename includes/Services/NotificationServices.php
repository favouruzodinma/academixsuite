<?php
/**
 * Notification Service
 * Handles sending notifications via email, SMS, and in-app
 */
namespace AcademixSuite\Services;

class NotificationService {
    
    private $db;
    private $mailer;
    private $sms;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->mailer = new \AcademixSuite\Helpers\Mailer();
        $this->sms = new \AcademixSuite\Helpers\SmsService();
    }
    
    /**
     * Send payment receipt
     */
    public function sendPaymentReceipt(int $paymentId, array $channels = ['email']): array {
        try {
            // Get payment details
            $payment = $this->getPaymentDetails($paymentId);
            
            if (!$payment) {
                throw new \Exception('Payment not found');
            }
            
            $results = [];
            
            foreach ($channels as $channel) {
                switch ($channel) {
                    case 'email':
                        $result = $this->sendPaymentReceiptEmail($payment);
                        $results['email'] = $result;
                        break;
                        
                    case 'sms':
                        $result = $this->sendPaymentReceiptSms($payment);
                        $results['sms'] = $result;
                        break;
                        
                    case 'in_app':
                        $result = $this->createInAppNotification($payment);
                        $results['in_app'] = $result;
                        break;
                }
            }
            
            // Log notification
            $this->logNotification('payment_receipt', $payment['parent_id'], $results);
            
            return [
                'success' => true,
                'results' => $results,
                'message' => 'Receipt sent successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send invoice notification
     */
    public function sendInvoiceNotification(int $invoiceId, array $channels = ['email']): array {
        try {
            $invoice = $this->getInvoiceDetails($invoiceId);
            
            if (!$invoice) {
                throw new \Exception('Invoice not found');
            }
            
            $results = [];
            
            foreach ($channels as $channel) {
                switch ($channel) {
                    case 'email':
                        $result = $this->sendInvoiceEmail($invoice);
                        $results['email'] = $result;
                        break;
                        
                    case 'sms':
                        $result = $this->sendInvoiceSms($invoice);
                        $results['sms'] = $result;
                        break;
                }
            }
            
            // Log notification
            $this->logNotification('invoice_issued', $invoice['parent_id'], $results);
            
            return [
                'success' => true,
                'results' => $results,
                'message' => 'Invoice notification sent'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send payment reminder
     */
    public function sendPaymentReminder(int $invoiceId, string $type = 'due_soon'): array {
        try {
            $invoice = $this->getInvoiceDetails($invoiceId);
            
            if (!$invoice) {
                throw new \Exception('Invoice not found');
            }
            
            if ($invoice['status'] === 'paid') {
                throw new \Exception('Invoice is already paid');
            }
            
            $daysUntilDue = $this->getDaysUntilDue($invoice['due_date']);
            
            if ($daysUntilDue > 7 && $type === 'overdue') {
                throw new \Exception('Invoice is not overdue yet');
            }
            
            $template = $this->getReminderTemplate($type, $daysUntilDue);
            
            // Send email
            $emailResult = $this->sendReminderEmail($invoice, $template);
            
            // Send SMS if phone available
            $smsResult = null;
            if (!empty($invoice['parent_phone'])) {
                $smsResult = $this->sendReminderSms($invoice, $template);
            }
            
            // Create in-app notification
            $inAppResult = $this->createReminderNotification($invoice, $template);
            
            // Log reminder
            $this->logReminder($invoiceId, $type, [
                'email' => $emailResult,
                'sms' => $smsResult,
                'in_app' => $inAppResult
            ]);
            
            return [
                'success' => true,
                'message' => 'Payment reminder sent successfully',
                'sent_to' => [
                    'email' => $invoice['parent_email'],
                    'phone' => $invoice['parent_phone']
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send batch payment confirmation
     */
    public function sendBatchPaymentConfirmation(int $batchId): array {
        try {
            $batch = $this->getBatchPaymentDetails($batchId);
            
            if (!$batch) {
                throw new \Exception('Batch payment not found');
            }
            
            $data = [
                'batch' => $batch,
                'student' => $batch['student'],
                'parent' => $batch['parent'],
                'invoices' => $batch['invoices'],
                'payment_date' => date('Y-m-d H:i:s')
            ];
            
            // Send email
            $this->mailer->sendTemplate('batch-payment-confirmation', $batch['parent_email'], $data);
            
            // Send SMS if phone available
            if (!empty($batch['parent_phone'])) {
                $message = "Payment of {$batch['total_amount']} for {$batch['student_name']} confirmed. " .
                          "Reference: {$batch['batch_reference']}. Thank you!";
                $this->sms->send($batch['parent_phone'], $message);
            }
            
            return [
                'success' => true,
                'message' => 'Batch payment confirmation sent'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send enrollment status update
     */
    public function sendEnrollmentStatusUpdate(int $requestId, string $status): array {
        try {
            $request = $this->getEnrollmentRequest($requestId);
            
            if (!$request) {
                throw new \Exception('Enrollment request not found');
            }
            
            $data = [
                'request' => $request,
                'status' => ucfirst($status),
                'update_date' => date('Y-m-d'),
                'next_steps' => $this->getEnrollmentNextSteps($status)
            ];
            
            if ($status === 'accepted') {
                $data['payment_url'] = $this->generatePaymentUrl($requestId);
            }
            
            $this->mailer->sendTemplate('enrollment-status-update', $request['school_email'], $data);
            
            return [
                'success' => true,
                'message' => 'Status update sent successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send school activation notification
     */
    public function sendSchoolActivationNotification(int $schoolId): array {
        try {
            $school = $this->getSchoolDetails($schoolId);
            
            if (!$school) {
                throw new \Exception('School not found');
            }
            
            $data = [
                'school' => $school,
                'activation_date' => date('Y-m-d'),
                'login_url' => $this->generateSchoolLoginUrl($school['slug']),
                'admin_email' => $school['admin_email'],
                'support_contact' => 'support@academixsuite.com'
            ];
            
            $this->mailer->sendTemplate('school-activation', $school['admin_email'], $data);
            
            return [
                'success' => true,
                'message' => 'School activation notification sent'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send subscription renewal reminder
     */
    public function sendSubscriptionRenewalReminder(int $schoolId, int $daysBefore = 7): array {
        try {
            $school = $this->getSchoolDetails($schoolId);
            $subscription = $this->getSchoolSubscription($schoolId);
            
            if (!$school || !$subscription) {
                throw new \Exception('School or subscription not found');
            }
            
            $renewalDate = $subscription['current_period_end'];
            $daysUntilRenewal = $this->getDaysUntilDate($renewalDate);
            
            if ($daysUntilRenewal > $daysBefore) {
                throw new \Exception("Renewal is more than {$daysBefore} days away");
            }
            
            $data = [
                'school' => $school,
                'subscription' => $subscription,
                'renewal_date' => $renewalDate,
                'days_until_renewal' => $daysUntilRenewal,
                'renewal_url' => $this->generateRenewalUrl($schoolId)
            ];
            
            $this->mailer->sendTemplate('subscription-renewal-reminder', $school['admin_email'], $data);
            
            return [
                'success' => true,
                'message' => 'Subscription renewal reminder sent'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send system alert to admins
     */
    public function sendSystemAlert(string $type, string $message, array $data = []): array {
        try {
            $admins = $this->getPlatformAdmins();
            
            if (empty($admins)) {
                throw new \Exception('No platform admins found');
            }
            
            $alertData = [
                'type' => $type,
                'message' => $message,
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s'),
                'priority' => $this->getAlertPriority($type)
            ];
            
            foreach ($admins as $admin) {
                $this->mailer->sendTemplate('system-alert', $admin['email'], $alertData);
            }
            
            // Also create in-app notifications
            foreach ($admins as $admin) {
                $this->createSystemAlertNotification($admin['id'], $type, $message, $data);
            }
            
            return [
                'success' => true,
                'message' => 'System alert sent to admins',
                'recipients' => count($admins)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Send welcome email to new parent
     */
    public function sendParentWelcomeEmail(int $parentId): array {
        try {
            $parent = $this->getParentDetails($parentId);
            
            if (!$parent) {
                throw new \Exception('Parent not found');
            }
            
            $data = [
                'parent' => $parent,
                'welcome_date' => date('Y-m-d'),
                'login_url' => $this->generateParentLoginUrl(),
                'student_count' => $this->getParentStudentCount($parentId),
                'support_contact' => $parent['school_email']
            ];
            
            $this->mailer->sendTemplate('parent-welcome', $parent['email'], $data);
            
            return [
                'success' => true,
                'message' => 'Welcome email sent to parent'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get payment details
     */
    private function getPaymentDetails(int $paymentId): ?array {
        $sql = "SELECT 
                p.*,
                i.invoice_number,
                s.first_name as student_first_name,
                s.last_name as student_last_name,
                pr.name as parent_name,
                pr.email as parent_email,
                pr.phone as parent_phone,
                sc.name as school_name
            FROM payments p
            JOIN invoices i ON p.invoice_id = i.id
            JOIN students s ON p.student_id = s.id
            JOIN parents pr ON p.parent_id = pr.id
            JOIN schools sc ON p.school_id = sc.id
            WHERE p.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$paymentId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get invoice details
     */
    private function getInvoiceDetails(int $invoiceId): ?array {
        $sql = "SELECT 
                i.*,
                s.first_name as student_first_name,
                s.last_name as student_last_name,
                pr.id as parent_id,
                pr.name as parent_name,
                pr.email as parent_email,
                pr.phone as parent_phone,
                sc.name as school_name
            FROM invoices i
            JOIN students s ON i.student_id = s.id
            JOIN parents pr ON s.parent_id = pr.id
            JOIN schools sc ON i.school_id = sc.id
            WHERE i.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$invoiceId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get batch payment details
     */
    private function getBatchPaymentDetails(int $batchId): ?array {
        $sql = "SELECT 
                bp.*,
                s.first_name as student_first_name,
                s.last_name as student_last_name,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                p.name as parent_name,
                p.email as parent_email,
                p.phone as parent_phone,
                sc.name as school_name
            FROM batch_payments bp
            JOIN students s ON bp.student_id = s.id
            JOIN parents p ON bp.parent_id = p.id
            JOIN schools sc ON bp.school_id = sc.id
            WHERE bp.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$batchId]);
        
        $batch = $stmt->fetch();
        
        if (!$batch) {
            return null;
        }
        
        // Get invoices in batch
        $metadata = json_decode($batch['metadata'], true);
        $invoiceIds = $metadata['invoice_ids'] ?? [];
        
        if (!empty($invoiceIds)) {
            $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
            $invoiceSql = "SELECT * FROM invoices WHERE id IN ($placeholders)";
            $invoiceStmt = $this->db->prepare($invoiceSql);
            $invoiceStmt->execute($invoiceIds);
            $batch['invoices'] = $invoiceStmt->fetchAll();
        }
        
        return $batch;
    }
    
    /**
     * Get enrollment request
     */
    private function getEnrollmentRequest(int $requestId): ?array {
        $result = \Database::select(
            $this->db,
            'enrollment_requests',
            '*',
            'id = ?',
            [$requestId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get school details
     */
    private function getSchoolDetails(int $schoolId): ?array {
        $sql = "SELECT 
                s.*,
                sa.email as admin_email
            FROM schools s
            LEFT JOIN school_admins sa ON s.id = sa.school_id AND sa.role = 'owner'
            WHERE s.id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$schoolId]);
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Get school subscription
     */
    private function getSchoolSubscription(int $schoolId): ?array {
        $result = \Database::select(
            $this->db,
            'subscriptions',
            '*',
            'school_id = ? AND status = ?',
            [$schoolId, 'active']
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get platform admins
     */
    private function getPlatformAdmins(): array {
        return \Database::select(
            $this->db,
            'platform_users',
            '*',
            'role IN (?, ?) AND is_active = 1',
            ['super_admin', 'admin']
        );
    }
    
    /**
     * Get parent details
     */
    private function getParentDetails(int $parentId): ?array {
        $result = \Database::select(
            $this->db,
            'parents',
            '*',
            'id = ?',
            [$parentId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get days until due
     */
    private function getDaysUntilDue(string $dueDate): int {
        $dueTimestamp = strtotime($dueDate);
        $currentTimestamp = time();
        
        return floor(($dueTimestamp - $currentTimestamp) / (60 * 60 * 24));
    }
    
    /**
     * Get days until date
     */
    private function getDaysUntilDate(string $date): int {
        $dateTimestamp = strtotime($date);
        $currentTimestamp = time();
        
        return floor(($dateTimestamp - $currentTimestamp) / (60 * 60 * 24));
    }
    
    /**
     * Get reminder template
     */
    private function getReminderTemplate(string $type, int $daysUntilDue): array {
        $templates = [
            'due_soon' => [
                'subject' => 'Payment Due Soon',
                'message' => 'Your payment is due in {days} days.',
                'urgency' => 'medium'
            ],
            'due_today' => [
                'subject' => 'Payment Due Today',
                'message' => 'Your payment is due today.',
                'urgency' => 'high'
            ],
            'overdue' => [
                'subject' => 'Payment Overdue',
                'message' => 'Your payment is {days} days overdue.',
                'urgency' => 'critical'
            ]
        ];
        
        $template = $templates[$type] ?? $templates['due_soon'];
        $template['message'] = str_replace('{days}', abs($daysUntilDue), $template['message']);
        
        return $template;
    }
    
    /**
     * Get enrollment next steps
     */
    private function getEnrollmentNextSteps(string $status): string {
        $steps = [
            'pending' => 'Your application is being processed. We will contact you soon.',
            'reviewing' => 'Your application is under review. This may take 2-3 business days.',
            'accepted' => 'Congratulations! Please proceed to payment to activate your school.',
            'rejected' => 'We regret to inform you that your application was not accepted.',
            'waitlisted' => 'Your application has been added to our waitlist.',
            'completed' => 'Your school setup is complete. You can now log in.'
        ];
        
        return $steps[$status] ?? 'We will contact you with next steps.';
    }
    
    /**
     * Get alert priority
     */
    private function getAlertPriority(string $type): string {
        $priorities = [
            'payment_failed' => 'high',
            'subscription_expired' => 'high',
            'system_error' => 'critical',
            'security_alert' => 'critical',
            'maintenance' => 'medium',
            'update' => 'low'
        ];
        
        return $priorities[$type] ?? 'medium';
    }
    
    /**
     * Get parent student count
     */
    private function getParentStudentCount(int $parentId): int {
        $result = \Database::select(
            $this->db,
            'students',
            'COUNT(*) as count',
            'parent_id = ? AND status = ?',
            [$parentId, 'active']
        );
        
        return (int) ($result[0]['count'] ?? 0);
    }
    
    /**
     * Send payment receipt email
     */
    private function sendPaymentReceiptEmail(array $payment): bool {
        $data = [
            'payment' => $payment,
            'receipt_date' => date('Y-m-d'),
            'download_url' => $this->generateReceiptUrl($payment['id'])
        ];
        
        return $this->mailer->sendTemplate('payment-receipt', $payment['parent_email'], $data);
    }
    
    /**
     * Send payment receipt SMS
     */
    private function sendPaymentReceiptSms(array $payment): bool {
        if (empty($payment['parent_phone'])) {
            return false;
        }
        
        $message = "Payment receipt for {$payment['student_first_name']}. " .
                  "Amount: {$payment['amount']}. " .
                  "Reference: {$payment['payment_number']}. " .
                  "Date: {$payment['payment_date']}";
        
        return $this->sms->send($payment['parent_phone'], $message);
    }
    
    /**
     * Create in-app notification
     */
    private function createInAppNotification(array $payment): bool {
        $notification = [
            'user_id' => $payment['parent_id'],
            'user_type' => 'parent',
            'type' => 'payment_receipt',
            'title' => 'Payment Receipt',
            'message' => "Payment of {$payment['amount']} for {$payment['student_first_name']} has been confirmed.",
            'data' => json_encode([
                'payment_id' => $payment['id'],
                'invoice_id' => $payment['invoice_id'],
                'amount' => $payment['amount']
            ]),
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'notifications', $notification) > 0;
    }
    
    /**
     * Send invoice email
     */
    private function sendInvoiceEmail(array $invoice): bool {
        $data = [
            'invoice' => $invoice,
            'due_date' => $invoice['due_date'],
            'amount' => $invoice['total_amount'],
            'payment_url' => $this->generateInvoicePaymentUrl($invoice['id'])
        ];
        
        return $this->mailer->sendTemplate('invoice-issued', $invoice['parent_email'], $data);
    }
    
    /**
     * Send invoice SMS
     */
    private function sendInvoiceSms(array $invoice): bool {
        if (empty($invoice['parent_phone'])) {
            return false;
        }
        
        $message = "New invoice for {$invoice['student_first_name']}. " .
                  "Amount: {$invoice['total_amount']}. " .
                  "Due: {$invoice['due_date']}. " .
                  "Pay: " . $this->generateInvoicePaymentUrl($invoice['id']);
        
        return $this->sms->send($invoice['parent_phone'], $message);
    }
    
    /**
     * Send reminder email
     */
    private function sendReminderEmail(array $invoice, array $template): bool {
        $data = [
            'invoice' => $invoice,
            'template' => $template,
            'due_date' => $invoice['due_date'],
            'balance' => $invoice['balance_amount'],
            'payment_url' => $this->generateInvoicePaymentUrl($invoice['id'])
        ];
        
        return $this->mailer->sendTemplate('payment-reminder', $invoice['parent_email'], $data);
    }
    
    /**
     * Send reminder SMS
     */
    private function sendReminderSms(array $invoice, array $template): bool {
        if (empty($invoice['parent_phone'])) {
            return false;
        }
        
        $message = "Reminder: {$template['message']} " .
                  "Invoice: {$invoice['invoice_number']}. " .
                  "Balance: {$invoice['balance_amount']}. " .
                  "Pay: " . $this->generateInvoicePaymentUrl($invoice['id']);
        
        return $this->sms->send($invoice['parent_phone'], $message);
    }
    
    /**
     * Create reminder notification
     */
    private function createReminderNotification(array $invoice, array $template): bool {
        $notification = [
            'user_id' => $invoice['parent_id'],
            'user_type' => 'parent',
            'type' => 'payment_reminder',
            'title' => $template['subject'],
            'message' => $template['message'] . " Invoice: {$invoice['invoice_number']}",
            'data' => json_encode([
                'invoice_id' => $invoice['id'],
                'due_date' => $invoice['due_date'],
                'balance' => $invoice['balance_amount']
            ]),
            'priority' => $template['urgency'],
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'notifications', $notification) > 0;
    }
    
    /**
     * Create system alert notification
     */
    private function createSystemAlertNotification(int $adminId, string $type, string $message, array $data): bool {
        $notification = [
            'user_id' => $adminId,
            'user_type' => 'platform_admin',
            'type' => 'system_alert',
            'title' => ucfirst(str_replace('_', ' ', $type)),
            'message' => $message,
            'data' => json_encode($data),
            'priority' => $this->getAlertPriority($type),
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'notifications', $notification) > 0;
    }
    
    /**
     * Log notification
     */
    private function logNotification(string $type, int $recipientId, array $results): void {
        \Database::insert($this->db, 'notification_logs', [
            'type' => $type,
            'recipient_id' => $recipientId,
            'results' => json_encode($results),
            'sent_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Log reminder
     */
    private function logReminder(int $invoiceId, string $type, array $results): void {
        \Database::insert($this->db, 'invoice_reminders', [
            'invoice_id' => $invoiceId,
            'type' => $type,
            'sent_at' => date('Y-m-d H:i:s'),
            'results' => json_encode($results)
        ]);
    }
    
    /**
     * Generate receipt URL
     */
    private function generateReceiptUrl(int $paymentId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/receipt/' . $paymentId;
    }
    
    /**
     * Generate invoice payment URL
     */
    private function generateInvoicePaymentUrl(int $invoiceId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/pay/invoice/' . $invoiceId;
    }
    
    /**
     * Generate payment URL
     */
    private function generatePaymentUrl(int $requestId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/enrollment/payment/' . $requestId;
    }
    
    /**
     * Generate school login URL
     */
    private function generateSchoolLoginUrl(string $slug): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/tenant/' . $slug . '/login';
    }
    
    /**
     * Generate renewal URL
     */
    private function generateRenewalUrl(int $schoolId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/subscription/renew/' . $schoolId;
    }
    
    /**
     * Generate parent login URL
     */
    private function generateParentLoginUrl(): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/parent-portal/login';
    }
}