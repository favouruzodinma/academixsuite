<?php
/**
 * Paystack API Client
 */
namespace AcademixSuite\Gateway\Paystack;

class PaystackApi {
    
    private $secretKey;
    private $publicKey;
    private $baseUrl = 'https://api.paystack.co';
    private $timeout = 30;
    
    public function __construct(string $secretKey, string $publicKey = '', bool $testMode = false) {
        $this->secretKey = $secretKey;
        $this->publicKey = $publicKey;
        
        if ($testMode) {
            // Test mode specific configuration if needed
        }
    }
    
    /**
     * Initialize transaction
     */
    public function initializeTransaction(array $data): array {
        return $this->makeRequest('/transaction/initialize', 'POST', $data);
    }
    
    /**
     * Verify transaction
     */
    public function verifyTransaction(string $reference): array {
        return $this->makeRequest('/transaction/verify/' . urlencode($reference), 'GET');
    }
    
    /**
     * List transactions
     */
    public function listTransactions(array $params = []): array {
        $query = http_build_query($params);
        $url = '/transaction' . ($query ? '?' . $query : '');
        return $this->makeRequest($url, 'GET');
    }
    
    /**
     * Charge authorization
     */
    public function chargeAuthorization(array $data): array {
        return $this->makeRequest('/transaction/charge_authorization', 'POST', $data);
    }
    
    /**
     * Create transfer recipient
     */
    public function createTransferRecipient(array $data): array {
        return $this->makeRequest('/transferrecipient', 'POST', $data);
    }
    
    /**
     * Initiate transfer
     */
    public function initiateTransfer(array $data): array {
        return $this->makeRequest('/transfer', 'POST', $data);
    }
    
    /**
     * Create subscription
     */
    public function createSubscription(array $data): array {
        return $this->makeRequest('/subscription', 'POST', $data);
    }
    
    /**
     * Send invoice
     */
    public function sendInvoice(array $data): array {
        return $this->makeRequest('/paymentrequest', 'POST', $data);
    }
    
    /**
     * Make API request
     */
    private function makeRequest(string $endpoint, string $method = 'GET', array $data = []): array {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Cache-Control: no-cache'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        
        if ($method === 'POST' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Paystack API Error: " . $error);
        }
        
        $decoded = json_decode($response, true) ?? [];
        
        // Log the request for debugging
        $this->logRequest($endpoint, $method, $data, $decoded, $httpCode);
        
        return [
            'status' => $httpCode,
            'success' => $decoded['status'] ?? false,
            'message' => $decoded['message'] ?? '',
            'data' => $decoded['data'] ?? []
        ];
    }
    
    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool {
        $computedSignature = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Parse webhook data
     */
    public function parseWebhookData(array $data): array {
        $event = $data['event'] ?? '';
        $webhookData = $data['data'] ?? [];
        
        $parsed = [
            'event' => $event,
            'type' => $this->mapEventToType($event),
            'data' => $webhookData,
            'timestamp' => $data['createdAt'] ?? date('Y-m-d\TH:i:s\Z')
        ];
        
        // Extract common fields
        if (!empty($webhookData)) {
            $parsed['reference'] = $webhookData['reference'] ?? null;
            $parsed['amount'] = isset($webhookData['amount']) ? $webhookData['amount'] / 100 : null;
            $parsed['currency'] = $webhookData['currency'] ?? null;
            $parsed['status'] = $webhookData['status'] ?? null;
            $parsed['gateway_response'] = $webhookData['gateway_response'] ?? null;
        }
        
        return $parsed;
    }
    
    /**
     * Map event to type
     */
    private function mapEventToType(string $event): string {
        $mapping = [
            'charge.success' => 'payment_success',
            'transfer.success' => 'transfer_success',
            'transfer.failed' => 'transfer_failed',
            'transfer.reversed' => 'transfer_reversed',
            'refund.processed' => 'refund_processed',
            'subscription.create' => 'subscription_created',
            'subscription.disable' => 'subscription_disabled',
            'invoice.create' => 'invoice_created',
            'invoice.update' => 'invoice_updated',
            'invoice.payment_failed' => 'invoice_payment_failed'
        ];
        
        return $mapping[$event] ?? 'unknown';
    }
    
    /**
     * Log request for debugging
     */
    private function logRequest(string $endpoint, string $method, array $request, array $response, int $httpCode): void {
        $logDir = __DIR__ . '/../../../logs/paystack/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'method' => $method,
            'request' => $request,
            'response' => $response,
            'http_code' => $httpCode
        ];
        
        $logFile = $logDir . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry, JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND);
    }
}