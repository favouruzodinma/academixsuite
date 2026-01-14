<?php
/**
 * Mailer Helper (Simplified version without external dependencies)
 * Handles email sending with template support using native PHP
 */
namespace AcademixSuite\Helpers;

class Mailer {
    
    private $config;
    private $templatesDir;
    private $logger;
    
    public function __construct() {
        $this->config = require __DIR__ . '/../../config/mail.php';
        $this->templatesDir = __DIR__ . '/../../templates/emails/';
        $this->logger = new PaymentLogger();
        
        // Ensure template directory exists
        if (!is_dir($this->templatesDir)) {
            mkdir($this->templatesDir, 0755, true);
        }
    }
    
    /**
     * Send email with template
     */
    public function sendTemplate(string $template, string $to, array $data = []): bool {
        try {
            // Load template
            $templateContent = $this->loadTemplate($template);
            
            if (!$templateContent) {
                throw new \Exception("Template '$template' not found");
            }
            
            // Replace variables
            $subject = $this->replaceVariables($templateContent['subject'], $data);
            $htmlBody = $this->replaceVariables($templateContent['html'], $data);
            $textBody = $this->replaceVariables($templateContent['text'], $data);
            
            // Send email
            return $this->send($to, $subject, $htmlBody, $textBody);
            
        } catch (\Exception $e) {
            $this->logger->logError('email_template_error', [
                'template' => $template,
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send email
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
        try {
            $config = $this->config;
            $driver = $config['driver'] ?? 'mail';
            
            switch ($driver) {
                case 'smtp':
                    return $this->sendWithSmtp($to, $subject, $htmlBody, $textBody);
                    
                case 'sendmail':
                    return $this->sendWithSendmail($to, $subject, $htmlBody, $textBody);
                    
                case 'mail':
                default:
                    return $this->sendWithMail($to, $subject, $htmlBody, $textBody);
            }
            
        } catch (\Exception $e) {
            $this->logger->logError('email_send_error', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Send using PHP mail() function
     */
    private function sendWithMail(string $to, string $subject, string $htmlBody, string $textBody): bool {
        $from = $this->config['from']['address'];
        $fromName = $this->config['from']['name'];
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: "' . $fromName . '" <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . phpversion()
        ];
        
        if (!empty($textBody)) {
            // Create multipart message
            $boundary = md5(time());
            
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'From: "' . $fromName . '" <' . $from . '>',
                'Reply-To: ' . $from,
                'X-Mailer: PHP/' . phpversion()
            ];
            
            $body = "--$boundary\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $textBody . "\r\n\r\n" .
                   "--$boundary\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $htmlBody . "\r\n\r\n" .
                   "--$boundary--";
        } else {
            $body = $htmlBody;
        }
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        // Log the email
        $this->logEmail($to, $subject, $result);
        
        return $result;
    }
    
    /**
     * Send using SMTP (PHP socket implementation)
     */
    private function sendWithSmtp(string $to, string $subject, string $htmlBody, string $textBody): bool {
        $config = $this->config['smtp'];
        
        $host = $config['host'];
        $port = $config['port'];
        $username = $config['username'];
        $password = $config['password'];
        $encryption = $config['encryption'] ?? 'tls';
        $from = $this->config['from']['address'];
        $fromName = $this->config['from']['name'];
        
        // Create socket
        $socket = fsockopen(
            ($encryption === 'ssl' ? 'ssl://' : '') . $host,
            $port,
            $errno,
            $errstr,
            30
        );
        
        if (!$socket) {
            throw new \Exception("SMTP Connection failed: $errstr ($errno)");
        }
        
        $response = fgets($socket, 515);
        
        // Send SMTP commands
        $this->smtpCommand($socket, "EHLO " . $host, 250);
        
        if ($encryption === 'tls') {
            $this->smtpCommand($socket, "STARTTLS", 220);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->smtpCommand($socket, "EHLO " . $host, 250);
        }
        
        // Auth if credentials provided
        if (!empty($username) && !empty($password)) {
            $this->smtpCommand($socket, "AUTH LOGIN", 334);
            $this->smtpCommand($socket, base64_encode($username), 334);
            $this->smtpCommand($socket, base64_encode($password), 235);
        }
        
        // Send email
        $this->smtpCommand($socket, "MAIL FROM: <$from>", 250);
        $this->smtpCommand($socket, "RCPT TO: <$to>", 250);
        $this->smtpCommand($socket, "DATA", 354);
        
        // Email headers and body
        $headers = [
            "From: \"$fromName\" <$from>",
            "To: $to",
            "Subject: $subject",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"_boundary_\"",
            "X-Mailer: AcademixSuite Mailer"
        ];
        
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        $message .= "--_boundary_\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $message .= $textBody ?: strip_tags($htmlBody) . "\r\n\r\n";
        $message .= "--_boundary_\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= "--_boundary_--\r\n";
        $message .= ".\r\n";
        
        fwrite($socket, $message);
        $response = fgets($socket, 515);
        
        if (substr($response, 0, 3) != '250') {
            fclose($socket);
            throw new \Exception("SMTP Error: $response");
        }
        
        $this->smtpCommand($socket, "QUIT", 221);
        fclose($socket);
        
        // Log the email
        $this->logEmail($to, $subject, true);
        
        return true;
    }
    
    /**
     * Send using sendmail
     */
    private function sendWithSendmail(string $to, string $subject, string $htmlBody, string $textBody): bool {
        $from = $this->config['from']['address'];
        $fromName = $this->config['from']['name'];
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: "' . $fromName . '" <' . $from . '>',
            'Reply-To: ' . $from
        ];
        
        if (!empty($textBody)) {
            $boundary = md5(time());
            
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'From: "' . $fromName . '" <' . $from . '>',
                'Reply-To: ' . $from
            ];
            
            $body = "--$boundary\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $textBody . "\r\n\r\n" .
                   "--$boundary\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                   $htmlBody . "\r\n\r\n" .
                   "--$boundary--";
        } else {
            $body = $htmlBody;
        }
        
        // Use sendmail command
        $sendmail = $this->config['sendmail'] ?? '/usr/sbin/sendmail -t';
        
        $mail = fopen("sendmail", "w");
        fwrite($mail, "To: $to\n");
        fwrite($mail, "Subject: $subject\n");
        fwrite($mail, implode("\n", $headers) . "\n\n");
        fwrite($mail, $body);
        fclose($mail);
        
        exec($sendmail . " < sendmail", $output, $returnCode);
        unlink("sendmail");
        
        $success = $returnCode === 0;
        $this->logEmail($to, $subject, $success);
        
        return $success;
    }
    
    /**
     * SMTP command helper
     */
    private function smtpCommand($socket, string $command, int $expectedCode): void {
        fwrite($socket, $command . "\r\n");
        $response = fgets($socket, 515);
        
        if (substr($response, 0, 3) != $expectedCode) {
            throw new \Exception("SMTP Command failed: $command - $response");
        }
    }
    
    /**
     * Load email template
     */
    private function loadTemplate(string $templateName): ?array {
        $templateFile = $this->templatesDir . $templateName . '.html';
        
        if (!file_exists($templateFile)) {
            // Try to create default template
            $this->createDefaultTemplate($templateName);
            
            if (!file_exists($templateFile)) {
                return null;
            }
        }
        
        $content = file_get_contents($templateFile);
        
        // Extract subject from HTML comment
        $subject = 'Notification';
        if (preg_match('/<!-- SUBJECT:\s*(.+?)\s*-->/', $content, $matches)) {
            $subject = trim($matches[1]);
            $content = preg_replace('/<!-- SUBJECT:\s*.+?\s*-->/', '', $content, 1);
        }
        
        // Create plain text version
        $textContent = $this->htmlToText($content);
        
        return [
            'subject' => $subject,
            'html' => $content,
            'text' => $textContent
        ];
    }
    
    /**
     * Create default template if missing
     */
    private function createDefaultTemplate(string $templateName): void {
        $templateFile = $this->templatesDir . $templateName . '.html';
        
        $defaultTemplates = [
            'invoice-issued' => '
<!-- SUBJECT: Invoice {{INVOICE_NUMBER}} Issued -->
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice Notification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1>Invoice {{INVOICE_NUMBER}} Issued</h1>
        <p>Dear {{PARENT_NAME}},</p>
        <p>An invoice has been issued for {{STUDENT_NAME}}.</p>
        
        <div style="background-color: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 20px 0;">
            <p><strong>Invoice Details:</strong></p>
            <p>Amount: {{AMOUNT}}</p>
            <p>Due Date: {{DUE_DATE}}</p>
            <p>Status: {{STATUS}}</p>
        </div>
        
        <p>To view and pay the invoice, please click the button below:</p>
        
        <a href="{{PAYMENT_URL}}" style="display: inline-block; padding: 10px 20px; background-color: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0;">
            Pay Invoice
        </a>
        
        <p>If you have any questions, please contact us.</p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 12px;">
            This is an automated message from {{SCHOOL_NAME}}.
        </p>
    </div>
</body>
</html>',
            
            'payment-receipt' => '
<!-- SUBJECT: Payment Receipt {{RECEIPT_NUMBER}} -->
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6;">
    <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
        <h1 style="color: #27ae60;">Payment Receipt</h1>
        <p>Dear {{PARENT_NAME}},</p>
        <p>Thank you for your payment. Here is your receipt:</p>
        
        <div style="background-color: #e8f6f3; padding: 15px; border-left: 4px solid #27ae60; margin: 20px 0;">
            <p><strong>Payment Details:</strong></p>
            <p>Receipt #: {{RECEIPT_NUMBER}}</p>
            <p>Amount: {{AMOUNT}}</p>
            <p>Date: {{PAYMENT_DATE}}</p>
            <p>Payment Method: {{PAYMENT_METHOD}}</p>
            <p>Transaction ID: {{TRANSACTION_ID}}</p>
        </div>
        
        <p>You can download your receipt by clicking the button below:</p>
        
        <a href="{{RECEIPT_URL}}" style="display: inline-block; padding: 10px 20px; background-color: #27ae60; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0;">
            Download Receipt
        </a>
        
        <p>If you have any questions about this payment, please contact us.</p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 12px;">
            This is an automated message from {{SCHOOL_NAME}}.
        </p>
    </div>
</body>
</html>'
        ];
        
        if (isset($defaultTemplates[$templateName])) {
            file_put_contents($templateFile, $defaultTemplates[$templateName]);
        }
    }
    
    /**
     * Replace variables in template
     */
    private function replaceVariables(string $content, array $data): string {
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            
            $placeholder = '{{' . strtoupper($key) . '}}';
            $content = str_replace($placeholder, htmlspecialchars($value), $content);
        }
        
        // Replace common variables
        $commonVars = [
            '{{APP_NAME}}' => defined('APP_NAME') ? APP_NAME : 'AcademixSuite',
            '{{APP_URL}}' => defined('APP_URL') ? APP_URL : 'https://academixsuite.com',
            '{{CURRENT_YEAR}}' => date('Y'),
            '{{CURRENT_DATE}}' => date('Y-m-d'),
            '{{SUPPORT_EMAIL}}' => defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : 'support@academixsuite.com'
        ];
        
        $content = str_replace(array_keys($commonVars), array_values($commonVars), $content);
        
        return $content;
    }
    
    /**
     * Convert HTML to plain text
     */
    private function htmlToText(string $html): string {
        $text = $html;
        
        // Remove style and script tags
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        
        // Replace HTML elements
        $replacements = [
            '/<br\s*\/?>/i' => "\n",
            '/<p\s*\/?>/i' => "\n\n",
            '/<div\s*\/?>/i' => "\n",
            '/<h[1-6]\s*\/?>/i' => "\n\n",
            '/<\/h[1-6]>/i' => "\n\n",
            '/<li>/i' => "• ",
            '/<\/li>/i' => "\n",
            '/<a\s+href="([^"]+)"[^>]*>(.*?)<\/a>/i' => '$2 ($1)',
            '/<[^>]+>/' => '',
            '/&nbsp;/' => ' ',
            '/&amp;/' => '&',
            '/&lt;/' => '<',
            '/&gt;/' => '>',
            '/&quot;/' => '"',
            '/&#039;/' => "'",
        ];
        
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }
        
        // Clean up
        $text = preg_replace('/\n\s+\n/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Log email sending
     */
    private function logEmail(string $to, string $subject, bool $success, string $message = ''): void {
        $logDir = defined('LOG_DIR') ? LOG_DIR : __DIR__ . '/../../logs/emails/';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logEntry = [
            'to' => $to,
            'subject' => $subject,
            'success' => $success,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        $logFile = $logDir . date('Y-m-d') . '.log';
        file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);
        
        // Also log to payment logger for important emails
        if (strpos($subject, 'Payment') !== false || strpos($subject, 'Invoice') !== false) {
            $this->logger->logEvent('email_sent', [
                'to' => $to,
                'subject' => $subject,
                'success' => $success
            ]);
        }
    }
    
    /**
     * Get email template list
     */
    public function getTemplates(): array {
        $templates = [];
        $files = glob($this->templatesDir . '*.html');
        
        foreach ($files as $file) {
            $templateName = basename($file, '.html');
            $template = $this->loadTemplate($templateName);
            
            if ($template) {
                $templates[$templateName] = [
                    'name' => $templateName,
                    'subject' => $template['subject'],
                    'variables' => $this->extractVariables($template['html'])
                ];
            }
        }
        
        return $templates;
    }
    
    /**
     * Extract variables from template
     */
    private function extractVariables(string $content): array {
        preg_match_all('/{{([A-Z_]+)}}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }
}