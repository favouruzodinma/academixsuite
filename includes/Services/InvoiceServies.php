<?php
/**
 * Invoice Service
 * Handles invoice generation and management
 */
namespace AcademixSuite\Services;

class InvoiceService {
    
    private $db;
    private $config;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->config = require __DIR__ . '/../../config/payment.php';
    }
    
    /**
     * Create invoice
     */
    public function createInvoice(array $data): array {
        try {
            // Validate required data
            $validation = $this->validateInvoiceData($data);
            if (!$validation['valid']) {
                throw new \Exception(implode(', ', $validation['errors']));
            }
            
            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($data['school_id'] ?? 0);
            
            // Calculate due date
            $dueDate = $this->calculateDueDate($data['issue_date'] ?? date('Y-m-d'));
            
            // Create invoice
            $invoiceId = $this->saveInvoice([
                'school_id' => $data['school_id'],
                'invoice_number' => $invoiceNumber,
                'student_id' => $data['student_id'],
                'academic_year_id' => $data['academic_year_id'] ?? null,
                'academic_term_id' => $data['academic_term_id'] ?? null,
                'class_id' => $data['class_id'] ?? null,
                'issue_date' => $data['issue_date'] ?? date('Y-m-d'),
                'due_date' => $dueDate,
                'total_amount' => $data['total_amount'],
                'discount' => $data['discount'] ?? 0,
                'late_fee' => $data['late_fee'] ?? 0,
                'paid_amount' => 0,
                'balance_amount' => $data['total_amount'],
                'status' => 'pending',
                'notes' => $data['notes'] ?? '',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Add invoice items
            if (!empty($data['items'])) {
                $this->addInvoiceItems($invoiceId, $data['items']);
            }
            
            // Generate PDF if requested
            if ($data['generate_pdf'] ?? false) {
                $this->generateInvoicePdf($invoiceId);
            }
            
            // Send notification if requested
            if ($data['send_notification'] ?? false) {
                $this->sendInvoiceNotification($invoiceId);
            }
            
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'message' => 'Invoice created successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get invoice details
     */
    public function getInvoice(int $invoiceId): ?array {
        $invoice = $this->getInvoiceById($invoiceId);
        
        if (!$invoice) {
            return null;
        }
        
        // Get invoice items
        $items = $this->getInvoiceItems($invoiceId);
        
        // Get payment history
        $payments = $this->getInvoicePayments($invoiceId);
        
        // Get student details
        $student = $this->getStudentDetails($invoice['student_id']);
        
        // Calculate age of invoice
        $age = $this->calculateInvoiceAge($invoice['issue_date'], $invoice['due_date']);
        
        return [
            'invoice' => $invoice,
            'items' => $items,
            'payments' => $payments,
            'student' => $student,
            'age' => $age,
            'is_overdue' => $this->isInvoiceOverdue($invoice),
            'can_pay_online' => $this->canPayOnline($invoice)
        ];
    }
    
    /**
     * Update invoice status
     */
    public function updateInvoiceStatus(int $invoiceId, string $status, string $notes = ''): bool {
        $updates = ['status' => $status];
        
        if ($status === 'paid') {
            $updates['paid_at'] = date('Y-m-d H:i:s');
            $updates['paid_amount'] = $this->getInvoiceTotal($invoiceId);
            $updates['balance_amount'] = 0;
        } elseif ($status === 'cancelled') {
            $updates['cancelled_at'] = date('Y-m-d H:i:s');
        }
        
        if (!empty($notes)) {
            $updates['notes'] = $notes;
        }
        
        return \Database::update(
            $this->db,
            'invoices',
            $updates,
            'id = ?',
            [$invoiceId]
        ) > 0;
    }
    
    /**
     * Add payment to invoice
     */
    public function addPayment(int $invoiceId, array $paymentData): array {
        try {
            $invoice = $this->getInvoiceById($invoiceId);
            
            if (!$invoice) {
                throw new \Exception('Invoice not found');
            }
            
            // Validate payment amount
            $maxAmount = $invoice['balance_amount'];
            if ($paymentData['amount'] > $maxAmount) {
                throw new \Exception("Payment amount exceeds balance. Maximum: {$maxAmount}");
            }
            
            // Generate payment number
            $paymentNumber = $this->generatePaymentNumber();
            
            // Record payment
            $paymentId = \Database::insert($this->db, 'payments', [
                'school_id' => $invoice['school_id'],
                'invoice_id' => $invoiceId,
                'payment_number' => $paymentNumber,
                'student_id' => $invoice['student_id'],
                'amount' => $paymentData['amount'],
                'payment_method' => $paymentData['payment_method'],
                'payment_date' => $paymentData['payment_date'] ?? date('Y-m-d'),
                'collected_by' => $paymentData['collected_by'] ?? null,
                'bank_name' => $paymentData['bank_name'] ?? null,
                'cheque_number' => $paymentData['cheque_number'] ?? null,
                'transaction_id' => $paymentData['transaction_id'] ?? null,
                'reference' => $paymentData['reference'] ?? null,
                'notes' => $paymentData['notes'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            // Update invoice
            $newPaidAmount = $invoice['paid_amount'] + $paymentData['amount'];
            $newBalance = $invoice['total_amount'] - $newPaidAmount;
            
            $invoiceStatus = $newBalance <= 0 ? 'paid' : 
                           ($newPaidAmount > 0 ? 'partial' : 'pending');
            
            \Database::update($this->db, 'invoices', [
                'paid_amount' => $newPaidAmount,
                'balance_amount' => $newBalance,
                'status' => $invoiceStatus
            ], 'id = ?', [$invoiceId]);
            
            // Send receipt if online payment
            if ($paymentData['payment_method'] === 'online') {
                $this->sendPaymentReceipt($paymentId);
            }
            
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'payment_number' => $paymentNumber,
                'new_balance' => $newBalance,
                'invoice_status' => $invoiceStatus
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate invoice PDF
     */
    public function generateInvoicePdf(int $invoiceId): ?string {
        $invoice = $this->getInvoice($invoiceId);
        
        if (!$invoice) {
            return null;
        }
        
        try {
            $pdfGenerator = new \AcademixSuite\Helpers\PDFGenerator();
            $pdfContent = $pdfGenerator->generateInvoice($invoice);
            
            // Save PDF to file
            $filename = 'invoice_' . $invoice['invoice']['invoice_number'] . '_' . date('YmdHis') . '.pdf';
            $filepath = UPLOAD_PATH . '/invoices/pdf/' . $filename;
            
            file_put_contents($filepath, $pdfContent);
            
            // Update invoice with PDF path
            \Database::update(
                $this->db,
                'invoices',
                ['pdf_path' => $filepath],
                'id = ?',
                [$invoiceId]
            );
            
            return $filepath;
            
        } catch (\Exception $e) {
            error_log("PDF Generation Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send invoice reminder
     */
    public function sendInvoiceReminder(int $invoiceId, string $type = 'email'): array {
        $invoice = $this->getInvoice($invoiceId);
        
        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found'];
        }
        
        if ($invoice['invoice']['status'] === 'paid') {
            return ['success' => false, 'error' => 'Invoice is already paid'];
        }
        
        try {
            // Get parent contact
            $parent = $this->getParentForStudent($invoice['invoice']['student_id']);
            
            if (!$parent) {
                return ['success' => false, 'error' => 'Parent not found'];
            }
            
            switch ($type) {
                case 'email':
                    $this->sendEmailReminder($invoice, $parent);
                    break;
                    
                case 'sms':
                    $this->sendSmsReminder($invoice, $parent);
                    break;
                    
                case 'both':
                    $this->sendEmailReminder($invoice, $parent);
                    $this->sendSmsReminder($invoice, $parent);
                    break;
            }
            
            // Log the reminder
            $this->logReminder($invoiceId, $type);
            
            return [
                'success' => true,
                'message' => 'Reminder sent successfully',
                'sent_to' => [
                    'email' => $parent['email'],
                    'phone' => $parent['phone']
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
     * Bulk create invoices
     */
    public function bulkCreateInvoices(array $students, array $feeData): array {
        $results = [
            'success' => [],
            'failed' => []
        ];
        
        foreach ($students as $studentId) {
            $invoiceData = array_merge($feeData, ['student_id' => $studentId]);
            
            $result = $this->createInvoice($invoiceData);
            
            if ($result['success']) {
                $results['success'][] = [
                    'student_id' => $studentId,
                    'invoice_id' => $result['invoice_id'],
                    'invoice_number' => $result['invoice_number']
                ];
            } else {
                $results['failed'][] = [
                    'student_id' => $studentId,
                    'error' => $result['error']
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get outstanding invoices for student
     */
    public function getOutstandingInvoices(int $studentId): array {
        $invoices = \Database::select(
            $this->db,
            'invoices',
            '*',
            'student_id = ? AND status IN (?, ?)',
            [$studentId, 'pending', 'partial']
        );
        
        $totalOutstanding = 0;
        $formatted = [];
        
        foreach ($invoices as $invoice) {
            $totalOutstanding += $invoice['balance_amount'];
            
            $formatted[] = [
                'id' => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'issue_date' => $invoice['issue_date'],
                'due_date' => $invoice['due_date'],
                'total_amount' => (float) $invoice['total_amount'],
                'paid_amount' => (float) $invoice['paid_amount'],
                'balance_amount' => (float) $invoice['balance_amount'],
                'status' => $invoice['status'],
                'is_overdue' => strtotime($invoice['due_date']) < time()
            ];
        }
        
        return [
            'invoices' => $formatted,
            'total_outstanding' => $totalOutstanding,
            'count' => count($formatted)
        ];
    }
    
    /**
     * Validate invoice data
     */
    private function validateInvoiceData(array $data): array {
        $errors = [];
        
        $required = ['school_id', 'student_id', 'total_amount'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }
        
        if (!empty($data['total_amount']) && $data['total_amount'] <= 0) {
            $errors[] = "Total amount must be greater than 0";
        }
        
        if (!empty($data['discount']) && $data['discount'] < 0) {
            $errors[] = "Discount cannot be negative";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber(int $schoolId): string {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');
        $sequence = $this->getNextInvoiceSequence($schoolId);
        
        return sprintf('%s-%s%s-%04d-%06d', $prefix, $year, $month, $schoolId, $sequence);
    }
    
    /**
     * Calculate due date
     */
    private function calculateDueDate(string $issueDate): string {
        $dueDays = $this->config['invoice']['due_days'] ?? 30;
        return date('Y-m-d', strtotime($issueDate . " +{$dueDays} days"));
    }
    
    /**
     * Save invoice
     */
    private function saveInvoice(array $data): int {
        return \Database::insert($this->db, 'invoices', $data);
    }
    
    /**
     * Add invoice items
     */
    private function addInvoiceItems(int $invoiceId, array $items): void {
        foreach ($items as $item) {
            \Database::insert($this->db, 'invoice_items', [
                'invoice_id' => $invoiceId,
                'fee_category_id' => $item['fee_category_id'],
                'description' => $item['description'] ?? '',
                'amount' => $item['amount'],
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Get invoice by ID
     */
    private function getInvoiceById(int $invoiceId): ?array {
        $result = \Database::select($this->db, 'invoices', '*', 'id = ?', [$invoiceId]);
        return $result[0] ?? null;
    }
    
    /**
     * Get invoice items
     */
    private function getInvoiceItems(int $invoiceId): array {
        return \Database::select(
            $this->db,
            'invoice_items',
            'ii.*, fc.name as fee_category',
            'ii.invoice_id = ?',
            [$invoiceId],
            'ii.id ASC',
            0,
            0,
            'invoice_items ii LEFT JOIN fee_categories fc ON ii.fee_category_id = fc.id'
        );
    }
    
    /**
     * Get invoice payments
     */
    private function getInvoicePayments(int $invoiceId): array {
        return \Database::select(
            $this->db,
            'payments',
            '*',
            'invoice_id = ?',
            [$invoiceId],
            'payment_date DESC'
        );
    }
    
    /**
     * Get student details
     */
    private function getStudentDetails(int $studentId): ?array {
        $result = \Database::select(
            $this->db,
            'students',
            'first_name, last_name, admission_number, class_id',
            'id = ?',
            [$studentId]
        );
        
        if (empty($result)) {
            return null;
        }
        
        $student = $result[0];
        
        // Get class name
        $class = \Database::select(
            $this->db,
            'classes',
            'name',
            'id = ?',
            [$student['class_id']]
        );
        
        $student['class_name'] = $class[0]['name'] ?? '';
        
        return $student;
    }
    
    /**
     * Calculate invoice age
     */
    private function calculateInvoiceAge(string $issueDate, string $dueDate): array {
        $issueTimestamp = strtotime($issueDate);
        $dueTimestamp = strtotime($dueDate);
        $currentTimestamp = time();
        
        $daysSinceIssue = floor(($currentTimestamp - $issueTimestamp) / (60 * 60 * 24));
        $daysUntilDue = floor(($dueTimestamp - $currentTimestamp) / (60 * 60 * 24));
        $isOverdue = $currentTimestamp > $dueTimestamp;
        $overdueDays = $isOverdue ? floor(($currentTimestamp - $dueTimestamp) / (60 * 60 * 24)) : 0;
        
        return [
            'days_since_issue' => $daysSinceIssue,
            'days_until_due' => $daysUntilDue,
            'is_overdue' => $isOverdue,
            'overdue_days' => $overdueDays
        ];
    }
    
    /**
     * Check if invoice is overdue
     */
    private function isInvoiceOverdue(array $invoice): bool {
        return strtotime($invoice['due_date']) < time() && 
               !in_array($invoice['status'], ['paid', 'cancelled']);
    }
    
    /**
     * Check if invoice can be paid online
     */
    private function canPayOnline(array $invoice): bool {
        return $invoice['status'] !== 'paid' && 
               $invoice['status'] !== 'cancelled' &&
               $invoice['balance_amount'] > 0;
    }
    
    /**
     * Get invoice total
     */
    private function getInvoiceTotal(int $invoiceId): float {
        $invoice = $this->getInvoiceById($invoiceId);
        return (float) ($invoice['total_amount'] ?? 0);
    }
    
    /**
     * Get next invoice sequence
     */
    private function getNextInvoiceSequence(int $schoolId): int {
        $year = date('Y');
        $month = date('m');
        
        $sql = "SELECT COUNT(*) as count FROM invoices 
                WHERE school_id = ? AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$schoolId, $year, $month]);
        $result = $stmt->fetch();
        
        return (int) ($result['count'] ?? 0) + 1;
    }
    
    /**
     * Generate payment number
     */
    private function generatePaymentNumber(): string {
        $prefix = 'PMT';
        $date = date('Ymd');
        $random = strtoupper(bin2hex(random_bytes(3)));
        
        return $prefix . '-' . $date . '-' . $random;
    }
    
    /**
     * Get parent for student
     */
    private function getParentForStudent(int $studentId): ?array {
        $sql = "SELECT p.* FROM parents p
                JOIN guardians g ON p.id = g.user_id
                WHERE g.student_id = ? AND g.is_primary = 1
                LIMIT 1";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        $result = $stmt->fetch();
        
        return $result ?: null;
    }
    
    /**
     * Send email reminder
     */
    private function sendEmailReminder(array $invoice, array $parent): void {
        $mailer = new \AcademixSuite\Helpers\Mailer();
        
        $data = [
            'invoice' => $invoice['invoice'],
            'student' => $invoice['student'],
            'parent' => $parent,
            'due_date' => $invoice['invoice']['due_date'],
            'balance_amount' => $invoice['invoice']['balance_amount'],
            'payment_url' => $this->generatePaymentLink($invoice['invoice']['id'])
        ];
        
        $mailer->sendTemplate('invoice-reminder', $parent['email'], $data);
    }
    
    /**
     * Send SMS reminder
     */
    private function sendSmsReminder(array $invoice, array $parent): void {
        if (empty($parent['phone'])) {
            return;
        }
        
        $sms = new \AcademixSuite\Helpers\SmsService();
        
        $message = "Dear Parent, Invoice {$invoice['invoice']['invoice_number']} for " .
                  "{$invoice['student']['first_name']} is due on {$invoice['invoice']['due_date']}. " .
                  "Balance: {$invoice['invoice']['balance_amount']}. " .
                  "Pay online: " . $this->generatePaymentLink($invoice['invoice']['id']);
        
        $sms->send($parent['phone'], $message);
    }
    
    /**
     * Send payment receipt
     */
    private function sendPaymentReceipt(int $paymentId): void {
        // Implementation for sending payment receipts
    }
    
    /**
     * Send invoice notification
     */
    private function sendInvoiceNotification(int $invoiceId): void {
        // Implementation for sending invoice notifications
    }
    
    /**
     * Generate payment link
     */
    private function generatePaymentLink(int $invoiceId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/pay/invoice/' . $invoiceId;
    }
    
    /**
     * Log reminder
     */
    private function logReminder(int $invoiceId, string $type): void {
        \Database::insert($this->db, 'invoice_reminders', [
            'invoice_id' => $invoiceId,
            'type' => $type,
            'sent_at' => date('Y-m-d H:i:s')
        ]);
    }
}