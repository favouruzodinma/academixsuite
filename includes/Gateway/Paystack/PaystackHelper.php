<?php
/**
 * Paystack Helper Functions
 */
namespace AcademixSuite\Gateway\Paystack;

class PaystackHelper {
    
    /**
     * Generate Paystack reference
     */
    public static function generateReference(string $prefix = 'PSK'): string {
        return $prefix . '_' . time() . '_' . strtoupper(bin2hex(random_bytes(4)));
    }
    
    /**
     * Format amount for Paystack (NGN to kobo)
     */
    public static function formatAmount(float $amount, string $currency = 'NGN'): int {
        if ($currency === 'NGN') {
            return (int)($amount * 100); // Convert to kobo
        }
        
        // For other currencies, check if they need similar conversion
        $minorUnits = [
            'GHS' => 100, // Ghanaian cedi to pesewas
            'KES' => 100, // Kenyan shilling to cents
            'ZAR' => 100  // South African rand to cents
        ];
        
        if (isset($minorUnits[$currency])) {
            return (int)($amount * $minorUnits[$currency]);
        }
        
        // For currencies without minor units, use amount as-is
        return (int)$amount;
    }
    
    /**
     * Parse amount from Paystack response
     */
    public static function parseAmount(int $amount, string $currency = 'NGN'): float {
        if ($currency === 'NGN') {
            return $amount / 100; // Convert from kobo to Naira
        }
        
        $minorUnits = [
            'GHS' => 100,
            'KES' => 100,
            'ZAR' => 100
        ];
        
        if (isset($minorUnits[$currency])) {
            return $amount / $minorUnits[$currency];
        }
        
        return (float)$amount;
    }
    
    /**
     * Validate callback URL
     */
    public static function validateCallbackUrl(string $url): bool {
        if (empty($url)) {
            return false;
        }
        
        // Validate URL format
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Ensure it's HTTPS in production
        if (APP_ENV === 'production' && !str_starts_with($url, 'https://')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Extract metadata from Paystack response
     */
    public static function extractMetadata(array $response): array {
        $data = $response['data'] ?? [];
        
        $metadata = [
            'gateway' => 'paystack',
            'transaction_id' => $data['id'] ?? null,
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? null,
            'channel' => $data['channel'] ?? null,
            'status' => $data['status'] ?? null,
            'paid_at' => $data['paid_at'] ?? null,
            'created_at' => $data['created_at'] ?? null
        ];
        
        // Add authorization data if available
        if (isset($data['authorization'])) {
            $metadata['authorization'] = [
                'authorization_code' => $data['authorization']['authorization_code'] ?? null,
                'bin' => $data['authorization']['bin'] ?? null,
                'last4' => $data['authorization']['last4'] ?? null,
                'exp_month' => $data['authorization']['exp_month'] ?? null,
                'exp_year' => $data['authorization']['exp_year'] ?? null,
                'card_type' => $data['authorization']['card_type'] ?? null,
                'bank' => $data['authorization']['bank'] ?? null,
                'country_code' => $data['authorization']['country_code'] ?? null,
                'brand' => $data['authorization']['brand'] ?? null,
                'reusable' => $data['authorization']['reusable'] ?? false,
                'signature' => $data['authorization']['signature'] ?? null
            ];
        }
        
        // Add customer data if available
        if (isset($data['customer'])) {
            $metadata['customer'] = [
                'id' => $data['customer']['id'] ?? null,
                'email' => $data['customer']['email'] ?? null,
                'customer_code' => $data['customer']['customer_code'] ?? null,
                'first_name' => $data['customer']['first_name'] ?? null,
                'last_name' => $data['customer']['last_name'] ?? null,
                'phone' => $data['customer']['phone'] ?? null
            ];
        }
        
        return $metadata;
    }
    
    /**
     * Generate payment link for sharing
     */
    public static function generatePaymentLink(string $reference, float $amount, string $email, string $name = ''): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        
        $params = [
            'reference' => $reference,
            'amount' => $amount,
            'email' => urlencode($email)
        ];
        
        if (!empty($name)) {
            $params['name'] = urlencode($name);
        }
        
        return $baseUrl . '/pay?' . http_build_query($params);
    }
    
    /**
     * Get supported banks for transfers
     */
    public static function getSupportedBanks(): array {
        // Common Nigerian banks supported by Paystack
        return [
            '044' => 'Access Bank',
            '023' => 'Citibank',
            '063' => 'Diamond Bank',
            '050' => 'Ecobank',
            '070' => 'Fidelity Bank',
            '011' => 'First Bank',
            '214' => 'First City Monument Bank',
            '058' => 'Guaranty Trust Bank',
            '030' => 'Heritage Bank',
            '301' => 'Jaiz Bank',
            '082' => 'Keystone Bank',
            '076' => 'Polaris Bank',
            '221' => 'Stanbic IBTC Bank',
            '068' => 'Standard Chartered Bank',
            '232' => 'Sterling Bank',
            '100' => 'Suntrust Bank',
            '032' => 'Union Bank',
            '033' => 'United Bank for Africa',
            '215' => 'Unity Bank',
            '035' => 'Wema Bank',
            '057' => 'Zenith Bank'
        ];
    }
    
    /**
     * Validate bank account number
     */
    public static function validateBankAccount(string $accountNumber, string $bankCode): bool {
        if (empty($accountNumber) || empty($bankCode)) {
            return false;
        }
        
        // Basic validation - account number should be 10 digits for Nigerian banks
        if (!preg_match('/^\d{10}$/', $accountNumber)) {
            return false;
        }
        
        // Bank code should be in supported banks
        $supportedBanks = self::getSupportedBanks();
        return isset($supportedBanks[$bankCode]);
    }
    
    /**
     * Calculate settlement amount
     */
    public static function calculateSettlement(float $amount, array $fees): float {
        $transactionFee = ($amount * ($fees['transaction_fee_percentage'] ?? 0.015)) / 100;
        $fixedFee = $fees['transaction_fee_fixed'] ?? 0;
        $vat = ($transactionFee * ($fees['vat'] ?? 7.5)) / 100;
        
        return $amount - $transactionFee - $fixedFee - $vat;
    }
    
    /**
     * Get transaction status label
     */
    public static function getStatusLabel(string $status): string {
        $labels = [
            'success' => 'Successful',
            'failed' => 'Failed',
            'abandoned' => 'Abandoned',
            'pending' => 'Pending',
            'reversed' => 'Reversed'
        ];
        
        return $labels[$status] ?? ucfirst($status);
    }
    
    /**
     * Format date from Paystack
     */
    public static function formatDate(string $dateString): string {
        if (empty($dateString)) {
            return '';
        }
        
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $dateString;
        }
    }
}