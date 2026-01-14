<?php
/**
 * Payment Processor
 * Handles payment processing logic and coordination
 */
namespace AcademixSuite\Payment;

class PaymentProcessor {
    
    private $gateway;
    private $db;
    private $logger;
    
    public function __construct(PaymentGatewayInterface $gateway) {
        $this->gateway = $gateway;
        $this->db = \Database::getPlatformConnection();
        $this->logger = new \AcademixSuite\Helpers\PaymentLogger();
    }
    
    /**
     * Process school onboarding payment
     * @param array $data Onboarding data
     * @return array Payment result
     */
    public function processSchoolOnboarding(array $data): array {
        try {
            $reference = 'ONBOARD_' . $data['school_id'] . '_' . time();
            
            $paymentData = [
                'type' => 'onboarding',
                'amount' => $data['amount'],
                'email' => $data['school_email'],
                'name' => $data['school_name'],
                'reference' => $reference,
                'metadata' => [
                    'school_id' => $data['school_id'],
                    'plan_id' => $data['plan_id'],
                    'action' => 'onboarding'
                ]
            ];
            
            $result = $this->gateway->initializePayment($paymentData);
            
            // Log the initiation
            $this->logger->logPaymentInitiation('onboarding', $data['school_id'], $reference, $data['amount']);
            
            return [
                'success' => true,
                'payment_url' => $result['data']['authorization_url'] ?? $result['data']['payment_url'] ?? null,
                'reference' => $reference,
                'message' => 'Onboarding payment initiated successfully'
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('onboarding_payment_failed', [
                'school_id' => $data['school_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process parent fee payment
     * @param array $data Parent payment data
     * @return array Payment result
     */
    public function processParentFeePayment(array $data): array {
        try {
            $reference = 'FEE_' . $data['parent_id'] . '_' . time() . '_' . substr(md5(uniqid()), 0, 6);
            
            // Calculate total amount from invoices
            $totalAmount = $this->calculateTotalAmount($data['invoice_ids']);
            
            // Create batch payment record
            $batchId = $this->createBatchPayment($data['parent_id'], $data['student_id'], $data['invoice_ids'], $totalAmount, $reference);
            
            $paymentData = [
                'type' => 'fee_payment',
                'amount' => $totalAmount,
                'email' => $data['parent_email'],
                'name' => $data['parent_name'],
                'reference' => $reference,
                'metadata' => [
                    'parent_id' => $data['parent_id'],
                    'student_id' => $data['student_id'],
                    'batch_id' => $batchId,
                    'invoice_ids' => $data['invoice_ids'],
                    'action' => 'fee_payment'
                ]
            ];
            
            $result = $this->gateway->initializePayment($paymentData);
            
            // Log the initiation
            $this->logger->logPaymentInitiation('fee_payment', $data['parent_id'], $reference, $totalAmount);
            
            return [
                'success' => true,
                'payment_url' => $result['data']['authorization_url'] ?? $result['data']['payment_url'] ?? null,
                'reference' => $reference,
                'batch_id' => $batchId,
                'message' => 'Fee payment initiated successfully'
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('fee_payment_failed', [
                'parent_id' => $data['parent_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process subscription payment
     * @param array $data Subscription data
     * @return array Payment result
     */
    public function processSubscriptionPayment(array $data): array {
        try {
            $reference = 'SUB_' . $data['subscription_id'] . '_' . time();
            
            $paymentData = [
                'type' => 'subscription',
                'amount' => $data['amount'],
                'email' => $data['school_email'],
                'name' => $data['school_name'],
                'reference' => $reference,
                'metadata' => [
                    'school_id' => $data['school_id'],
                    'subscription_id' => $data['subscription_id'],
                    'plan_id' => $data['plan_id'],
                    'action' => 'subscription_renewal'
                ]
            ];
            
            $result = $this->gateway->initializePayment($paymentData);
            
            // Log the initiation
            $this->logger->logPaymentInitiation('subscription', $data['school_id'], $reference, $data['amount']);
            
            return [
                'success' => true,
                'payment_url' => $result['data']['authorization_url'] ?? $result['data']['payment_url'] ?? null,
                'reference' => $reference,
                'message' => 'Subscription payment initiated successfully'
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('subscription_payment_failed', [
                'school_id' => $data['school_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify and process payment callback
     * @param string $reference Transaction reference
     * @param string $type Payment type
     * @return array Verification result
     */
    public function verifyAndProcessCallback(string $reference, string $type = 'general'): array {
        try {
            // Verify payment with gateway
            $verification = $this->gateway->verifyPayment($reference);
            
            if ($verification['status'] !== 'success') {
                return [
                    'success' => false,
                    'error' => 'Payment verification failed',
                    'details' => $verification
                ];
            }
            
            // Process based on payment type
            $transactionData = $this->getTransactionByReference($reference);
            
            if (!$transactionData) {
                throw new \Exception("Transaction not found for reference: $reference");
            }
            
            $metadata = json_decode($transactionData['metadata'] ?? '{}', true);
            
            switch ($type) {
                case 'onboarding':
                    $this->processOnboardingSuccess($metadata['school_id'] ?? 0, $reference);
                    break;
                    
                case 'fee_payment':
                    $this->processFeePaymentSuccess($metadata['batch_id'] ?? 0, $reference);
                    break;
                    
                case 'subscription':
                    $this->processSubscriptionSuccess($metadata['subscription_id'] ?? 0, $reference);
                    break;
            }
            
            $this->logger->logPaymentSuccess($type, $reference, $verification['transaction']['amount'] ?? 0);
            
            return [
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => $verification
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('callback_processing_failed', [
                'reference' => $reference,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process refund
     * @param int $transactionId Transaction ID
     * @param float $amount Amount to refund
     * @param string $reason Refund reason
     * @return array Refund result
     */
    public function processRefund(int $transactionId, float $amount, string $reason = ''): array {
        try {
            $transaction = $this->getTransactionById($transactionId);
            
            if (!$transaction) {
                throw new \Exception("Transaction not found");
            }
            
            $refundResult = $this->gateway->refundPayment(
                $transaction['gateway_transaction_id'],
                $amount,
                $reason
            );
            
            // Update transaction status
            $this->updateTransaction($transactionId, [
                'status' => 'refunded',
                'refunded_at' => date('Y-m-d H:i:s'),
                'refund_reason' => $reason
            ]);
            
            $this->logger->logRefund($transactionId, $amount, $reason);
            
            return [
                'success' => true,
                'message' => 'Refund processed successfully',
                'data' => $refundResult
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('refund_failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate total amount from invoice IDs
     * @param array $invoiceIds Array of invoice IDs
     * @return float Total amount
     */
    private function calculateTotalAmount(array $invoiceIds): float {
        if (empty($invoiceIds)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql = "SELECT SUM(balance_amount) as total FROM invoices WHERE id IN ($placeholders)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($invoiceIds);
        $result = $stmt->fetch();
        
        return (float)($result['total'] ?? 0);
    }
    
    /**
     * Create batch payment record
     */
    private function createBatchPayment(int $parentId, int $studentId, array $invoiceIds, float $totalAmount, string $reference): int {
        $batchData = [
            'school_id' => $this->getSchoolIdFromParent($parentId),
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'batch_reference' => $reference,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'metadata' => json_encode(['invoice_ids' => $invoiceIds]),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'batch_payments', $batchData);
    }
    
    /**
     * Process successful onboarding payment
     */
    private function processOnboardingSuccess(int $schoolId, string $reference): void {
        // Update school status
        \Database::update(
            $this->db,
            'schools',
            [
                'status' => 'active',
                'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+14 days')),
                'subscription_ends_at' => date('Y-m-d H:i:s', strtotime('+1 year'))
            ],
            'id = ?',
            [$schoolId]
        );
        
        // Create school database
        $school = \Database::select($this->db, 'schools', 'database_name', 'id = ?', [$schoolId]);
        if (!empty($school)) {
            \Database::createSchoolDatabase($school[0]['database_name']);
        }
        
        // Send welcome email
        $this->sendOnboardingSuccessEmail($schoolId);
    }
    
    /**
     * Process successful fee payment
     */
    private function processFeePaymentSuccess(int $batchId, string $reference): void {
        // Update batch payment status
        \Database::update(
            $this->db,
            'batch_payments',
            [
                'status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$batchId]
        );
        
        // Update invoices
        $batch = \Database::select($this->db, 'batch_payments', 'metadata', 'id = ?', [$batchId]);
        if (!empty($batch)) {
            $metadata = json_decode($batch[0]['metadata'], true);
            $invoiceIds = $metadata['invoice_ids'] ?? [];
            
            foreach ($invoiceIds as $invoiceId) {
                \Database::update(
                    $this->db,
                    'invoices',
                    [
                        'status' => 'paid',
                        'paid_at' => date('Y-m-d H:i:s')
                    ],
                    'id = ?',
                    [$invoiceId]
                );
            }
        }
        
        // Send receipt
        $this->sendPaymentReceipt($batchId);
    }
    
    /**
     * Process successful subscription payment
     */
    private function processSubscriptionSuccess(int $subscriptionId, string $reference): void {
        \Database::update(
            $this->db,
            'subscriptions',
            [
                'status' => 'active',
                'current_period_start' => date('Y-m-d H:i:s'),
                'current_period_end' => date('Y-m-d H:i:s', strtotime('+1 month'))
            ],
            'id = ?',
            [$subscriptionId]
        );
        
        // Send confirmation
        $this->sendSubscriptionConfirmation($subscriptionId);
    }
    
    /**
     * Get transaction by reference
     */
    private function getTransactionByReference(string $reference): ?array {
        $result = \Database::select(
            $this->db,
            'payment_transactions',
            '*',
            'transaction_reference = ?',
            [$reference]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get transaction by ID
     */
    private function getTransactionById(int $transactionId): ?array {
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
     * Update transaction
     */
    private function updateTransaction(int $transactionId, array $data): bool {
        return \Database::update(
            $this->db,
            'payment_transactions',
            $data,
            'id = ?',
            [$transactionId]
        ) > 0;
    }
    
    /**
     * Get school ID from parent ID
     */
    private function getSchoolIdFromParent(int $parentId): int {
        $result = \Database::select(
            $this->db,
            'parents',
            'school_id',
            'id = ?',
            [$parentId]
        );
        
        return (int)($result[0]['school_id'] ?? 0);
    }
    
    /**
     * Send onboarding success email
     */
    private function sendOnboardingSuccessEmail(int $schoolId): void {
        // Email implementation would go here
        // For now, just log it
        $this->logger->logEvent('onboarding_success_email_sent', ['school_id' => $schoolId]);
    }
    
    /**
     * Send payment receipt
     */
    private function sendPaymentReceipt(int $batchId): void {
        $this->logger->logEvent('payment_receipt_sent', ['batch_id' => $batchId]);
    }
    
    /**
     * Send subscription confirmation
     */
    private function sendSubscriptionConfirmation(int $subscriptionId): void {
        $this->logger->logEvent('subscription_confirmation_sent', ['subscription_id' => $subscriptionId]);
    }
}