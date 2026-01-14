<?php
/**
 * Payment Helper
 * Utility functions for payment processing
 */
namespace AcademixSuite\Helpers;

class PaymentHelper {
    
    /**
     * Format currency amount
     */
    public static function formatCurrency(float $amount, string $currency = 'NGN'): string {
        $symbols = [
            'NGN' => '₦',
            'USD' => '$',
            'GBP' => '£',
            'EUR' => '€'
        ];
        
        $symbol = $symbols[$currency] ?? $currency;
        
        return $symbol . number_format($amount, 2);
    }
    
    /**
     * Generate transaction reference
     */
    public static function generateReference(string $prefix = 'TX', string $suffix = ''): string {
        $timestamp = time();
        $random = strtoupper(bin2hex(random_bytes(4)));
        
        $reference = $prefix . '_' . $timestamp . '_' . $random;
        
        if (!empty($suffix)) {
            $reference .= '_' . $suffix;
        }
        
        return $reference;
    }
    
    /**
     * Mask credit card number
     */
    public static function maskCardNumber(string $cardNumber): string {
        if (strlen($cardNumber) < 4) {
            return $cardNumber;
        }
        
        $lastFour = substr($cardNumber, -4);
        return '**** **** **** ' . $lastFour;
    }
    
    /**
     * Validate Nigerian phone number
     */
    public static function validateNigerianPhone(string $phone): bool {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) !== 11) {
            return false;
        }
        
        // Nigerian phone numbers start with 080, 081, 070, 090, etc.
        $validPrefixes = ['080', '081', '070', '090', '091'];
        $prefix = substr($phone, 0, 3);
        
        return in_array($prefix, $validPrefixes);
    }
    
    /**
     * Calculate VAT
     */
    public static function calculateVAT(float $amount, float $vatRate = 7.5): array {
        $vat = ($amount * $vatRate) / 100;
        $total = $amount + $vat;
        
        return [
            'amount' => $amount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vat,
            'total' => $total
        ];
    }
    
    /**
     * Calculate payment fees
     */
    public static function calculateFees(float $amount, array $feeStructure): array {
        $percentageFee = ($amount * ($feeStructure['percentage'] ?? 0)) / 100;
        $fixedFee = $feeStructure['fixed'] ?? 0;
        $totalFee = $percentageFee + $fixedFee;
        $netAmount = $amount - $totalFee;
        
        return [
            'gross_amount' => $amount,
            'percentage_fee' => $percentageFee,
            'fixed_fee' => $fixedFee,
            'total_fee' => $totalFee,
            'net_amount' => $netAmount
        ];
    }
    
    /**
     * Generate payment link
     */
    public static function generatePaymentLink(array $data): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        
        $params = [
            'amount' => $data['amount'],
            'email' => urlencode($data['email']),
            'reference' => $data['reference'],
            'callback' => urlencode($data['callback_url'] ?? '')
        ];
        
        if (!empty($data['metadata'])) {
            $params['metadata'] = urlencode(json_encode($data['metadata']));
        }
        
        return $baseUrl . '/pay?' . http_build_query($params);
    }
    
    /**
     * Parse payment callback data
     */
    public static function parseCallbackData(array $data): array {
        $parsed = [
            'success' => $data['status'] === 'success',
            'reference' => $data['reference'] ?? null,
            'amount' => $data['amount'] ?? null,
            'currency' => $data['currency'] ?? 'NGN',
            'paid_at' => $data['paid_at'] ?? null,
            'gateway_response' => $data
        ];
        
        // Extract customer info
        if (isset($data['customer'])) {
            $parsed['customer'] = [
                'email' => $data['customer']['email'] ?? null,
                'name' => $data['customer']['name'] ?? null,
                'phone' => $data['customer']['phone'] ?? null
            ];
        }
        
        // Extract payment method
        if (isset($data['authorization'])) {
            $parsed['payment_method'] = [
                'type' => $data['authorization']['channel'] ?? 'card',
                'card_type' => $data['authorization']['card_type'] ?? null,
                'bank' => $data['authorization']['bank'] ?? null,
                'last4' => $data['authorization']['last4'] ?? null
            ];
        }
        
        return $parsed;
    }
    
    /**
     * Validate webhook signature
     */
    public static function validateWebhookSignature(string $payload, string $signature, string $secret): bool {
        if (empty($secret)) {
            throw new \Exception('Webhook secret is not configured');
        }
        
        $computedSignature = hash_hmac('sha512', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }
    
    /**
     * Generate receipt number
     */
    public static function generateReceiptNumber(int $schoolId): string {
        $prefix = 'RCP';
        $year = date('Y');
        $month = date('m');
        $sequence = self::getNextReceiptSequence($schoolId);
        
        return sprintf('%s-%s%s-%04d-%06d', $prefix, $year, $month, $schoolId, $sequence);
    }
    
    /**
     * Get next receipt sequence
     */
    private static function getNextReceiptSequence(int $schoolId): int {
        $db = \Database::getPlatformConnection();
        
        $year = date('Y');
        $month = date('m');
        
        $sql = "SELECT COUNT(*) as count FROM payments 
                WHERE school_id = ? AND YEAR(created_at) = ? AND MONTH(created_at) = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, $year, $month]);
        $result = $stmt->fetch();
        
        return (int) ($result['count'] ?? 0) + 1;
    }
    
    /**
     * Get payment status label
     */
    public static function getStatusLabel(string $status): string {
        $labels = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'success' => 'Successful',
            'failed' => 'Failed',
            'refunded' => 'Refunded',
            'cancelled' => 'Cancelled'
        ];
        
        return $labels[$status] ?? ucfirst($status);
    }
    
    /**
     * Get payment method label
     */
    public static function getMethodLabel(string $method): string {
        $labels = [
            'card' => 'Credit/Debit Card',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash',
            'cheque' => 'Cheque',
            'online' => 'Online Payment'
        ];
        
        return $labels[$method] ?? ucfirst($method);
    }
    
    /**
     * Format date for display
     */
    public static function formatDate(string $date, string $format = 'Y-m-d H:i:s'): string {
        try {
            $dateTime = new \DateTime($date);
            return $dateTime->format($format);
        } catch (\Exception $e) {
            return $date;
        }
    }
    
    /**
     * Calculate age in days
     */
    public static function calculateAgeInDays(string $date): int {
        try {
            $dateTime = new \DateTime($date);
            $now = new \DateTime();
            $interval = $dateTime->diff($now);
            return $interval->days;
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    /**
     * Generate QR code for payment
     */
    public static function generatePaymentQRCode(string $reference, float $amount, string $currency = 'NGN'): string {
        $data = [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'timestamp' => time()
        ];
        
        $encoded = base64_encode(json_encode($data));
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($encoded);
    }
    
    /**
     * Validate email
     */
    public static function validateEmail(string $email): bool {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Sanitize input
     */
    public static function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitizeInput'], $input);
        }
        
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}