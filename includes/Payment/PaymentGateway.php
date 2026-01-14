<?php
/**
 * Payment Gateway Interface
 * Defines the contract for all payment gateway implementations
 */
namespace AcademixSuite\Payment;

interface PaymentGatewayInterface {
    
    /**
     * Initialize a payment transaction
     * @param array $data Payment data including amount, email, reference, etc.
     * @return array Gateway response with payment URL, reference, etc.
     */
    public function initializePayment(array $data): array;
    
    /**
     * Verify a payment transaction
     * @param string $reference Transaction reference
     * @return array Verification response with status, data, etc.
     */
    public function verifyPayment(string $reference): array;
    
    /**
     * Process refund for a transaction
     * @param string $transactionId Gateway transaction ID
     * @param float $amount Amount to refund
     * @param string $reason Reason for refund
     * @return array Refund response
     */
    public function refundPayment(string $transactionId, float $amount, string $reason = ''): array;
    
    /**
     * Get transaction details
     * @param string $reference Transaction reference
     * @return array Transaction details
     */
    public function getTransaction(string $reference): array;
    
    /**
     * List transactions
     * @param array $filters Filters for listing transactions
     * @return array List of transactions
     */
    public function listTransactions(array $filters = []): array;
    
    /**
     * Get supported currencies
     * @return array List of supported currencies
     */
    public function getSupportedCurrencies(): array;
    
    /**
     * Validate webhook signature
     * @param string $payload Webhook payload
     * @param string $signature Webhook signature
     * @return bool Whether signature is valid
     */
    public function validateWebhook(string $payload, string $signature): bool;
    
    /**
     * Process webhook
     * @param array $data Webhook data
     * @return array Processed webhook response
     */
    public function processWebhook(array $data): array;
    
    /**
     * Check if gateway is available
     * @return bool Whether gateway is available
     */
    public function isAvailable(): bool;
}