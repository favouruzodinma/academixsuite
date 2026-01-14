<?php
/**
 * Paystack Webhook Handler
 */
namespace AcademixSuite\Gateway\Paystack;

class PaystackWebhook {
    
    private $api;
    private $db;
    private $secret;
    
    public function __construct(PaystackApi $api, string $webhookSecret) {
        $this->api = $api;
        $this->db = \Database::getPlatformConnection();
        $this->secret = $webhookSecret;
    }
    
    /**
     * Handle incoming webhook
     */
    public function handle(array $data, string $signature): array {
        try {
            // Validate signature
            $payload = json_encode($data);
            if (!$this->api->validateWebhookSignature($payload, $signature)) {
                throw new \Exception('Invalid webhook signature');
            }
            
            // Parse webhook data
            $parsed = $this->api->parseWebhookData($data);
            
            // Process based on event type
            $result = $this->processEvent($parsed);
            
            // Log webhook
            $this->logWebhook($parsed, $result);
            
            return [
                'success' => true,
                'processed' => true,
                'result' => $result
            ];
            
        } catch (\Exception $e) {
            $this->logError($e, $data);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => false
            ];
        }
    }
    
    /**
     * Process webhook event
     */
    private function processEvent(array $parsed): array {
        switch ($parsed['type']) {
            case 'payment_success':
                return $this->handlePaymentSuccess($parsed);
                
            case 'transfer_success':
                return $this->handleTransferSuccess($parsed);
                
            case 'refund_processed':
                return $this->handleRefundProcessed($parsed);
                
            case 'subscription_created':
                return $this->handleSubscriptionCreated($parsed);
                
            case 'invoice_payment_failed':
                return $this->handleInvoicePaymentFailed($parsed);
                
            default:
                return [
                    'status' => 'ignored',
                    'message' => 'Event type not handled: ' . $parsed['type']
                ];
        }
    }
    
    /**
     * Handle successful payment
     */
    private function handlePaymentSuccess(array $parsed): array {
        $reference = $parsed['reference'] ?? null;
        
        if (!$reference) {
            return ['status' => 'error', 'message' => 'No reference in payment data'];
        }
        
        // Verify the transaction
        $verification = $this->api->verifyTransaction($reference);
        
        if (!$verification['success']) {
            return ['status' => 'error', 'message' => 'Payment verification failed'];
        }
        
        // Update transaction in database
        $this->updateTransaction($reference, [
            'status' => 'success',
            'verified_at' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($verification['data'])
        ]);
        
        // Trigger payment success actions
        $this->triggerPaymentSuccessActions($reference, $verification['data']);
        
        return [
            'status' => 'success',
            'message' => 'Payment processed successfully',
            'reference' => $reference,
            'amount' => $parsed['amount']
        ];
    }
    
    /**
     * Handle successful transfer
     */
    private function handleTransferSuccess(array $parsed): array {
        // Process successful transfer (for settlements)
        return [
            'status' => 'success',
            'message' => 'Transfer completed successfully',
            'data' => $parsed['data']
        ];
    }
    
    /**
     * Handle processed refund
     */
    private function handleRefundProcessed(array $parsed): array {
        // Update refund status
        return [
            'status' => 'success',
            'message' => 'Refund processed',
            'data' => $parsed['data']
        ];
    }
    
    /**
     * Handle subscription creation
     */
    private function handleSubscriptionCreated(array $parsed): array {
        // Process new subscription
        return [
            'status' => 'success',
            'message' => 'Subscription created',
            'data' => $parsed['data']
        ];
    }
    
    /**
     * Handle failed invoice payment
     */
    private function handleInvoicePaymentFailed(array $parsed): array {
        // Handle failed invoice payments
        return [
            'status' => 'processed',
            'message' => 'Invoice payment failure recorded',
            'data' => $parsed['data']
        ];
    }
    
    /**
     * Update transaction in database
     */
    private function updateTransaction(string $reference, array $data): bool {
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        return \Database::update(
            $this->db,
            'payment_transactions',
            $data,
            'transaction_reference = ?',
            [$reference]
        ) > 0;
    }
    
    /**
     * Trigger payment success actions
     */
    private function triggerPaymentSuccessActions(string $reference, array $transactionData): void {
        // Get transaction metadata
        $transaction = \Database::select(
            $this->db,
            'payment_transactions',
            'metadata',
            'transaction_reference = ?',
            [$reference]
        );
        
        if (empty($transaction)) {
            return;
        }
        
        $metadata = json_decode($transaction[0]['metadata'], true) ?? [];
        $paymentType = $metadata['action'] ?? 'general';
        
        // Trigger appropriate actions based on payment type
        switch ($paymentType) {
            case 'onboarding':
                $this->triggerOnboardingSuccess($metadata['school_id'] ?? 0);
                break;
                
            case 'fee_payment':
                $this->triggerFeePaymentSuccess($metadata['batch_id'] ?? 0);
                break;
                
            case 'subscription_renewal':
                $this->triggerSubscriptionRenewal($metadata['subscription_id'] ?? 0);
                break;
        }
    }
    
    /**
     * Trigger onboarding success
     */
    private function triggerOnboardingSuccess(int $schoolId): void {
        // Activate school, create database, send welcome email, etc.
        \Database::update(
            $this->db,
            'schools',
            ['status' => 'active'],
            'id = ?',
            [$schoolId]
        );
    }
    
    /**
     * Trigger fee payment success
     */
    private function triggerFeePaymentSuccess(int $batchId): void {
        // Update invoices, send receipt, etc.
        \Database::update(
            $this->db,
            'batch_payments',
            ['status' => 'completed'],
            'id = ?',
            [$batchId]
        );
    }
    
    /**
     * Trigger subscription renewal
     */
    private function triggerSubscriptionRenewal(int $subscriptionId): void {
        // Update subscription, extend period, etc.
        \Database::update(
            $this->db,
            'subscriptions',
            ['status' => 'active'],
            'id = ?',
            [$subscriptionId]
        );
    }
    
    /**
     * Log webhook
     */
    private function logWebhook(array $parsed, array $result): void {
        $logDir = __DIR__ . '/../../../logs/webhooks/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $parsed['event'] ?? '',
            'type' => $parsed['type'] ?? '',
            'reference' => $parsed['reference'] ?? null,
            'result' => $result,
            'processed' => true
        ];
        
        $logFile = $logDir . 'paystack_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    }
    
    /**
     * Log error
     */
    private function logError(\Exception $e, array $data): void {
        $logDir = __DIR__ . '/../../../logs/webhooks/errors/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'error' => $e->getMessage(),
            'data' => $data,
            'trace' => $e->getTraceAsString()
        ];
        
        $logFile = $logDir . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    }
}