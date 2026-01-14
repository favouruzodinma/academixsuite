<?php
/**
 * Payment Gateway Factory
 * Creates appropriate gateway instances based on provider
 */
namespace AcademixSuite\Payment;

class PaymentFactory {
    
    private static $config;
    private static $gatewayCache = [];
    
    /**
     * Initialize factory with configuration
     * @param array $config Payment configuration
     */
    public static function init(array $config) {
        self::$config = $config;
    }
    
    /**
     * Create payment gateway instance
     * @param string $provider Gateway provider (paystack, flutterwave, stripe)
     * @param int|null $schoolId School ID for school-specific gateway
     * @param bool $testMode Whether to use test mode
     * @return PaymentGatewayInterface Gateway instance
     * @throws \Exception If provider is not supported or configured
     */
    public static function create(string $provider, int $schoolId = null, bool $testMode = false): PaymentGatewayInterface {
        $cacheKey = $provider . '_' . $schoolId . '_' . ($testMode ? 'test' : 'live');
        
        if (isset(self::$gatewayCache[$cacheKey])) {
            return self::$gatewayCache[$cacheKey];
        }
        
        if (!isset(self::$config['gateways'][$provider])) {
            throw new \Exception("Payment gateway '$provider' is not configured");
        }
        
        $gatewayConfig = self::$config['gateways'][$provider];
        
        switch (strtolower($provider)) {
            case 'paystack':
                $gateway = new PaystackGateway($gatewayConfig, $schoolId, $testMode);
                break;
                
            case 'flutterwave':
                $gateway = new FlutterwaveGateway($gatewayConfig, $schoolId, $testMode);
                break;
                
            case 'stripe':
                $gateway = new StripeGateway($gatewayConfig, $schoolId, $testMode);
                break;
                
            default:
                throw new \Exception("Unsupported payment gateway: $provider");
        }
        
        self::$gatewayCache[$cacheKey] = $gateway;
        return $gateway;
    }
    
    /**
     * Get default payment gateway
     * @param int|null $schoolId School ID
     * @param bool $testMode Whether to use test mode
     * @return PaymentGatewayInterface Default gateway
     * @throws \Exception If no default gateway configured
     */
    public static function getDefault(int $schoolId = null, bool $testMode = false): PaymentGatewayInterface {
        $defaultProvider = self::$config['default_gateway'] ?? 'paystack';
        return self::create($defaultProvider, $schoolId, $testMode);
    }
    
    /**
     * Get all available gateways
     * @param int|null $schoolId School ID
     * @param bool $testMode Whether to use test mode
     * @return array List of available gateways
     */
    public static function getAvailableGateways(int $schoolId = null, bool $testMode = false): array {
        $available = [];
        
        foreach (array_keys(self::$config['gateways'] ?? []) as $provider) {
            try {
                $gateway = self::create($provider, $schoolId, $testMode);
                if ($gateway->isAvailable()) {
                    $available[] = $provider;
                }
            } catch (\Exception $e) {
                // Skip unavailable gateways
                continue;
            }
        }
        
        return $available;
    }
    
    /**
     * Get gateway configuration for a specific school
     * @param int $schoolId School ID
     * @param string $provider Gateway provider
     * @return array|null Gateway configuration or null if not found
     */
    public static function getSchoolGatewayConfig(int $schoolId, string $provider): ?array {
        $db = \Database::getPlatformConnection();
        
        $result = \Database::select(
            $db,
            'payment_gateways',
            '*',
            'school_id = ? AND provider = ? AND is_active = 1',
            [$schoolId, $provider]
        );
        
        if (empty($result)) {
            // Fallback to platform-wide gateway
            $result = \Database::select(
                $db,
                'payment_gateways',
                '*',
                'school_id IS NULL AND provider = ? AND is_active = 1',
                [$provider]
            );
        }
        
        return $result[0] ?? null;
    }
    
    /**
     * Clear gateway cache
     */
    public static function clearCache(): void {
        self::$gatewayCache = [];
    }
}