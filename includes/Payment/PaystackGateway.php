<?php
/**
 * Paystack Payment Gateway Implementation
 */
namespace AcademixSuite\Payment;

class PaystackGateway extends BaseGateway {
    
    protected $baseUrl;
    protected $publicKey;
    protected $secretKey;
    
    public function __construct(array $config, int $schoolId = null, bool $testMode = false) {
        parent::__construct($config, $schoolId, $testMode);
        
        $modeConfig = $this->getConfig();
        $this->publicKey = $modeConfig['public_key'] ?? '';
        $this->secretKey = $modeConfig['secret_key'] ?? '';
        $this->baseUrl = $modeConfig['base_url'] ?? 'https://api.paystack.co';
        
        if (empty($this->secretKey)) {
            throw new \Exception("Paystack secret key is not configured");
        }
    }
    
    public function initializePayment(array $data): array {
        $validatedData = $this->validatePaymentData($data);
        
        $amount = $this->formatAmount($validatedData['amount']);
        $reference = $validatedData['reference'] ?? $this->generateReference('PSK');
        
        $payload = [
            'email' => $validatedData['email'],
            'amount' => $amount,
            'reference' => $reference,
            'callback_url' => $validatedData['callback_url'] ?? $this->getCallbackUrl($validatedData['type'] ?? 'general'),
            'metadata' => array_merge(
                $validatedData['metadata'] ?? [],
                [
                    'school_id' => $this->schoolId,
                    'payment_type' => $validatedData['type'] ?? 'general',
                    'platform' => 'AcademixSuite'
                ]
            )
        ];
        
        // Add optional fields
        if (!empty($validatedData['name'])) {
            $payload['name'] = $validatedData['name'];
        }
        
        if (!empty($validatedData['phone'])) {
            $payload['phone'] = $validatedData['phone'];
        }
        
        if (!empty($validatedData['currency'])) {
            $payload['currency'] = $validatedData['currency'];
        }
        
        $response = $this->makeRequest(
            $this->baseUrl . '/transaction/initialize',
            'POST',
            $payload,
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200 || !$response['data']['status']) {
            throw new \Exception("Paystack initialization failed: " . 
                ($response['data']['message'] ?? 'Unknown error'));
        }
        
        // Save transaction
        $transactionId = $this->saveTransaction([
            'school_id' => $this->schoolId,
            'payment_gateway_id' => $this->getGatewayId(),
            'transaction_reference' => $reference,
            'amount' => $validatedData['amount'],
            'currency' => $validatedData['currency'] ?? 'NGN',
            'payer_email' => $validatedData['email'],
            'payer_name' => $validatedData['name'] ?? '',
            'status' => 'initiated',
            'metadata' => json_encode($payload['metadata']),
            'gateway_response' => json_encode($response['data'])
        ]);
        
        return [
            'status' => 'success',
            'message' => 'Payment initialized successfully',
            'data' => [
                'authorization_url' => $response['data']['data']['authorization_url'],
                'access_code' => $response['data']['data']['access_code'],
                'reference' => $reference,
                'transaction_id' => $transactionId
            ]
        ];
    }
    
    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest(
            $this->baseUrl . '/transaction/verify/' . urlencode($reference),
            'GET',
            [],
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200) {
            throw new \Exception("Paystack verification failed: " . 
                ($response['data']['message'] ?? 'HTTP ' . $response['status']));
        }
        
        $transactionData = $response['data']['data'] ?? [];
        
        // Update transaction in database
        $this->updateTransactionByReference($reference, [
            'gateway_transaction_id' => $transactionData['id'] ?? null,
            'payment_method' => $transactionData['channel'] ?? null,
            'card_last_four' => $transactionData['authorization']['last4'] ?? null,
            'bank_name' => $transactionData['authorization']['bank'] ?? null,
            'status' => $transactionData['status'] === 'success' ? 'success' : 'failed',
            'verified_at' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($transactionData)
        ]);
        
        return [
            'status' => $transactionData['status'] === 'success' ? 'success' : 'failed',
            'message' => $response['data']['message'] ?? '',
            'data' => $transactionData,
            'transaction' => [
                'amount' => $transactionData['amount'] / 100,
                'currency' => $transactionData['currency'] ?? 'NGN',
                'paid_at' => $transactionData['paid_at'] ?? null,
                'reference' => $transactionData['reference'] ?? $reference
            ]
        ];
    }
    
    public function refundPayment(string $transactionId, float $amount, string $reason = ''): array {
        $payload = [
            'transaction' => $transactionId,
            'amount' => $this->formatAmount($amount)
        ];
        
        if (!empty($reason)) {
            $payload['customer_note'] = $reason;
        }
        
        $response = $this->makeRequest(
            $this->baseUrl . '/refund',
            'POST',
            $payload,
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200 || !$response['data']['status']) {
            throw new \Exception("Refund failed: " . 
                ($response['data']['message'] ?? 'Unknown error'));
        }
        
        return [
            'status' => 'success',
            'message' => 'Refund initiated successfully',
            'data' => $response['data']['data']
        ];
    }
    
    public function getTransaction(string $reference): array {
        return $this->verifyPayment($reference);
    }
    
    public function listTransactions(array $filters = []): array {
        $query = http_build_query($filters);
        $url = $this->baseUrl . '/transaction' . ($query ? '?' . $query : '');
        
        $response = $this->makeRequest(
            $url,
            'GET',
            [],
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        return [
            'status' => $response['status'] === 200 ? 'success' : 'error',
            'data' => $response['data']['data'] ?? [],
            'meta' => $response['data']['meta'] ?? []
        ];
    }
    
    public function getSupportedCurrencies(): array {
        return ['NGN', 'GHS', 'USD', 'ZAR'];
    }
    
    public function validateWebhook(string $payload, string $signature): bool {
        $computedSignature = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($computedSignature, $signature);
    }
    
    public function processWebhook(array $data): array {
        $event = $data['event'] ?? '';
        $transactionData = $data['data'] ?? [];
        
        switch ($event) {
            case 'charge.success':
                return $this->handleSuccessfulCharge($transactionData);
                
            case 'transfer.success':
                return $this->handleTransferSuccess($transactionData);
                
            case 'refund.processed':
                return $this->handleRefundProcessed($transactionData);
                
            default:
                return [
                    'status' => 'ignored',
                    'message' => 'Event not handled: ' . $event
                ];
        }
    }
    
    public function isAvailable(): bool {
        return !empty($this->secretKey) && !empty($this->publicKey);
    }
    
    protected function formatAmount(float $amount): int {
        // Paystack expects amount in kobo (NGN * 100)
        return (int)($amount * 100);
    }
    
    private function getGatewayId(): int {
        $result = \Database::select(
            $this->db,
            'payment_gateways',
            'id',
            'provider = ? AND school_id ' . ($this->schoolId ? '= ?' : 'IS NULL'),
            $this->schoolId ? ['paystack', $this->schoolId] : ['paystack']
        );
        
        return $result[0]['id'] ?? 0;
    }
    
    private function updateTransactionByReference(string $reference, array $data): bool {
        return \Database::update(
            $this->db,
            'payment_transactions',
            $data,
            'transaction_reference = ?',
            [$reference]
        ) > 0;
    }
    
    private function handleSuccessfulCharge(array $data): array {
        $reference = $data['reference'] ?? '';
        
        if (empty($reference)) {
            return ['status' => 'error', 'message' => 'No reference in webhook data'];
        }
        
        // Verify the payment
        $verification = $this->verifyPayment($reference);
        
        if ($verification['status'] === 'success') {
            // Trigger payment success event
            $this->triggerPaymentSuccessEvent($data);
        }
        
        return $verification;
    }
    
    private function handleTransferSuccess(array $data): array {
        // Handle successful transfers (for settlements)
        return [
            'status' => 'success',
            'message' => 'Transfer processed successfully',
            'data' => $data
        ];
    }
    
    private function handleRefundProcessed(array $data): array {
        // Handle refund webhook
        return [
            'status' => 'success',
            'message' => 'Refund processed successfully',
            'data' => $data
        ];
    }
    
    private function triggerPaymentSuccessEvent(array $data): void {
        // This would trigger other parts of the system (email notifications, etc.)
        // For now, just log it
        $this->logger->logEvent('payment_success', $data);
    }
}