<?php
/**
 * Payment Validator
 * Validates payment data and transactions
 */
namespace AcademixSuite\Payment;

class PaymentValidator {
    
    private $db;
    private $config;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->config = require __DIR__ . '/../../config/payment.php';
    }
    
    /**
     * Validate payment data
     * @param array $data Payment data
     * @return array Validation result
     */
    public function validatePaymentData(array $data): array {
        $errors = [];
        
        // Validate required fields
        $required = ['amount', 'email', 'type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }
        
        // Validate email
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        
        // Validate amount
        if (!empty($data['amount'])) {
            $amount = (float) $data['amount'];
            if ($amount <= 0) {
                $errors[] = "Amount must be greater than 0";
            }
            
            $minAmount = $this->config['fees']['minimum_amount'] ?? 100;
            if ($amount < $minAmount) {
                $errors[] = "Amount must be at least " . $minAmount;
            }
        }
        
        // Validate currency
        if (!empty($data['currency'])) {
            $supportedCurrencies = array_keys($this->config['currencies'] ?? []);
            if (!in_array(strtoupper($data['currency']), $supportedCurrencies)) {
                $errors[] = "Unsupported currency. Supported: " . implode(', ', $supportedCurrencies);
            }
        }
        
        // Validate payment type
        if (!empty($data['type'])) {
            $validTypes = ['onboarding', 'subscription', 'fee_payment', 'general'];
            if (!in_array($data['type'], $validTypes)) {
                $errors[] = "Invalid payment type. Valid types: " . implode(', ', $validTypes);
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Validate transaction reference
     * @param string $reference Transaction reference
     * @return bool Whether reference is valid
     */
    public function validateReference(string $reference): bool {
        if (empty($reference) || strlen($reference) < 10) {
            return false;
        }
        
        // Check if reference exists in database
        $result = \Database::select(
            $this->db,
            'payment_transactions',
            'COUNT(*) as count',
            'transaction_reference = ?',
            [$reference]
        );
        
        return ($result[0]['count'] ?? 0) > 0;
    }
    
    /**
     * Validate webhook data
     * @param array $data Webhook data
     * @param string $provider Gateway provider
     * @return array Validation result
     */
    public function validateWebhook(array $data, string $provider): array {
        $errors = [];
        
        // Common validation for all providers
        if (empty($data)) {
            $errors[] = "Webhook data is empty";
        }
        
        // Provider-specific validation
        switch ($provider) {
            case 'paystack':
                if (empty($data['event']) || empty($data['data'])) {
                    $errors[] = "Invalid Paystack webhook format";
                }
                break;
                
            case 'flutterwave':
                if (empty($data['event']) || empty($data['data'])) {
                    $errors[] = "Invalid Flutterwave webhook format";
                }
                break;
                
            case 'stripe':
                if (empty($data['type']) || empty($data['data'])) {
                    $errors[] = "Invalid Stripe webhook format";
                }
                break;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Check if transaction can be refunded
     * @param int $transactionId Transaction ID
     * @return array Refund eligibility
     */
    public function canRefundTransaction(int $transactionId): array {
        $transaction = $this->getTransaction($transactionId);
        
        if (!$transaction) {
            return [
                'can_refund' => false,
                'reason' => 'Transaction not found'
            ];
        }
        
        // Check if already refunded
        if ($transaction['status'] === 'refunded') {
            return [
                'can_refund' => false,
                'reason' => 'Transaction already refunded'
            ];
        }
        
        // Check if transaction was successful
        if ($transaction['status'] !== 'success') {
            return [
                'can_refund' => false,
                'reason' => 'Cannot refund unsuccessful transaction'
            ];
        }
        
        // Check refund window (30 days)
        $transactionTime = strtotime($transaction['created_at']);
        $currentTime = time();
        $daysDiff = ($currentTime - $transactionTime) / (60 * 60 * 24);
        
        if ($daysDiff > 30) {
            return [
                'can_refund' => false,
                'reason' => 'Refund window expired (30 days)'
            ];
        }
        
        return [
            'can_refund' => true,
            'max_amount' => (float) $transaction['amount'],
            'currency' => $transaction['currency']
        ];
    }
    
    /**
     * Validate school for payment
     * @param int $schoolId School ID
     * @return array Validation result
     */
    public function validateSchool(int $schoolId): array {
        $school = \Database::select(
            $this->db,
            'schools',
            'status, subscription_ends_at',
            'id = ?',
            [$schoolId]
        );
        
        if (empty($school)) {
            return [
                'valid' => false,
                'error' => 'School not found'
            ];
        }
        
        $school = $school[0];
        
        // Check if school is active
        if (!in_array($school['status'], ['active', 'trial'])) {
            return [
                'valid' => false,
                'error' => 'School is not active'
            ];
        }
        
        // Check subscription expiry
        if ($school['subscription_ends_at'] && strtotime($school['subscription_ends_at']) < time()) {
            return [
                'valid' => false,
                'error' => 'School subscription has expired'
            ];
        }
        
        return [
            'valid' => true
        ];
    }
    
    /**
     * Validate parent for payment
     * @param int $parentId Parent ID
     * @param int $studentId Student ID
     * @return array Validation result
     */
    public function validateParentForPayment(int $parentId, int $studentId): array {
        // Check if parent exists and is active
        $parent = \Database::select(
            $this->db,
            'parents',
            'is_active',
            'id = ?',
            [$parentId]
        );
        
        if (empty($parent)) {
            return [
                'valid' => false,
                'error' => 'Parent not found'
            ];
        }
        
        if (!$parent[0]['is_active']) {
            return [
                'valid' => false,
                'error' => 'Parent account is not active'
            ];
        }
        
        // Check if parent has access to student
        $relationship = \Database::select(
            $this->db,
            'guardians',
            'COUNT(*) as count',
            'user_id = ? AND student_id = ?',
            [$parentId, $studentId]
        );
        
        if (($relationship[0]['count'] ?? 0) === 0) {
            return [
                'valid' => false,
                'error' => 'Parent does not have access to this student'
            ];
        }
        
        return [
            'valid' => true
        ];
    }
    
    /**
     * Validate invoices for payment
     * @param array $invoiceIds Invoice IDs
     * @param int $studentId Student ID
     * @return array Validation result
     */
    public function validateInvoices(array $invoiceIds, int $studentId): array {
        if (empty($invoiceIds)) {
            return [
                'valid' => false,
                'error' => 'No invoices selected'
            ];
        }
        
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('pending', 'partial') THEN 1 ELSE 0 END) as payable 
                FROM invoices 
                WHERE id IN ($placeholders) AND student_id = ?";
        
        $params = array_merge($invoiceIds, [$studentId]);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        $total = (int) ($result['total'] ?? 0);
        $payable = (int) ($result['payable'] ?? 0);
        
        if ($total !== count($invoiceIds)) {
            return [
                'valid' => false,
                'error' => 'Some invoices do not exist or belong to a different student'
            ];
        }
        
        if ($payable === 0) {
            return [
                'valid' => false,
                'error' => 'No payable invoices selected (all are already paid or cancelled)'
            ];
        }
        
        return [
            'valid' => true,
            'payable_count' => $payable
        ];
    }
    
    /**
     * Get transaction details
     * @param int $transactionId Transaction ID
     * @return array|null Transaction data or null
     */
    private function getTransaction(int $transactionId): ?array {
        $result = \Database::select(
            $this->db,
            'payment_transactions',
            '*',
            'id = ?',
            [$transactionId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Sanitize payment data
     * @param array $data Payment data
     * @return array Sanitized data
     */
    public function sanitizePaymentData(array $data): array {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizePaymentData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}