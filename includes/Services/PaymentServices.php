<?php
/**
 * Payment Service
 * Main service for handling all payment operations
 */
namespace AcademixSuite\Services;

use AcademixSuite\Payment\PaymentFactory;
use AcademixSuite\Payment\PaymentProcessor;
use AcademixSuite\Payment\PaymentValidator;

class PaymentService {
    
    private $db;
    private $config;
    private $validator;
    private $logger;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->config = require __DIR__ . '/../../config/payment.php';
        $this->validator = new PaymentValidator();
        $this->logger = new \AcademixSuite\Helpers\PaymentLogger();
        
        // Initialize payment factory
        PaymentFactory::init($this->config);
    }
    
    /**
     * Initialize payment
     */
    public function initializePayment(array $data): array {
        try {
            // Validate input data
            $validation = $this->validator->validatePaymentData($data);
            if (!$validation['valid']) {
                throw new \Exception(implode(', ', $validation['errors']));
            }
            
            // Sanitize data
            $sanitizedData = $this->validator->sanitizePaymentData($data);
            
            // Get payment gateway
            $gateway = $this->getGatewayForPayment($sanitizedData);
            
            // Create payment processor
            $processor = new PaymentProcessor($gateway);
            
            // Process based on payment type
            switch ($sanitizedData['type']) {
                case 'onboarding':
                    return $processor->processSchoolOnboarding($sanitizedData);
                    
                case 'subscription':
                    return $processor->processSubscriptionPayment($sanitizedData);
                    
                case 'fee_payment':
                    return $processor->processParentFeePayment($sanitizedData);
                    
                default:
                    return $this->processGeneralPayment($sanitizedData, $gateway);
            }
            
        } catch (\Exception $e) {
            $this->logger->logError('payment_initialization_failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify payment
     */
    public function verifyPayment(string $reference, string $type = 'general'): array {
        try {
            // Validate reference
            if (!$this->validator->validateReference($reference)) {
                throw new \Exception('Invalid transaction reference');
            }
            
            // Get transaction to determine gateway
            $transaction = $this->getTransactionByReference($reference);
            if (!$transaction) {
                throw new \Exception('Transaction not found');
            }
            
            // Get gateway
            $gateway = PaymentFactory::create(
                $this->getGatewayProvider($transaction['payment_gateway_id']),
                $transaction['school_id'],
                $this->isTestTransaction($transaction)
            );
            
            // Create processor and verify
            $processor = new PaymentProcessor($gateway);
            return $processor->verifyAndProcessCallback($reference, $type);
            
        } catch (\Exception $e) {
            $this->logger->logError('payment_verification_failed', [
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
     */
    public function processRefund(int $transactionId, float $amount, string $reason = ''): array {
        try {
            // Check if refund is possible
            $refundCheck = $this->validator->canRefundTransaction($transactionId);
            if (!$refundCheck['can_refund']) {
                throw new \Exception($refundCheck['reason']);
            }
            
            // Validate amount
            if ($amount > $refundCheck['max_amount']) {
                throw new \Exception('Refund amount exceeds transaction amount');
            }
            
            // Get transaction
            $transaction = $this->getTransactionById($transactionId);
            
            // Get gateway
            $gateway = PaymentFactory::create(
                $this->getGatewayProvider($transaction['payment_gateway_id']),
                $transaction['school_id'],
                $this->isTestTransaction($transaction)
            );
            
            // Create processor and process refund
            $processor = new PaymentProcessor($gateway);
            return $processor->processRefund($transactionId, $amount, $reason);
            
        } catch (\Exception $e) {
            $this->logger->logError('refund_processing_failed', [
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
     * Get transaction details
     */
    public function getTransactionDetails(string $reference): ?array {
        $transaction = $this->getTransactionByReference($reference);
        
        if (!$transaction) {
            return null;
        }
        
        // Format transaction data
        return [
            'id' => $transaction['id'],
            'reference' => $transaction['transaction_reference'],
            'amount' => (float) $transaction['amount'],
            'currency' => $transaction['currency'],
            'status' => $transaction['status'],
            'payer_email' => $transaction['payer_email'],
            'payer_name' => $transaction['payer_name'],
            'created_at' => $transaction['created_at'],
            'verified_at' => $transaction['verified_at'],
            'metadata' => json_decode($transaction['metadata'] ?? '{}', true)
        ];
    }
    
    /**
     * Get payment history
     */
    public function getPaymentHistory(int $schoolId = null, array $filters = [], int $page = 1, int $perPage = 20): array {
        $where = '1=1';
        $params = [];
        
        if ($schoolId) {
            $where .= ' AND school_id = ?';
            $params[] = $schoolId;
        }
        
        // Apply filters
        if (!empty($filters['status'])) {
            $where .= ' AND status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['date_from'])) {
            $where .= ' AND DATE(created_at) >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where .= ' AND DATE(created_at) <= ?';
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $where .= ' AND (transaction_reference LIKE ? OR payer_email LIKE ? OR payer_name LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        // Calculate offset
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $total = \Database::count($this->db, 'payment_transactions', $where, $params);
        
        // Get transactions
        $transactions = \Database::select(
            $this->db,
            'payment_transactions',
            '*',
            $where,
            $params,
            'created_at DESC',
            $perPage,
            $offset
        );
        
        // Format transactions
        $formatted = [];
        foreach ($transactions as $transaction) {
            $formatted[] = [
                'id' => $transaction['id'],
                'reference' => $transaction['transaction_reference'],
                'amount' => (float) $transaction['amount'],
                'currency' => $transaction['currency'],
                'status' => $transaction['status'],
                'payer' => [
                    'email' => $transaction['payer_email'],
                    'name' => $transaction['payer_name']
                ],
                'created_at' => $transaction['created_at'],
                'verified_at' => $transaction['verified_at']
            ];
        }
        
        return [
            'transactions' => $formatted,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ];
    }
    
    /**
     * Generate payment report
     */
    public function generateReport(int $schoolId = null, string $period = 'monthly'): array {
        $where = 'status = "success"';
        $params = [];
        
        if ($schoolId) {
            $where .= ' AND school_id = ?';
            $params[] = $schoolId;
        }
        
        // Determine date range based on period
        $dateRange = $this->getDateRange($period);
        $where .= ' AND created_at >= ? AND created_at <= ?';
        $params = array_merge($params, [$dateRange['start'], $dateRange['end']]);
        
        // Get summary data
        $summarySql = "SELECT 
                COUNT(*) as total_transactions,
                SUM(amount) as total_amount,
                AVG(amount) as average_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount,
                COUNT(DISTINCT payer_email) as unique_payers
            FROM payment_transactions 
            WHERE $where";
        
        $stmt = $this->db->prepare($summarySql);
        $stmt->execute($params);
        $summary = $stmt->fetch();
        
        // Get daily breakdown
        $dailySql = "SELECT 
                DATE(created_at) as date,
                COUNT(*) as transaction_count,
                SUM(amount) as daily_amount
            FROM payment_transactions 
            WHERE $where
            GROUP BY DATE(created_at)
            ORDER BY date";
        
        $stmt = $this->db->prepare($dailySql);
        $stmt->execute($params);
        $dailyBreakdown = $stmt->fetchAll();
        
        // Get payment method breakdown
        $methodSql = "SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(amount) as amount
            FROM payment_transactions 
            WHERE $where AND payment_method IS NOT NULL
            GROUP BY payment_method
            ORDER BY amount DESC";
        
        $stmt = $this->db->prepare($methodSql);
        $stmt->execute($params);
        $methodBreakdown = $stmt->fetchAll();
        
        return [
            'period' => $period,
            'date_range' => $dateRange,
            'summary' => $summary,
            'daily_breakdown' => $dailyBreakdown,
            'method_breakdown' => $methodBreakdown,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Process webhook
     */
    public function processWebhook(string $provider, array $data, string $signature): array {
        try {
            // Validate webhook data
            $validation = $this->validator->validateWebhook($data, $provider);
            if (!$validation['valid']) {
                throw new \Exception(implode(', ', $validation['errors']));
            }
            
            // Get gateway and process webhook
            $gateway = PaymentFactory::create($provider);
            $result = $gateway->processWebhook($data);
            
            $this->logger->logWebhook($provider, $data, $result);
            
            return [
                'success' => true,
                'processed' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            $this->logger->logError('webhook_processing_failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => false
            ];
        }
    }
    
    /**
     * Get gateway for payment
     */
    private function getGatewayForPayment(array $data): \AcademixSuite\Payment\PaymentGatewayInterface {
        $schoolId = $data['school_id'] ?? null;
        $provider = $data['gateway'] ?? $this->config['default_gateway'];
        
        // Check if school has specific gateway configuration
        if ($schoolId) {
            $schoolGateway = PaymentFactory::getSchoolGatewayConfig($schoolId, $provider);
            if ($schoolGateway) {
                return PaymentFactory::create($provider, $schoolId, $schoolGateway['mode'] === 'test');
            }
        }
        
        // Use default gateway
        return PaymentFactory::getDefault($schoolId, APP_ENV === 'development');
    }
    
    /**
     * Process general payment
     */
    private function processGeneralPayment(array $data, \AcademixSuite\Payment\PaymentGatewayInterface $gateway): array {
        $reference = $data['reference'] ?? $this->generateReference('GEN');
        
        $paymentData = [
            'amount' => $data['amount'],
            'email' => $data['email'],
            'reference' => $reference,
            'metadata' => $data['metadata'] ?? []
        ];
        
        if (!empty($data['name'])) {
            $paymentData['name'] = $data['name'];
        }
        
        if (!empty($data['phone'])) {
            $paymentData['phone'] = $data['phone'];
        }
        
        if (!empty($data['currency'])) {
            $paymentData['currency'] = $data['currency'];
        }
        
        $result = $gateway->initializePayment($paymentData);
        
        return [
            'success' => true,
            'payment_url' => $result['data']['authorization_url'] ?? $result['data']['payment_url'] ?? null,
            'reference' => $reference,
            'message' => 'Payment initialized successfully'
        ];
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
     * Get gateway provider from gateway ID
     */
    private function getGatewayProvider(int $gatewayId): string {
        $result = \Database::select(
            $this->db,
            'payment_gateways',
            'provider',
            'id = ?',
            [$gatewayId]
        );
        
        return $result[0]['provider'] ?? 'paystack';
    }
    
    /**
     * Check if transaction is test mode
     */
    private function isTestTransaction(array $transaction): bool {
        // Check gateway mode
        $gateway = \Database::select(
            $this->db,
            'payment_gateways',
            'mode',
            'id = ?',
            [$transaction['payment_gateway_id']]
        );
        
        return ($gateway[0]['mode'] ?? 'live') === 'test';
    }
    
    /**
     * Generate reference
     */
    private function generateReference(string $prefix = 'TX'): string {
        return $prefix . '_' . time() . '_' . strtoupper(bin2hex(random_bytes(4)));
    }
    
    /**
     * Get date range for report
     */
    private function getDateRange(string $period): array {
        $end = date('Y-m-d 23:59:59');
        
        switch ($period) {
            case 'daily':
                $start = date('Y-m-d 00:00:00');
                break;
                
            case 'weekly':
                $start = date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
                
            case 'monthly':
                $start = date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
                
            case 'quarterly':
                $start = date('Y-m-d 00:00:00', strtotime('-90 days'));
                break;
                
            case 'yearly':
                $start = date('Y-m-d 00:00:00', strtotime('-365 days'));
                break;
                
            default:
                $start = date('Y-m-d 00:00:00', strtotime('-30 days'));
        }
        
        return ['start' => $start, 'end' => $end];
    }
}