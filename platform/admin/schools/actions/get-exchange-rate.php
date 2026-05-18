<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

// Cache control
header('Content-Type: application/json');
header('Cache-Control: max-age=300'); // 5 minutes cache

function getExchangeRate() {
    $cacheFile = __DIR__ . '/../../../../cache/exchange_rate.json';
    $cacheTime = 3600; // 1 hour cache
    
    // Check cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if (isset($cache['rate']) && $cache['rate'] > 0) {
            return $cache['rate'];
        }
    }
    
    // Try multiple APIs with fallback
    $apis = [
        'https://api.exchangerate-api.com/v4/latest/USD',
        'https://api.frankfurter.app/latest?from=USD&to=NGN',
        'https://open.er-api.com/v6/latest/USD'
    ];
    
    foreach ($apis as $api) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                // Extract NGN rate from different API formats
                if (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                } elseif (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                } elseif (isset($data['rates']['NGN'])) {
                    $rate = $data['rates']['NGN'];
                }
                
                if (isset($rate) && $rate > 0) {
                    // Cache the rate
                    file_put_contents($cacheFile, json_encode([
                        'rate' => $rate,
                        'timestamp' => time(),
                        'source' => $api
                    ]));
                    return $rate;
                }
            }
        } catch (Exception $e) {
            error_log("Currency API error ($api): " . $e->getMessage());
            continue;
        }
    }
    
    // Fallback to cached or default rate
    if (file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        return $cache['rate'] ?? 1400;
    }
    
    return 1400; // Default fallback
}

try {
    $rate = getExchangeRate();
    echo json_encode([
        'success' => true,
        'rate' => $rate,
        'timestamp' => date('Y-m-d H:i:s'),
        'formatted' => '₦' . number_format($rate, 2)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'rate' => 1400,
        'message' => 'Using default rate: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>