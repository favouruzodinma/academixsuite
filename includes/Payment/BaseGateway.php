<?php
/**
 * Base Payment Gateway Class
 * Provides common functionality for all gateways
 */
namespace AcademixSuite\Payment;

abstract class BaseGateway implements PaymentGatewayInterface {
    
    protected $config;
    protected $mode;
    protected $testMode;
    protected $schoolId;
    protected $db;
    protected $logger;
    
    public function __construct(array $config, int $schoolId = null, bool $testMode = false) {
        $this->config = $config;
        $this->schoolId = $schoolId;
        $this->testMode = $testMode;
        $this->mode = $testMode ? 'test' : 'live';
        $this->db = \Database::getPlatformConnection();
        $this->logger = new \AcademixSuite\Helpers\PaymentLogger();
    }
    
    /**
     * Get gateway configuration
     * @return array Gateway configuration
     */
    protected function getConfig(): array {
        $configKey = $this->testMode ? 'test' : 'live';
        return $this->config[$configKey] ?? [];
    }
    
    /**
     * Make HTTP request to gateway API
     * @param string $url API endpoint
     * @param string $method HTTP method
     * @param array $data Request data
     * @param array $headers Additional headers
     * @return array Response data
     */
    protected function makeRequest(string $url, string $method = 'GET', array $data = [], array $headers = []): array {
        $ch = curl_init();
        
        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $allHeaders,
        ]);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->logger->logRequest($url, $method, $data, $response, $httpCode);
        
        if ($error) {
            throw new \Exception("CURL Error: " . $error);
        }
        
        $decodedResponse = json_decode($response, true) ?? [];
        
        return [
            'status' => $httpCode,
            'data' => $decodedResponse,
            'raw' => $response
        ];
    }
    
    /**
     * Save transaction to database
     * @param array $transactionData Transaction data
     * @return int Transaction ID
     */
    protected function saveTransaction(array $transactionData): int {
        $transactionData['created_at'] = date('Y-m-d H:i:s');
        $transactionData['updated_at'] = date('Y-m-d H:i:s');
        
        return \Database::insert($this->db, 'payment_transactions', $transactionData);
    }
    
    /**
     * Update transaction status
     * @param int $transactionId Transaction ID
     * @param array $updateData Data to update
     * @return bool Success status
     */
    protected function updateTransaction(int $transactionId, array $updateData): bool {
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        $affected = \Database::update(
            $this->db, 
            'payment_transactions', 
            $updateData, 
            'id = ?', 
            [$transactionId]
        );
        
        return $affected > 0;
    }
    
    /**
     * Generate unique transaction reference
     * @param string $prefix Reference prefix
     * @return string Unique reference
     */
    protected function generateReference(string $prefix = 'TX'): string {
        return $prefix . '_' . time() . '_' . strtoupper(bin2hex(random_bytes(4)));
    }
    
    /**
     * Format amount for gateway (e.g., convert to kobo for Paystack)
     * @param float $amount Amount to format
     * @return int Formatted amount
     */
    abstract protected function formatAmount(float $amount): int;
    
    /**
     * Get callback URL based on payment type
     * @param string $type Payment type (onboarding, subscription, fee, etc.)
     * @return string Callback URL
     */
    protected function getCallbackUrl(string $type): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'http://localhost';
        
        $urls = [
            'onboarding' => $baseUrl . '/webhooks/payment/callback',
            'subscription' => $baseUrl . '/webhooks/payment/callback',
            'fee' => $baseUrl . '/webhooks/payment/callback',
            'general' => $baseUrl . '/webhooks/payment/callback'
        ];
        
        return $urls[$type] ?? $urls['general'];
    }
    
    /**
     * Validate payment data before processing
     * @param array $data Payment data
     * @return array Validated data
     * @throws \Exception If validation fails
     */
    protected function validatePaymentData(array $data): array {
        $required = ['amount', 'email'];
        $missing = [];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new \Exception("Missing required fields: " . implode(', ', $missing));
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid email address");
        }
        
        if ($data['amount'] <= 0) {
            throw new \Exception("Amount must be greater than 0");
        }
        
        return $data;
    }
}