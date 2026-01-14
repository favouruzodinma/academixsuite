<?php
/**
 * SMS Service
 * Handles SMS sending with multiple provider support
 */
namespace AcademixSuite\Helpers;

class SmsService {
    
    private $config;
    private $provider;
    private $logger;
    
    const PROVIDER_TWILIO = 'twilio';
    const PROVIDER_TERMII = 'termii';
    const PROVIDER_AFRICAS_TALKING = 'africastalking';
    const PROVIDER_SMS_NG = 'smsng';
    
    public function __construct() {
        $this->config = require __DIR__ . '/../../config/sms.php';
        $this->provider = $this->config['default_provider'] ?? self::PROVIDER_TERMII;
        $this->logger = new PaymentLogger();
    }
    
    /**
     * Send SMS
     */
    public function send(string $to, string $message, array $options = []): bool {
        try {
            // Validate phone number
            if (!$this->validatePhoneNumber($to)) {
                throw new \Exception("Invalid phone number: $to");
            }
            
            // Format phone number
            $formattedNumber = $this->formatPhoneNumber($to);
            
            // Truncate message if too long
            $message = $this->truncateMessage($message);
            
            // Send via selected provider
            switch ($this->provider) {
                case self::PROVIDER_TWILIO:
                    $result = $this->sendWithTwilio($formattedNumber, $message, $options);
                    break;
                    
                case self::PROVIDER_TERMII:
                    $result = $this->sendWithTermii($formattedNumber, $message, $options);
                    break;
                    
                case self::PROVIDER_AFRICAS_TALKING:
                    $result = $this->sendWithAfricasTalking($formattedNumber, $message, $options);
                    break;
                    
                case self::PROVIDER_SMS_NG:
                    $result = $this->sendWithSmsNg($formattedNumber, $message, $options);
                    break;
                    
                default:
                    throw new \Exception("Unsupported SMS provider: " . $this->provider);
            }
            
            // Log the SMS
            $this->logSms($to, $message, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->logError('sms_send_failed', [
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Send bulk SMS
     */
    public function sendBulk(array $recipients, string $message, array $options = []): array {
        $results = [
            'success' => [],
            'failed' => []
        ];
        
        foreach ($recipients as $recipient) {
            $result = $this->send($recipient, $message, $options);
            
            if ($result) {
                $results['success'][] = $recipient;
            } else {
                $results['failed'][] = $recipient;
            }
        }
        
        return $results;
    }
    
    /**
     * Send with Twilio
     */
    private function sendWithTwilio(string $to, string $message, array $options): bool {
        $accountSid = $this->config['providers']['twilio']['account_sid'] ?? '';
        $authToken = $this->config['providers']['twilio']['auth_token'] ?? '';
        $fromNumber = $this->config['providers']['twilio']['from_number'] ?? '';
        
        if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
            throw new \Exception("Twilio configuration is incomplete");
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
        
        $postData = [
            'From' => $fromNumber,
            'To' => $to,
            'Body' => $message
        ];
        
        if (isset($options['media_url'])) {
            $postData['MediaUrl'] = $options['media_url'];
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_USERPWD => "{$accountSid}:{$authToken}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Twilio CURL error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        return $httpCode === 201 && isset($responseData['sid']);
    }
    
    /**
     * Send with Termii
     */
    private function sendWithTermii(string $to, string $message, array $options): bool {
        $apiKey = $this->config['providers']['termii']['api_key'] ?? '';
        $senderId = $this->config['providers']['termii']['sender_id'] ?? 'Academix';
        
        if (empty($apiKey)) {
            throw new \Exception("Termii API key is not configured");
        }
        
        $url = 'https://api.ng.termii.com/api/sms/send';
        
        $postData = [
            'to' => $to,
            'from' => $senderId,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic',
            'api_key' => $apiKey
        ];
        
        if (isset($options['channel'])) {
            $postData['channel'] = $options['channel'];
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Termii CURL error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        return $httpCode === 200 && isset($responseData['message_id']);
    }
    
    /**
     * Send with Africa's Talking
     */
    private function sendWithAfricasTalking(string $to, string $message, array $options): bool {
        $username = $this->config['providers']['africastalking']['username'] ?? '';
        $apiKey = $this->config['providers']['africastalking']['api_key'] ?? '';
        $senderId = $this->config['providers']['africastalking']['sender_id'] ?? 'Academix';
        
        if (empty($username) || empty($apiKey)) {
            throw new \Exception("Africa's Talking configuration is incomplete");
        }
        
        $url = 'https://api.africastalking.com/version1/messaging';
        
        $postData = [
            'username' => $username,
            'to' => $to,
            'message' => $message,
            'from' => $senderId
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'apiKey: ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("Africa's Talking CURL error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        return $httpCode === 201 && isset($responseData['SMSMessageData']['Recipients']);
    }
    
    /**
     * Send with SMS.NG
     */
    private function sendWithSmsNg(string $to, string $message, array $options): bool {
        $apiKey = $this->config['providers']['smsng']['api_key'] ?? '';
        $senderId = $this->config['providers']['smsng']['sender_id'] ?? 'Academix';
        
        if (empty($apiKey)) {
            throw new \Exception("SMS.NG API key is not configured");
        }
        
        $url = 'https://api.sms.ng/v1/sms/send';
        
        $postData = [
            'to' => $to,
            'sender' => $senderId,
            'message' => $message,
            'type' => 0 // 0 for plain text
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception("SMS.NG CURL error: " . $error);
        }
        
        $responseData = json_decode($response, true);
        
        return $httpCode === 200 && isset($responseData['status']) && $responseData['status'] === 'success';
    }
    
    /**
     * Validate phone number
     */
    private function validatePhoneNumber(string $phone): bool {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) < 10) {
            return false;
        }
        
        // Nigerian numbers
        if (strlen($phone) === 11) {
            $validPrefixes = ['080', '081', '070', '090', '091', '071'];
            $prefix = substr($phone, 0, 3);
            return in_array($prefix, $validPrefixes);
        }
        
        // International numbers (with country code)
        if (strlen($phone) >= 12) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Format phone number
     */
    private function formatPhoneNumber(string $phone): string {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // If number starts with 0, assume it's Nigerian and add +234
        if (strlen($phone) === 11 && $phone[0] === '0') {
            return '+234' . substr($phone, 1);
        }
        
        // If number doesn't start with +, add +
        if ($phone[0] !== '+') {
            return '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Truncate message if too long
     */
    private function truncateMessage(string $message): string {
        $maxLength = 160; // Standard SMS length
        
        if (strlen($message) <= $maxLength) {
            return $message;
        }
        
        // Truncate to nearest word boundary
        $truncated = substr($message, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');
        
        if ($lastSpace !== false) {
            $truncated = substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }
    
    /**
     * Log SMS sending
     */
    private function logSms(string $to, string $message, bool $success): void {
        $logDir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../../logs/';
        $smsLogDir = $logDir . 'sms/';
        
        if (!is_dir($smsLogDir)) {
            mkdir($smsLogDir, 0755, true);
        }
        
        $logEntry = [
            'to' => $to,
            'message' => substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''),
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s'),
            'provider' => $this->provider
        ];
        
        $logFile = $smsLogDir . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get SMS balance
     */
    public function getBalance(): ?float {
        try {
            switch ($this->provider) {
                case self::PROVIDER_TERMII:
                    return $this->getTermiiBalance();
                    
                case self::PROVIDER_AFRICAS_TALKING:
                    return $this->getAfricasTalkingBalance();
                    
                default:
                    return null;
            }
        } catch (\Exception $e) {
            $this->logger->logError('sms_balance_check_failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Get Termii balance
     */
    private function getTermiiBalance(): ?float {
        $apiKey = $this->config['providers']['termii']['api_key'] ?? '';
        
        if (empty($apiKey)) {
            return null;
        }
        
        $url = "https://api.ng.termii.com/api/get-balance?api_key={$apiKey}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $responseData = json_decode($response, true);
        
        return $responseData['balance'] ?? null;
    }
    
    /**
     * Get Africa's Talking balance
     */
    private function getAfricasTalkingBalance(): ?float {
        $username = $this->config['providers']['africastalking']['username'] ?? '';
        $apiKey = $this->config['providers']['africastalking']['api_key'] ?? '';
        
        if (empty($username) || empty($apiKey)) {
            return null;
        }
        
        $url = "https://api.africastalking.com/version1/user?username={$username}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        $responseData = json_decode($response, true);
        
        return $responseData['UserData']['balance'] ?? null;
    }
    
    /**
     * Get delivery status
     */
    public function getDeliveryStatus(string $messageId): ?array {
        try {
            switch ($this->provider) {
                case self::PROVIDER_TERMII:
                    return $this->getTermiiDeliveryStatus($messageId);
                    
                case self::PROVIDER_AFRICAS_TALKING:
                    return $this->getAfricasTalkingDeliveryStatus($messageId);
                    
                default:
                    return null;
            }
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get Termii delivery status
     */
    private function getTermiiDeliveryStatus(string $messageId): ?array {
        $apiKey = $this->config['providers']['termii']['api_key'] ?? '';
        
        if (empty($apiKey)) {
            return null;
        }
        
        $url = "https://api.ng.termii.com/api/sms/inbox?api_key={$apiKey}&message_id={$messageId}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Get Africa's Talking delivery status
     */
    private function getAfricasTalkingDeliveryStatus(string $messageId): ?array {
        $username = $this->config['providers']['africastalking']['username'] ?? '';
        $apiKey = $this->config['providers']['africastalking']['api_key'] ?? '';
        
        if (empty($username) || empty($apiKey)) {
            return null;
        }
        
        $url = "https://api.africastalking.com/version1/messaging?username={$username}&messageId={$messageId}";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . $apiKey,
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
}