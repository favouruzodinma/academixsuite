<?php
// includes/Services/EmailService.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

class EmailService {
    private $fromEmail;
    private $fromName;
    private $replyTo;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpEncryption;
    
    public function __construct() {
        // Load configuration from environment variables if env() exists
        if (function_exists('env')) {
            $this->smtpHost = env('MAIL_HOST', 'smtp.gmail.com');
            $this->smtpPort = env('MAIL_PORT', 587);
            $this->smtpUser = env('MAIL_USERNAME');
            $this->smtpPass = env('MAIL_PASSWORD');
            $this->smtpEncryption = env('MAIL_ENCRYPTION', 'ssl');
            $this->fromEmail = env('MAIL_FROM_ADDRESS', 'noreply@academixsuite.com');
            $this->fromName = env('MAIL_FROM_NAME', 'AcademixSuite');
        } else {
            // Default fallbacks
            $this->smtpHost = defined('MAIL_HOST') ? MAIL_HOST : 'smtp.gmail.com';
            $this->smtpPort = defined('MAIL_PORT') ? MAIL_PORT : 587;
            $this->fromEmail = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@academixsuite.com';
            $this->fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'AcademixSuite';
        }
        
        $this->replyTo = 'support@academixsuite.com';
    }

    private function ensurePHPMailerLoaded() {
        if (class_exists(PHPMailer::class, false)) {
            return;
        }

        $requiredFiles = [
            __DIR__ . '/../../PHPMailer/src/Exception.php',
            __DIR__ . '/../../PHPMailer/src/PHPMailer.php',
            __DIR__ . '/../../PHPMailer/src/SMTP.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!is_readable($file)) {
                throw new \RuntimeException('PHPMailer dependency is missing: ' . basename($file));
            }
            require_once $file;
        }
    }
    
    /**
     * Send email using PHPMailer (SMTP)
     */
    public function sendEmail($to, $subject, $htmlContent, $textContent = null, array $options = []) {
        $mail = null;
        $htmlContent = $this->ensureAcademixLogo($htmlContent);
        
        try {
            $this->ensurePHPMailerLoaded();
            $mail = new PHPMailer(true);
            error_log("EmailService: Attempting to send email to: " . $to . " via PHPMailer");
            $fromEmail = $options['from_email'] ?? $this->fromEmail;
            $fromName = $options['from_name'] ?? $this->fromName;
            $replyTo = $options['reply_to'] ?? $this->replyTo;
            
            // Server settings - use sendmail if no SMTP credentials
            $transport = 'smtp';
            if (!empty($this->smtpUser)) {
                $mail->isSMTP();
                $mail->Host       = $this->smtpHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = $this->smtpUser;
                $mail->Password   = $this->smtpPass;
                $mail->SMTPSecure = strtolower((string)$this->smtpEncryption) === 'ssl'
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $this->smtpPort;
            } else {
                // DELIVERABILITY: with no SMTP credentials, we hand the mail
                // to local sendmail. Free providers (gmail, yahoo, outlook)
                // will silently drop these unless SPF/DKIM/DMARC are configured
                // for the sending domain. Log the transport explicitly so the
                // operator can tell which path was used.
                $transport = 'sendmail';
                $mail->isSendmail();
                error_log("EmailService: WARNING — no SMTP credentials set; "
                    . "falling back to local sendmail. Mail to gmail/yahoo/"
                    . "outlook is likely to be silently rejected unless SPF, "
                    . "DKIM, and DMARC are configured for " . $this->fromEmail);
            }

            // Force a clean envelope-sender; mismatched bounce paths trigger
            // DMARC failures on the receiving side.
            $mail->Sender = $this->fromEmail;
            
            // Recipients
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);
            if (!empty($replyTo)) {
                $mail->addReplyTo($replyTo, 'Support');
            }

            foreach (($options['headers'] ?? []) as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $mail->addCustomHeader($name, (string)$value);
                }
            }
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlContent;
            
            if ($textContent) {
                $mail->AltBody = $textContent;
            } else {
                $mail->AltBody = strip_tags($htmlContent);
            }
            
            $mail->send();

            error_log("EmailService: handed off to {$transport} for {$to} "
                . "(this means PHPMailer accepted the message — it does NOT "
                . "guarantee the receiving provider will deliver it).");
            return [
                'success' => true,
                'message_id' => $mail->getLastMessageID(),
                'method' => $transport,
            ];
            
        } catch (\Throwable $e) {
            $errorInfo = ($mail && !empty($mail->ErrorInfo)) ? $mail->ErrorInfo : $e->getMessage();
            error_log("EmailService: PHPMailer failed: " . $errorInfo);
            
            // Fallback to traditional mail() function if PHPMailer fails
            return $this->sendFallbackEmail($to, $subject, $htmlContent, $textContent, $options);
        }
    }
    
    /**
     * Fallback email sending method
     */
    private function sendFallbackEmail($to, $subject, $htmlContent, $textContent = null, array $options = []) {
        try {
            $htmlContent = $this->ensureAcademixLogo($htmlContent);

            // Create text version from HTML if not provided
            if (!$textContent) {
                $textContent = strip_tags($htmlContent);
            }
            
            $fromEmail = $options['from_email'] ?? $this->fromEmail;
            $replyTo = $options['reply_to'] ?? $this->replyTo;

            // Prepare headers
            $headers = [
                'From: ' . $fromEmail,
                'Reply-To: ' . $replyTo,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'X-Mailer: PHP/' . phpversion()
            ];
            
            // Send email
            if (mail($to, $subject, $htmlContent, implode("\r\n", $headers))) {
                error_log("EmailService: Fallback email sent successfully to: " . $to);
                return ['success' => true, 'method' => 'fallback'];
            } else {
                throw new Exception("Fallback email function failed");
            }
            
        } catch (Exception $e) {
            error_log("EmailService: Fallback email also failed: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function ensureAcademixLogo($htmlContent): string {
        $htmlContent = (string)$htmlContent;

        if (stripos($htmlContent, 'tenant/assets/images/logo.png') !== false) {
            return $htmlContent;
        }

        $logoUrl = $this->academixLogoUrl();
        $brand = defined('APP_NAME') ? APP_NAME : 'AcademixSuite';
        $header = '<div style="background:#0f172a;padding:18px 22px;margin:0 0 24px 0;text-align:left;">'
            . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') . '" style="height:36px;width:auto;display:block;">'
            . '</div>';

        if (preg_match('/<body\b[^>]*>/i', $htmlContent)) {
            return preg_replace('/<body\b[^>]*>/i', '$0' . $header, $htmlContent, 1);
        }

        return $header . $htmlContent;
    }

    private function academixLogoUrl(): string {
        if (function_exists('academix_logo_url')) {
            return academix_logo_url(true);
        }

        $baseUrl = defined('APP_URL') ? constant('APP_URL') : 'https://www.academixsuite.com';
        return rtrim((string)$baseUrl, '/') . '/tenant/assets/images/logo.png';
    }

    
    /**
     * Get all active schools with admin emails
     */
    public function getAllSchoolAdmins($status = 'active', $includeTrial = true) {
    try {
        error_log("EmailService: Getting school admins with status='$status', includeTrial=" . ($includeTrial ? 'true' : 'false'));
        
        // Check if Database class exists
        if (!class_exists('Database')) {
            error_log("EmailService: Database class not found");
            return [];
        }
        
        $db = Database::getPlatformConnection();
        
        // Start with diagnostic logging
        error_log("EmailService: Connected to database successfully");
        
        // First, let's see what's actually in the database
        $diagSql = "SELECT COUNT(*) as total_schools FROM schools";
        $diagStmt = $db->query($diagSql);
        $totalSchools = $diagStmt->fetch()['total_schools'] ?? 0;
        error_log("EmailService: Total schools in database: $totalSchools");
        
        if ($totalSchools === 0) {
            error_log("EmailService: WARNING: No schools found in database at all!");
            return [];
        }
        
        // Get status breakdown
        $statusSql = "SELECT status, COUNT(*) as count FROM schools GROUP BY status";
        $statusStmt = $db->query($statusSql);
        $statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("EmailService: School status breakdown: " . json_encode($statusCounts));
        
        // Build the WHERE clause based on the actual platform tables:
        // schools + school_admins. There is no platform-level users table.
        $whereConditions = [];
        $params = [];
        
        // Handle status parameter
        if ($status === 'all') {
            // Get ALL schools regardless of status
            $whereConditions[] = "1=1"; // Always true
        } elseif ($status === 'operational') {
            if ($includeTrial) {
                $whereConditions[] = "s.status IN ('active', 'trial')";
            } else {
                $whereConditions[] = "s.status = 'active'";
            }
        } else {
            // Specific status
            $whereConditions[] = "s.status = ?";
            $params[] = $status;
            
            // Include trial if requested and status is 'active'
            if ($includeTrial && $status === 'active') {
                $whereConditions[count($whereConditions)-1] = "s.status IN (?, ?)";
                $params = ['active', 'trial'];
            }
        }
        
        // Add condition to exclude trial if requested
        if (!$includeTrial && $status !== 'trial' && $status !== 'operational') {
            $whereConditions[] = "s.status != 'trial'";
        }
        
        // Build final WHERE clause
        $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
        
        // Get school admin emails where they exist and fall back per-school
        // to schools.email. Avoid joining a non-existent platform users table.
        $sql = "
            SELECT DISTINCT
                s.id as school_id,
                s.name as school_name,
                s.email as school_email,
                s.status as school_status,
                COALESCE(NULLIF(sa.email, ''), s.email) as admin_email,
                COALESCE(sa.role, 'school') as admin_role,
                CASE
                    WHEN s.principal_name IS NOT NULL AND s.principal_name != '' THEN s.principal_name
                    WHEN sa.role IS NOT NULL THEN CONCAT(UPPER(SUBSTRING(sa.role, 1, 1)), SUBSTRING(sa.role, 2), ' Administrator')
                    ELSE 'School Administrator'
                END as admin_name
            FROM schools s
            LEFT JOIN school_admins sa ON s.id = sa.school_id 
                AND sa.is_active = 1
                AND sa.role IN ('owner', 'admin', 'principal')
            $whereClause
            AND COALESCE(NULLIF(sa.email, ''), s.email) IS NOT NULL
            AND COALESCE(NULLIF(sa.email, ''), s.email) != ''
            ORDER BY s.id,
                CASE sa.role
                    WHEN 'owner' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'principal' THEN 3
                    ELSE 4
                END
        ";
        
        error_log("EmailService: SQL Query: " . str_replace(["\n", "  "], [" ", " "], $sql));
        error_log("EmailService: Query params: " . json_encode($params));
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("EmailService: Main query returned " . count($results) . " admin records");
        
        // If no records found, try a direct schools.email fallback for diagnostics.
        if (empty($results)) {
            error_log("EmailService: No admin records found in school_admins table, trying schools table fallback...");
            
            // Simple query to get schools directly
            $fallbackSql = "SELECT id, name, email, status FROM schools $whereClause ORDER BY id";
            
            error_log("EmailService: Fallback SQL: " . $fallbackSql);
            
            $fallbackStmt = $db->prepare($fallbackSql);
            $fallbackStmt->execute($params);
            $schools = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("EmailService: Fallback found " . count($schools) . " schools");
            
            foreach ($schools as $school) {
                if (!empty($school['email']) && filter_var($school['email'], FILTER_VALIDATE_EMAIL)) {
                    $results[] = [
                        'school_id' => $school['id'],
                        'school_name' => $school['name'],
                        'school_email' => $school['email'],
                        'school_status' => $school['status'],
                        'admin_email' => $school['email'],
                        'admin_role' => 'owner',
                        'admin_name' => 'School Administrator'
                    ];
                } else {
                    error_log("EmailService: School #{$school['id']} has invalid/empty email: " . ($school['email'] ?? 'NULL'));
                }
            }
            
            error_log("EmailService: After fallback, total valid records: " . count($results));
        }
        
        // Log detailed results for debugging
        if (count($results) > 0) {
            $sample = array_slice($results, 0, 3);
            error_log("EmailService: Sample results (first 3):");
            foreach ($sample as $index => $record) {
                error_log("  [$index] School: {$record['school_name']}, Email: {$record['admin_email']}, Status: {$record['school_status']}");
            }
        } else {
            error_log("EmailService: WARNING: No valid email records found after all attempts!");
            
            // More diagnostics - check what's actually queryable
            $testSql = "SELECT id, name, email, status FROM schools LIMIT 5";
            $testStmt = $db->query($testSql);
            $testSchools = $testStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("EmailService: Sample schools from direct query: " . json_encode($testSchools));
            
            $testAdminsSql = "SELECT school_id, email, role, is_active FROM school_admins LIMIT 5";
            $testAdminsStmt = $db->query($testAdminsSql);
            $testAdmins = $testAdminsStmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("EmailService: Sample school_admins: " . json_encode($testAdmins));
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("EmailService: ERROR in getAllSchoolAdmins: " . $e->getMessage());
        error_log("EmailService: Stack trace: " . $e->getTraceAsString());
        return [];
    }
}
    
    /**
     * Send announcement to all schools
     */
    public function sendAnnouncementToAllSchools($subject, $message, $templateName = 'announcement', $filters = []) {
        try {
            error_log("=== EMAILSERVICE: STARTING BULK EMAIL CAMPAIGN ===");
            error_log("EmailService: Subject: " . $subject);
            error_log("EmailService: Message length: " . strlen($message));
            error_log("EmailService: Template: " . $templateName);
            error_log("EmailService: Filters: " . json_encode($filters));
            
            // Get all active school admins based on filters
            $status = $filters['status'] ?? 'active';
            $includeTrial = $filters['include_trial'] ?? true;
            $sendToAdmins = $filters['send_to_admins'] ?? true;
            
            error_log("EmailService: Filters - Status: $status, Include Trial: " . ($includeTrial ? 'YES' : 'NO') . ", Send to Admins: " . ($sendToAdmins ? 'YES' : 'NO'));
            
            $schoolAdmins = $this->getAllSchoolAdmins($status, $includeTrial);
            
            error_log("EmailService: Found " . count($schoolAdmins) . " total school records");
            
            if (empty($schoolAdmins)) {
                $errorMsg = "No school admins found with status='$status', includeTrial=" . ($includeTrial ? 'true' : 'false');
                error_log("EmailService: " . $errorMsg);
                return [
                    'success' => 0, 
                    'failed' => 0, 
                    'total' => 0, 
                    'error' => $errorMsg,
                    'debug' => [
                        'status' => $status,
                        'include_trial' => $includeTrial,
                        'query_used' => 'getAllSchoolAdmins'
                    ]
                ];
            }
            
            // Prepare email data
            $emails = [];
            $seenEmails = [];
            foreach ($schoolAdmins as $school) {
                // Decide which email to use
                if (!$sendToAdmins && !empty($school['school_email'])) {
                    $email = $school['school_email'];
                    $source = 'school_email';
                } else {
                    $email = $school['admin_email'];
                    $source = 'admin_email';
                }
                
                // Validate email
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emailKey = strtolower($email);
                    if (isset($seenEmails[$emailKey])) {
                        error_log("EmailService: Duplicate recipient skipped: " . $email);
                        continue;
                    }
                    $seenEmails[$emailKey] = true;

                    $emails[] = [
                        'email' => $email,
                        'source' => $source,
                        'school_id' => $school['school_id'],
                        'school_name' => $school['school_name'],
                        'admin_name' => $school['admin_name'] ?? 'School Administrator'
                    ];
                } else {
                    error_log("EmailService: Invalid email skipped: " . $email . " (source: $source)");
                }
            }
            
            error_log("EmailService: Valid emails after filtering: " . count($emails));
            
            if (empty($emails)) {
                $errorMsg = "No valid email addresses found after validation";
                error_log("EmailService: " . $errorMsg);
                return [
                    'success' => 0, 
                    'failed' => 0, 
                    'total' => 0, 
                    'error' => $errorMsg,
                    'debug' => [
                        'total_records' => count($schoolAdmins),
                        'status' => $status,
                        'include_trial' => $includeTrial
                    ]
                ];
            }
            
            // Log first few emails for debugging
            $sampleEmails = array_slice($emails, 0, 3);
            error_log("EmailService: Sample emails to send: " . json_encode($sampleEmails));
            
            // Prepare template data callback
            $templateDataCallback = function($emailData) use ($message, $subject) {
                return [
                    'admin_name' => $emailData['admin_name'],
                    'school_name' => $emailData['school_name'],
                    'message' => trim(strip_tags($message)),
                    'message_html' => $message,
                    'subject' => $subject,
                    'date' => date('F j, Y'),
                    'year' => date('Y')
                ];
            };
            
            // Send bulk emails
            return $this->sendBulkEmails($emails, $subject, $templateName, $templateDataCallback);
            
        } catch (Exception $e) {
            error_log("EmailService: Error sending announcement: " . $e->getMessage());
            error_log("EmailService: Stack trace: " . $e->getTraceAsString());
            return [
                'success' => 0,
                'failed' => 0,
                'total' => 0,
                'error' => "System error: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send bulk emails with rate limiting and tracking
     */
    public function sendBulkEmails($emails, $subject, $templateName, $templateDataCallback, $batchSize = 5) {
        try {
            error_log("EmailService: Starting bulk email send to " . count($emails) . " recipients (batch size: $batchSize)");
            
            $results = [
                'total' => count($emails),
                'success' => 0,
                'failed' => 0,
                'details' => [],
                'summary' => [
                    'sent_to' => [],
                    'failed_on' => []
                ]
            ];
            
            // If no emails, return empty result
            if (empty($emails)) {
                error_log("EmailService: No emails to send");
                return $results;
            }
            
            // Initialize EmailTemplate
            $template = null;
            if (class_exists('EmailTemplate')) {
                $template = new EmailTemplate();
                error_log("EmailService: EmailTemplate class loaded successfully");
            } else {
                // Try to load EmailTemplate
                $templatePath = __DIR__ . '/EmailTemplate.php';
                if (file_exists($templatePath)) {
                    require_once $templatePath;
                    $template = new EmailTemplate();
                    error_log("EmailService: EmailTemplate loaded from file: " . $templatePath);
                } else {
                    throw new Exception("EmailTemplate class not found at $templatePath");
                }
            }
            
            // Check if template has required methods
            if (!method_exists($template, 'getTemplate') && !method_exists($template, 'getAnnouncementTemplate')) {
                throw new Exception("EmailTemplate missing required methods");
            }
            
            // Process emails in batches
            $totalBatches = ceil(count($emails) / $batchSize);
            $currentBatch = 1;
            
            foreach (array_chunk($emails, $batchSize) as $batch) {
                error_log("EmailService: Processing batch $currentBatch/$totalBatches (" . count($batch) . " emails)");
                
                foreach ($batch as $emailData) {
                    try {
                        $email = $emailData['email'];
                        $schoolName = $emailData['school_name'];
                        
                        error_log("EmailService: Preparing email for: $email (School: $schoolName)");
                        
                        // Get personalized data
                        $personalizedData = $templateDataCallback($emailData);
                        
                        // Get template content
                        if (method_exists($template, 'getTemplate')) {
                            $htmlContent = $template->getTemplate($templateName, $personalizedData);
                        } else {
                            $htmlContent = $template->getAnnouncementTemplate($personalizedData);
                        }
                        
                        if (empty($htmlContent)) {
                            throw new Exception("Template '$templateName' returned empty content");
                        }
                        
                        // Log first 100 chars of template for debugging
                        error_log("EmailService: Template preview: " . substr(strip_tags($htmlContent), 0, 100) . "...");
                        
                        // Send email
                        error_log("EmailService: Sending email to: $email");
                        $result = $this->sendEmail($email, $subject, $htmlContent);
                        
                        if ($result['success']) {
                            $results['success']++;
                            $results['details'][] = [
                                'email' => $email,
                                'school' => $schoolName,
                                'status' => 'sent',
                                'message_id' => $result['message_id'] ?? null,
                                'method' => $result['method'] ?? 'resend'
                            ];
                            $results['summary']['sent_to'][] = $email;
                            error_log("EmailService: ✓ Successfully sent to: $email");
                        } else {
                            $results['failed']++;
                            $results['details'][] = [
                                'email' => $email,
                                'school' => $schoolName,
                                'status' => 'failed',
                                'error' => $result['error'] ?? 'Unknown error',
                                'method' => $result['method'] ?? 'unknown'
                            ];
                            $results['summary']['failed_on'][] = $email;
                            error_log("EmailService: ✗ Failed to send to: $email - " . ($result['error'] ?? 'Unknown error'));
                        }
                        
                        // Small delay between emails (0.3 seconds)
                        usleep(300000);
                        
                    } catch (Exception $e) {
                        $results['failed']++;
                        $results['details'][] = [
                            'email' => $email ?? 'unknown',
                            'school' => $schoolName ?? 'unknown',
                            'status' => 'error',
                            'error' => $e->getMessage()
                        ];
                        $results['summary']['failed_on'][] = $email ?? 'unknown';
                        error_log("EmailService: ✗ Error sending to $email: " . $e->getMessage());
                    }
                }
                
                // Delay between batches
                if ($currentBatch < $totalBatches) {
                    error_log("EmailService: Batch $currentBatch complete, waiting 1 second before next batch...");
                    sleep(1);
                }
                
                $currentBatch++;
            }
            
            error_log("EmailService: Bulk email completed. Summary: " . 
                     $results['success'] . " successful, " . 
                     $results['failed'] . " failed out of " . 
                     $results['total'] . " total");
            
            // Add success percentage
            $results['success_rate'] = $results['total'] > 0 ? 
                round(($results['success'] / $results['total']) * 100, 1) : 0;
            
            return $results;
            
        } catch (Exception $e) {
            error_log("EmailService: Bulk email error: " . $e->getMessage());
            error_log("EmailService: Stack trace: " . $e->getTraceAsString());
            
            return [
                'success' => 0,
                'failed' => count($emails),
                'total' => count($emails),
                'error' => "Bulk email processing failed: " . $e->getMessage(),
                'details' => []
            ];
        }
    }
    
    /**
     * Test method to validate email sending
     */
    public function testEmailService($testEmail = null) {
        try {
            error_log("EmailService: Testing email service...");
            
            if (!$testEmail) {
                $testEmail = 'test@example.com';
            }
            
            $testTemplate = new EmailTemplate();
            $testData = [
                'admin_name' => 'Test Admin',
                'school_name' => 'Test School',
                'message' => 'This is a test message to verify the email service is working correctly.',
                'subject' => 'Test Email',
                'date' => date('F j, Y'),
                'year' => date('Y')
            ];
            
            $htmlContent = $testTemplate->getAnnouncementTemplate($testData);
            
            if (empty($htmlContent)) {
                return [
                    'success' => false,
                    'error' => 'Could not generate email template'
                ];
            }
            
            $result = $this->sendEmail($testEmail, 'Test Email from AcademixSuite', $htmlContent);
            
            return [
                'success' => $result['success'],
                'message' => $result['success'] ? 'Test email sent successfully' : 'Failed to send test email',
                'details' => $result
            ];
            
        } catch (Exception $e) {
            error_log("EmailService: Test error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get email statistics for dashboard
     */
    public function getEmailStats() {
        try {
            if (!class_exists('Database')) {
                return ['error' => 'Database class not available'];
            }
            
            $db = Database::getPlatformConnection();
            
            // Check if bulk_email_campaigns table exists
            $tableCheck = $db->query("SHOW TABLES LIKE 'bulk_email_campaigns'");
            if ($tableCheck->rowCount() === 0) {
                return [
                    'total_campaigns' => 0,
                    'total_emails' => 0,
                    'success_rate' => 0,
                    'recent_campaigns' => 0
                ];
            }
            
            // Get total campaigns
            $stmt = $db->query("SELECT COUNT(*) as total FROM bulk_email_campaigns");
            $totalCampaigns = $stmt->fetch()['total'] ?? 0;

            $columnStmt = $db->query("SHOW COLUMNS FROM bulk_email_campaigns");
            $columns = array_column($columnStmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $successColumn = in_array('successful_sends', $columns, true)
                ? 'successful_sends'
                : (in_array('sent_count', $columns, true) ? 'sent_count' : null);
            $failedColumn = in_array('failed_sends', $columns, true)
                ? 'failed_sends'
                : (in_array('failed_count', $columns, true) ? 'failed_count' : null);
            $dateColumn = in_array('sent_at', $columns, true)
                ? 'sent_at'
                : (in_array('completed_at', $columns, true) ? 'completed_at' : 'created_at');
            
            // Get total emails sent
            $totalSuccess = 0;
            $totalFailed = 0;
            if ($successColumn) {
                $stmt = $db->query("SELECT COALESCE(SUM(`{$successColumn}`), 0) as success FROM bulk_email_campaigns");
                $totalSuccess = (int)($stmt->fetch()['success'] ?? 0);
            }
            if ($failedColumn) {
                $stmt = $db->query("SELECT COALESCE(SUM(`{$failedColumn}`), 0) as failed FROM bulk_email_campaigns");
                $totalFailed = (int)($stmt->fetch()['failed'] ?? 0);
            }
            $totalEmails = $totalSuccess + $totalFailed;
            
            // Get recent campaigns (last 7 days)
            $stmt = $db->query("SELECT COUNT(*) as recent FROM bulk_email_campaigns WHERE `{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $recentCampaigns = $stmt->fetch()['recent'] ?? 0;
            
            // Calculate success rate
            $successRate = $totalEmails > 0 ? round(($totalSuccess / $totalEmails) * 100, 1) : 0;
            
            return [
                'total_campaigns' => (int)$totalCampaigns,
                'total_emails' => (int)$totalEmails,
                'total_success' => (int)$totalSuccess,
                'total_failed' => (int)$totalFailed,
                'success_rate' => $successRate,
                'recent_campaigns' => (int)$recentCampaigns
            ];
            
        } catch (Exception $e) {
            error_log("EmailService: Error getting stats: " . $e->getMessage());
            return [
                'total_campaigns' => 0,
                'total_emails' => 0,
                'success_rate' => 0,
                'recent_campaigns' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>
