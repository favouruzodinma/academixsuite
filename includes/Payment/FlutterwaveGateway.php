<?php
/**
 * Flutterwave Payment Gateway Implementation
 */
namespace AcademixSuite\Payment;

class FlutterwaveGateway extends BaseGateway {
    
    protected $baseUrl;
    protected $publicKey;
    protected $secretKey;
    protected $encryptionKey;
    
    public function __construct(array $config, int $schoolId = null, bool $testMode = false) {
        parent::__construct($config, $schoolId, $testMode);
        
        $modeConfig = $this->getConfig();
        $this->publicKey = $modeConfig['public_key'] ?? '';
        $this->secretKey = $modeConfig['secret_key'] ?? '';
        $this->encryptionKey = $modeConfig['encryption_key'] ?? '';
        $this->baseUrl = 'https://api.flutterwave.com/v3';
        
        if (empty($this->secretKey)) {
            throw new \Exception("Flutterwave secret key is not configured");
        }
    }
    
    public function initializePayment(array $data): array {
        $validatedData = $this->validatePaymentData($data);
        
        $amount = $this->formatAmount($validatedData['amount']);
        $reference = $validatedData['reference'] ?? $this->generateReference('FLW');
        
        $payload = [
            'tx_ref' => $reference,
            'amount' => $amount,
            'currency' => $validatedData['currency'] ?? 'NGN',
            'redirect_url' => $validatedData['callback_url'] ?? $this->getCallbackUrl($validatedData['type'] ?? 'general'),
            'customer' => [
                'email' => $validatedData['email'],
                'name' => $validatedData['name'] ?? '',
                'phone_number' => $validatedData['phone'] ?? ''
            ],
            'customizations' => [
                'title' => 'AcademixSuite Payment',
                'description' => $validatedData['description'] ?? 'Payment for services',
                'logo' => defined('APP_LOGO') ? APP_LOGO : ''
            ],
            'meta' => array_merge(
                $validatedData['metadata'] ?? [],
                [
                    'school_id' => $this->schoolId,
                    'payment_type' => $validatedData['type'] ?? 'general'
                ]
            )
        ];
        
        $response = $this->makeRequest(
            $this->baseUrl . '/payments',
            'POST',
            $payload,
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200 || $response['data']['status'] !== 'success') {
            throw new \Exception("Flutterwave initialization failed: " . 
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
            'metadata' => json_encode($payload['meta']),
            'gateway_response' => json_encode($response['data'])
        ]);
        
        return [
            'status' => 'success',
            'message' => 'Payment initialized successfully',
            'data' => [
                'payment_url' => $response['data']['data']['link'],
                'reference' => $reference,
                'transaction_id' => $transactionId
            ]
        ];
    }
    
    public function verifyPayment(string $reference): array {
        $response = $this->makeRequest(
            $this->baseUrl . '/transactions/verify_by_reference?tx_ref=' . urlencode($reference),
            'GET',
            [],
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200) {
            throw new \Exception("Flutterwave verification failed: " . 
                ($response['data']['message'] ?? 'HTTP ' . $response['status']));
        }
        
        $transactionData = $response['data']['data'] ?? [];
        
        // Update transaction in database
        $this->updateTransactionByReference($reference, [
            'gateway_transaction_id' => $transactionData['id'] ?? null,
            'payment_method' => $transactionData['payment_type'] ?? null,
            'card_last_four' => isset($transactionData['card']['last_4digits']) ? 
                $transactionData['card']['last_4digits'] : null,
            'bank_name' => $transactionData['bank_name'] ?? null,
            'status' => $transactionData['status'] === 'successful' ? 'success' : 'failed',
            'verified_at' => date('Y-m-d H:i:s'),
            'gateway_response' => json_encode($transactionData)
        ]);
        
        return [
            'status' => $transactionData['status'] === 'successful' ? 'success' : 'failed',
            'message' => $response['data']['message'] ?? '',
            'data' => $transactionData,
            'transaction' => [
                'amount' => $transactionData['amount'] ?? 0,
                'currency' => $transactionData['currency'] ?? 'NGN',
                'paid_at' => $transactionData['created_at'] ?? null,
                'reference' => $transactionData['tx_ref'] ?? $reference
            ]
        ];
    }
    
    public function refundPayment(string $transactionId, float $amount, string $reason = ''): array {
        $payload = [
            'amount' => $this->formatAmount($amount)
        ];
        
        if (!empty($reason)) {
            $payload['comment'] = $reason;
        }
        
        $response = $this->makeRequest(
            $this->baseUrl . '/transactions/' . $transactionId . '/refund',
            'POST',
            $payload,
            [
                'Authorization: Bearer ' . $this->secretKey
            ]
        );
        
        if ($response['status'] !== 200 || $response['data']['status'] !== 'success') {
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
        $url = $this->baseUrl . '/transactions' . ($query ? '?' . $query : '');
        
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
        return ['NGN', 'GHS', 'KES', 'USD', 'GBP', 'EUR'];
    }
    
    public function validateWebhook(string $payload, string $signature): bool {
        $computedSignature = hash_hmac('sha256', $payload, $this->secretKey);
        return hash_equals($computedSignature, $signature);
    }
    
    public function processWebhook(array $data): array {
        $event = $data['event'] ?? '';
        
        switch ($event) {
            case 'charge.completed':
                return $this->handleChargeCompleted($data['data']);
                
            case 'transfer.completed':
                return $this->handleTransferCompleted($data['data']);
                
            case 'refund.completed':
                return $this->handleRefundCompleted($data['data']);
                
            default:
                return [
                    'status' => 'ignored',
                    'message' => 'Event not handled: ' . $event
                ];
        }
    }
    
    public function isAvailable(): bool {
        return !empty($this->secretKey);
    }
    
    protected function formatAmount(float $amount): float {
        // Flutterwave expects amount as-is
        return $amount;
    }
    
    private function getGatewayId(): int {
        $result = \Database::select(
            $this->db,
            'payment_gateways',
            'id',
            'provider = ? AND school_id ' . ($this->schoolId ? '= ?' : 'IS NULL'),
            $this->schoolId ? ['flutterwave', $this->schoolId] : ['flutterwave']
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
    
    private function handleChargeCompleted(array $data): array {
        $reference = $data['tx_ref'] ?? '';
        
        if (empty($reference)) {
            return ['status' => 'error', 'message' => 'No reference in webhook data'];
        }
        
        return $this->verifyPayment($reference);
    }
    
    private function handleTransferCompleted(array $data): array {
        return [
            'status' => 'success',
            'message' => 'Transfer completed',
            'data' => $data
        ];
    }
    
    private function handleRefundCompleted(array $data): array {
        return [
            'status' => 'success',
            'message' => 'Refund completed',
            'data' => $data
        ];
    }
}