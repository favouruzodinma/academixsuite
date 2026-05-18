<?php
// platform/admin/schools/send-email.php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/bulk_email_debug.log');

session_start();

// Check if autoload file exists
$autoloadPath = realpath(__DIR__ . '/../../../includes/autoload.php');

if (!$autoloadPath || !file_exists($autoloadPath)) {
    $alternativePaths = [
        __DIR__ . '/../../../includes/autoload.php',
        __DIR__ . '/../../includes/autoload.php',
        __DIR__ . '/../includes/autoload.php'
    ];
    
    foreach ($alternativePaths as $path) {
        if (file_exists($path)) {
            $autoloadPath = $path;
            error_log("Found autoload at: " . $path);
            break;
        }
    }
    
    if (!$autoloadPath || !file_exists($autoloadPath)) {
        error_log("FATAL ERROR: Autoload file not found at any location");
        die("System configuration error.");
    }
}

try {
    require_once $autoloadPath;
} catch (Exception $e) {
    error_log("ERROR loading autoload file: " . $e->getMessage());
    die("System configuration error.");
}

// Require super admin login
try {
    $auth = new Auth();
    
    // Check if user is logged in as super admin
    if (!isset($_SESSION['super_admin'])) {
        header('Location: /admin/login.php');
        exit;
    }
    
    $superAdmin = $_SESSION['super_admin'];
    
} catch (Exception $e) {
    error_log("Auth Error: " . $e->getMessage());
    die("Authentication error.");
}

// Initialize EmailService
try {
    if (!class_exists('EmailService')) {
        throw new Exception('EmailService class not found');
    }
    
    $emailService = new EmailService();
    
} catch (Exception $e) {
    error_log("EmailService Initialization Error: " . $e->getMessage());
    $emailService = null;
}

// Handle form submission
$success = $error = $campaignResult = null;

if (!empty($_SESSION['bulk_email_flash']) && is_array($_SESSION['bulk_email_flash'])) {
    if (($_SESSION['bulk_email_flash']['type'] ?? '') === 'success') {
        $success = $_SESSION['bulk_email_flash']['message'] ?? null;
    } else {
        $error = $_SESSION['bulk_email_flash']['message'] ?? null;
    }
    unset($_SESSION['bulk_email_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $csrfToken = $_POST['csrf_token'] ?? null;
    $sessionCsrf = $_SESSION['csrf_token'] ?? null;

    if (!isset($csrfToken) || $csrfToken !== $sessionCsrf) {
        $error = "Security validation failed. Please refresh the page and try again.";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $template = $_POST['template'] ?? 'announcement';
        $filters = [
            'status' => $_POST['status_filter'] ?? 'all',
            'include_trial' => isset($_POST['include_trial']),
            'send_to_admins' => isset($_POST['send_to_admins'])
        ];
        
        if (empty($subject) || empty($message)) {
            $error = "Subject and message are required.";
        } elseif (!$emailService) {
            $error = "Email service not available. Please check configuration.";
        } else {
            try {
                // Check if sendAnnouncementToAllSchools method exists
                if (!method_exists($emailService, 'sendAnnouncementToAllSchools')) {
                    throw new Exception("sendAnnouncementToAllSchools method not found in EmailService");
                }
                
                $campaignResult = $emailService->sendAnnouncementToAllSchools($subject, $message, $template, $filters);
                
                if (isset($campaignResult['error'])) {
                    $error = "Campaign Error: " . $campaignResult['error'];
                } else {
                    $success = "Campaign sent successfully! {$campaignResult['success']} emails delivered, {$campaignResult['failed']} failed.";
                    
                    // Try to log the campaign if database is available
                    try {
                        if (class_exists('Database')) {
                            $db = Database::getPlatformConnection();
                            if ($db) {
                                // Call log function if it exists
                                if (function_exists('logBulkEmailCampaign')) {
                                    logBulkEmailCampaign($superAdmin['id'] ?? 0, $subject, $campaignResult, $filters, $message, $template);
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Error logging campaign: " . $e->getMessage());
                        // Don't stop execution if logging fails
                    }
                }
            } catch (Exception $e) {
                $error = "Campaign Execution Error: " . $e->getMessage();
                error_log("Campaign Exception: " . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        error_log("New CSRF token generated");
    } catch (Exception $e) {
        error_log("Error generating CSRF token: " . $e->getMessage());
        $_SESSION['csrf_token'] = md5(uniqid(rand(), true));
    }
}

error_log("CSRF Token: " . substr($_SESSION['csrf_token'] ?? '', 0, 10) . '...');

// Get database connection
$db = null;
try {
    error_log("Getting database connection...");
    if (class_exists('Database')) {
        $db = Database::getPlatformConnection();
        error_log("Database connection established");
        
        // Test database connection
        $testQuery = $db->query("SELECT 1");
        if ($testQuery) {
            error_log("Database connection test passed");
        }
    } else {
        error_log("WARNING: Database class not found");
    }
} catch (Exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
    $db = null;
}

// Function definitions moved to top to ensure they're available
if (!function_exists('bulkEmailTableExists')) {
    function bulkEmailTableExists(PDO $db, string $table): bool {
        $stmt = $db->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('bulkEmailColumns')) {
    function bulkEmailColumns(PDO $db, string $table): array {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `{$table}`");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {
            error_log("Unable to inspect {$table} columns: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('bulkEmailPickColumn')) {
    function bulkEmailPickColumn(array $columns, array $candidates): ?string {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }
}

if (!function_exists('ensureBulkEmailCampaignTable')) {
    function ensureBulkEmailCampaignTable(PDO $db): void {
        if (bulkEmailTableExists($db, 'bulk_email_campaigns')) {
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS bulk_email_campaigns (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                subject VARCHAR(500) NOT NULL,
                html_content LONGTEXT NOT NULL,
                text_content TEXT DEFAULT NULL,
                from_email VARCHAR(255) DEFAULT NULL,
                from_name VARCHAR(255) DEFAULT NULL,
                reply_to VARCHAR(255) DEFAULT NULL,
                recipient_filter TEXT DEFAULT NULL,
                total_recipients INT UNSIGNED DEFAULT 0,
                sent_count INT UNSIGNED DEFAULT 0,
                failed_count INT UNSIGNED DEFAULT 0,
                status ENUM('draft', 'scheduled', 'processing', 'completed', 'cancelled') DEFAULT 'draft',
                scheduled_for DATETIME DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_scheduled (scheduled_for)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('normalizeBulkEmailCampaign')) {
    function normalizeBulkEmailCampaign(array $campaign): array {
        $success = (int)($campaign['successful_sends'] ?? $campaign['sent_count'] ?? $campaign['emails_sent'] ?? 0);
        $failed = (int)($campaign['failed_sends'] ?? $campaign['failed_count'] ?? 0);
        $total = (int)($campaign['total_recipients'] ?? ($success + $failed));
        $sentAt = $campaign['sent_at'] ?? $campaign['completed_at'] ?? $campaign['created_at'] ?? date('Y-m-d H:i:s');
        $status = $campaign['status'] ?? 'completed';

        if ($status === 'completed') {
            $status = $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'sent';
        }

        return array_merge($campaign, [
            'total_recipients' => $total,
            'successful_sends' => $success,
            'failed_sends' => $failed,
            'status' => $status,
            'sent_at' => $sentAt
        ]);
    }
}

if (!function_exists('getEmailStatistics')) {
    function getEmailStatistics($db) {
        if (!$db) {
            return [
                'total_campaigns' => 0,
                'total_success' => 0,
                'total_failed' => 0,
                'recent_campaigns' => 0,
                'delivery_rate' => 0
            ];
        }
        
        try {
            error_log("Executing getEmailStatistics query...");
            // First check if table exists
            if (!bulkEmailTableExists($db, 'bulk_email_campaigns')) {
                error_log("bulk_email_campaigns table doesn't exist");
                return [
                    'total_campaigns' => 0,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'recent_campaigns' => 0,
                    'delivery_rate' => 0
                ];
            }

            $columns = bulkEmailColumns($db, 'bulk_email_campaigns');
            $successColumn = bulkEmailPickColumn($columns, ['successful_sends', 'sent_count', 'emails_sent']);
            $failedColumn = bulkEmailPickColumn($columns, ['failed_sends', 'failed_count']);
            $dateColumn = bulkEmailPickColumn($columns, ['sent_at', 'completed_at', 'created_at']);
            
            // Total emails sent
            $stmt = $db->query("SELECT COUNT(*) as total FROM bulk_email_campaigns");
            $total = $stmt->fetch()['total'] ?? 0;
            
            // Successful emails
            $success = 0;
            if ($successColumn) {
                $stmt = $db->query("SELECT COALESCE(SUM(`{$successColumn}`), 0) as success FROM bulk_email_campaigns");
                $success = (int)($stmt->fetch()['success'] ?? 0);
            }
            
            // Failed emails
            $failed = 0;
            if ($failedColumn) {
                $stmt = $db->query("SELECT COALESCE(SUM(`{$failedColumn}`), 0) as failed FROM bulk_email_campaigns");
                $failed = (int)($stmt->fetch()['failed'] ?? 0);
            }
            
            // Recent activity
            $recent = 0;
            if ($dateColumn) {
                $stmt = $db->query("SELECT COUNT(*) as recent FROM bulk_email_campaigns WHERE `{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
                $recent = (int)($stmt->fetch()['recent'] ?? 0);
            }
            
            $result = [
                'total_campaigns' => $total,
                'total_success' => $success,
                'total_failed' => $failed,
                'recent_campaigns' => $recent,
                'delivery_rate' => (($success + $failed) > 0) ? round(($success / ($success + $failed)) * 100, 1) : 100
            ];
            
            error_log("Email statistics calculated: " . json_encode($result));
            return $result;
        } catch (Exception $e) {
            error_log("Error in getEmailStatistics: " . $e->getMessage());
            return [
                'total_campaigns' => 0,
                'total_success' => 0,
                'total_failed' => 0,
                'recent_campaigns' => 0,
                'delivery_rate' => 0
            ];
        }
    }
}

if (!function_exists('getSchoolStatistics')) {
    function getSchoolStatistics($db) {
        if (!$db) {
            return ['total' => 0, 'active' => 0, 'trial' => 0, 'suspended' => 0, 'cancelled' => 0];
        }
        
        try {
            error_log("Executing getSchoolStatistics query...");
            $stmt = $db->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'trial' THEN 1 ELSE 0 END) as trial,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM schools
            ");
            $result = $stmt->fetch();
            error_log("School statistics retrieved: " . json_encode($result));
            return $result ?: ['total' => 0, 'active' => 0, 'trial' => 0, 'suspended' => 0, 'cancelled' => 0];
        } catch (Exception $e) {
            error_log("Error in getSchoolStatistics: " . $e->getMessage());
            return ['total' => 0, 'active' => 0, 'trial' => 0, 'suspended' => 0, 'cancelled' => 0];
        }
    }
}

if (!function_exists('getRecentCampaigns')) {
    function getRecentCampaigns($db, $limit = 5) {
        if (!$db) {
            return [];
        }
        
        try {
            error_log("Executing getRecentCampaigns query with limit: " . $limit);
            
            // Check if table exists first
            if (!bulkEmailTableExists($db, 'bulk_email_campaigns')) {
                return [];
            }

            $columns = bulkEmailColumns($db, 'bulk_email_campaigns');
            $dateColumn = bulkEmailPickColumn($columns, ['sent_at', 'completed_at', 'created_at']) ?? 'id';
            
            $stmt = $db->prepare("
                SELECT * FROM bulk_email_campaigns 
                ORDER BY `{$dateColumn}` DESC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            $result = array_map('normalizeBulkEmailCampaign', $stmt->fetchAll(PDO::FETCH_ASSOC));
            error_log("Recent campaigns retrieved: " . count($result) . " records");
            return $result;
        } catch (Exception $e) {
            error_log("Error in getRecentCampaigns: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('logBulkEmailCampaign')) {
    function logBulkEmailCampaign($adminId, $subject, $result, $filters, $message = '', $template = 'announcement') {
        global $db;
        
        if (!$db) {
            error_log("No database connection for logging campaign");
            return false;
        }
        
        try {
            error_log("Logging bulk email campaign...");
            
            ensureBulkEmailCampaignTable($db);
            $columns = bulkEmailColumns($db, 'bulk_email_campaigns');
            
            $total = $result['total'] ?? 0;
            $success = $result['success'] ?? 0;
            $failed = $result['failed'] ?? 0;
            
            $displayStatus = $failed > 0 ? ($success > 0 ? 'partial' : 'failed') : 'sent';
            $schemaStatus = in_array('completed_at', $columns, true) ? 'completed' : $displayStatus;
            $details = json_encode($result['details'] ?? []);
            $filterJson = json_encode($filters);

            $valuesByColumn = [
                'admin_id' => $adminId,
                'created_by' => $adminId,
                'name' => 'Bulk Email - ' . substr($subject, 0, 220),
                'subject' => $subject,
                'html_content' => $message,
                'text_content' => trim(strip_tags($message)),
                'recipient_filter' => $filterJson,
                'filters' => $filterJson,
                'details' => $details,
                'total_recipients' => $total,
                'successful_sends' => $success,
                'sent_count' => $success,
                'failed_sends' => $failed,
                'failed_count' => $failed,
                'status' => $schemaStatus,
                'template' => $template,
                'started_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s'),
                'sent_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $insertColumns = [];
            $placeholders = [];
            $params = [];

            foreach ($valuesByColumn as $column => $value) {
                if (in_array($column, $columns, true)) {
                    $insertColumns[] = "`{$column}`";
                    $placeholders[] = '?';
                    $params[] = $value;
                }
            }

            if (empty($insertColumns)) {
                throw new Exception('bulk_email_campaigns table has no supported columns');
            }

            $stmt = $db->prepare("
                INSERT INTO bulk_email_campaigns (" . implode(', ', $insertColumns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ");
            $stmt->execute($params);
            
            $insertId = $db->lastInsertId();
            error_log("Campaign logged successfully with ID: " . $insertId);
            return $insertId;
        } catch (Exception $e) {
            error_log("Error in logBulkEmailCampaign: " . $e->getMessage());
            return false;
        }
    }
}

// Function to get status badge
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $badges = [
            'sent' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'failed' => 'bg-red-50 text-red-600 border-red-100',
            'partial' => 'bg-amber-50 text-amber-600 border-amber-100',
            'scheduled' => 'bg-blue-50 text-blue-600 border-blue-100'
        ];
        return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
    }
}

// Get email statistics
$stats = getEmailStatistics($db);
error_log("Email Statistics: " . json_encode($stats));

// Get recent campaigns
$recentCampaigns = getRecentCampaigns($db);
error_log("Recent Campaigns Count: " . count($recentCampaigns));

// Get school counts by status
$schoolStats = getSchoolStatistics($db);
error_log("School Statistics: " . json_encode($schoolStats));

// Get current exchange rate (for consistency with schools page)
$exchangeRate = 1500;

error_log("========== BULK EMAIL SENDER RENDER START ==========");
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Bulk Email Sender | <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'pulse-glow': 'pulse-glow 2s infinite',
                        'float': 'float 3s ease-in-out infinite',
                    },
                    keyframes: {
                        'pulse-glow': {
                            '0%, 100%': { opacity: 1 },
                            '50%': { opacity: 0.5, boxShadow: '0 0 20px rgba(139, 92, 246, 0.5)' }
                        },
                        'float': {
                            '0%, 100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' }
                        }
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #8b5cf6;
            --brand-secondary: #3b82f6;
            --brand-success: #10b981;
            --brand-warning: #f59e0b;
            --brand-danger: #ef4444;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
        }

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
        }
        
        .card { 
            background: var(--brand-surface);
            border: 1px solid #e2e8f0; 
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); 
        }

        .gradient-bg {
            background: linear-gradient(135deg, #8b5cf6 0%, #3b82f6 100%);
        }

        .gradient-bg-secondary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .status-pulse {
            height: 8px; 
            width: 8px; 
            border-radius: 50%;
            display: inline-block; 
            position: relative;
        }
        
        .status-pulse.online { 
            background: #22c55e; 
        }
        
        .status-pulse.online::after {
            content: ''; 
            position: absolute; 
            width: 100%; 
            height: 100%;
            background: #22c55e; 
            border-radius: 50%; 
            animation: pulse-green 2s infinite;
        }
        
        @keyframes pulse-green { 
            0% { transform: scale(1); opacity: 0.8; } 
            100% { transform: scale(3); opacity: 0; } 
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Text editor styles */
        .text-editor {
            min-height: 200px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            outline: none;
            background: white;
        }
        
        .text-editor:focus {
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .text-editor p {
            margin-bottom: 1em;
        }

        /* Tag styles */
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            background: #f1f5f9;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        
        .tag-purple {
            background: #f3e8ff;
            color: #7c3aed;
        }
        
        .tag-blue {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .tag-emerald {
            background: #d1fae5;
            color: #065f46;
        }

        /* Progress indicator */
        .progress-indicator {
            height: 6px;
            border-radius: 3px;
            background: #e2e8f0;
            overflow: hidden;
            position: relative;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            background: linear-gradient(90deg, #8b5cf6, #3b82f6);
            transition: width 0.3s ease;
        }
        
        .progress-fill.success {
            background: linear-gradient(90deg, #10b981, #059669);
        }
        
        .progress-fill.warning {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }
        
        .progress-fill.danger {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        /* Card hover effects */
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        }

        /* Touch target sizes */
        .touch-target {
            min-height: 44px;
            min-width: 44px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .mobile-stack {
                flex-direction: column;
            }
            
            .mobile-full {
                width: 100%;
            }
        }
        
        /* Debug indicator for developers */
        .debug-indicator {
            position: fixed;
            bottom: 10px;
            right: 10px;
            background: #ef4444;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            z-index: 9999;
            opacity: 0.7;
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-purple-100">

    <!-- Debug indicator (only shows in development) -->
    <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
    <div class="debug-indicator">
        Debug Mode | Errors: <?php echo error_reporting(); ?>
    </div>
    <?php endif; ?>

    <div class="flex h-screen overflow-hidden">
        
        <?php 
        error_log("Including sidebar...");
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include_once($sidebarPath);
            error_log("Sidebar included successfully");
        } else {
            error_log("ERROR: Sidebar not found at: " . $sidebarPath);
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <!-- Header -->
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Bulk Email Campaigns</h1>
                        <span class="px-2 py-0.5 bg-purple-600 text-[10px] text-white font-black rounded-full uppercase animate-pulse-glow">Live</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="../schools" class="hidden sm:flex items-center gap-2 text-slate-600 hover:text-slate-900 text-sm font-medium px-4 py-2 rounded-lg hover:bg-slate-100 transition touch-target">
                        <i class="fas fa-arrow-left"></i>
                        <span>Back to Schools</span>
                    </a>
                    <div class="flex items-center gap-2 text-sm text-slate-500">
                        <span class="status-pulse online"></span>
                        <span class="font-medium">Resend API <?php echo $emailService ? 'Connected' : 'Not Available'; ?></span>
                    </div>
                </div>
            </header>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-4 lg:p-8 space-y-6">
                
                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                <div class="card p-4 border-emerald-200 bg-emerald-50 animate-float">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
                            <i class="fas fa-check text-emerald-600 text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-emerald-800">Campaign Launched Successfully!</h3>
                            <p class="text-emerald-600 text-sm"><?php echo htmlspecialchars($success); ?></p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-emerald-400 hover:text-emerald-600 touch-target">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="card p-4 border-red-200 bg-red-50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-red-800">Campaign Error</h3>
                            <p class="text-red-600 text-sm"><?php echo htmlspecialchars($error); ?></p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="text-red-400 hover:text-red-600 touch-target">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Database Connection Warning -->
                <?php if (!$db): ?>
                <div class="card p-4 border-amber-200 bg-amber-50">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-amber-100 rounded-xl flex items-center justify-center">
                            <i class="fas fa-database text-amber-600 text-lg"></i>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-bold text-amber-800">Database Connection Issue</h3>
                            <p class="text-amber-600 text-sm">Statistics and campaign logging may not be available. Please check your database configuration.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Campaign Statistics Dashboard -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Total Recipients Card -->
                    <div class="card p-5 hover-lift">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <p class="text-xs font-black text-slate-400 uppercase tracking-wider">Total Recipients</p>
                                <p class="text-2xl font-black text-slate-900 mt-1"><?php echo $stats['total_success'] + $stats['total_failed']; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl gradient-bg flex items-center justify-center">
                                <i class="fas fa-users text-white text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-emerald-600 font-bold"><?php echo $stats['total_success']; ?> delivered</span>
                            <span class="text-slate-400"><?php echo $stats['total_failed']; ?> failed</span>
                        </div>
                    </div>

                    <!-- Delivery Rate Card -->
                    <div class="card p-5 hover-lift">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <p class="text-xs font-black text-slate-400 uppercase tracking-wider">Delivery Rate</p>
                                <p class="text-2xl font-black text-slate-900 mt-1"><?php echo $stats['delivery_rate']; ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center">
                                <i class="fas fa-chart-line text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-indicator mt-3">
                            <div class="progress-fill <?php echo $stats['delivery_rate'] >= 95 ? 'success' : ($stats['delivery_rate'] >= 80 ? 'warning' : 'danger'); ?>" 
                                 style="width: <?php echo $stats['delivery_rate']; ?>%"></div>
                        </div>
                    </div>

                    <!-- Active Campaigns Card -->
                    <div class="card p-5 hover-lift">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <p class="text-xs font-black text-slate-400 uppercase tracking-wider">Campaigns</p>
                                <p class="text-2xl font-black text-slate-900 mt-1"><?php echo $stats['total_campaigns']; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-purple-50 flex items-center justify-center">
                                <i class="fas fa-bullhorn text-purple-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="text-sm text-slate-600">
                            <span class="font-bold"><?php echo $stats['recent_campaigns']; ?></span> in last 7 days
                        </div>
                    </div>

                    <!-- School Coverage Card -->
                    <div class="card p-5 hover-lift">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <p class="text-xs font-black text-slate-400 uppercase tracking-wider">School Coverage</p>
                                <p class="text-2xl font-black text-slate-900 mt-1"><?php echo $schoolStats['total']; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-emerald-50 flex items-center justify-center">
                                <i class="fas fa-school text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <span class="tag tag-emerald"><?php echo $schoolStats['active']; ?> active</span>
                            <span class="tag tag-blue"><?php echo $schoolStats['trial']; ?> trial</span>
                        </div>
                    </div>
                </div>

                <!-- Main Campaign Creator -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Campaign Form -->
                    <div class="lg:col-span-2">
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <h2 class="text-lg font-black text-slate-900">Create New Campaign</h2>
                                    <p class="text-sm text-slate-500 mt-1">Send announcements to all school administrators</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="tag tag-purple">
                                        <i class="fas fa-bolt"></i>
                                        Powered by Resend
                                    </span>
                                </div>
                            </div>

                            <form method="POST" action="" id="campaignForm">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                
                                <!-- Subject Line -->
                                <div class="mb-6">
                                    <label class="block text-sm font-bold text-slate-700 mb-2" for="subject">
                                        Subject Line
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <input type="text" 
                                               id="subject" 
                                               name="subject" 
                                               required
                                               value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>"
                                               placeholder="e.g., Important Platform Update - New Features Available"
                                               class="w-full px-4 py-3.5 bg-white border border-slate-200 rounded-xl text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition touch-target">
                                        <div class="absolute right-3 top-3.5 text-xs text-slate-400">
                                            <span id="subjectCounter">0</span>/100
                                        </div>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-2">
                                        <i class="fas fa-lightbulb mr-1"></i>
                                        Keep it clear and compelling for better open rates
                                    </p>
                                </div>

                                <!-- Message Editor -->
                                <div class="mb-6">
                                    <div class="flex items-center justify-between mb-2">
                                        <label class="block text-sm font-bold text-slate-700" for="message">
                                            Message Content
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <div class="flex gap-2">
                                            <button type="button" onclick="insertTemplate()" class="text-xs text-purple-600 hover:text-purple-700 font-medium px-3 py-1.5 bg-purple-50 rounded-lg transition touch-target">
                                                <i class="fas fa-magic mr-1"></i>Insert Template
                                            </button>
                                            <button type="button" onclick="previewEmail()" class="text-xs text-blue-600 hover:text-blue-700 font-medium px-3 py-1.5 bg-blue-50 rounded-lg transition touch-target">
                                                <i class="fas fa-eye mr-1"></i>Preview
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="text-editor" 
                                         id="messageEditor" 
                                         contenteditable="true"
                                         placeholder="Write your announcement here... You can use basic HTML tags like &lt;strong&gt;, &lt;em&gt;, &lt;ul&gt;, &lt;li&gt;, etc."
                                         oninput="updateMessageInput()">
                                        <?php echo htmlspecialchars($_POST['message'] ?? ''); ?>
                                    </div>
                                    <textarea name="message" id="messageInput" required class="hidden"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                                    
                                    <div class="flex items-center justify-between mt-2">
                                        <div class="flex gap-3">
                                            <button type="button" onclick="formatText('bold')" class="text-slate-500 hover:text-slate-700 touch-target p-2">
                                                <i class="fas fa-bold"></i>
                                            </button>
                                            <button type="button" onclick="formatText('italic')" class="text-slate-500 hover:text-slate-700 touch-target p-2">
                                                <i class="fas fa-italic"></i>
                                            </button>
                                            <button type="button" onclick="formatText('underline')" class="text-slate-500 hover:text-slate-700 touch-target p-2">
                                                <i class="fas fa-underline"></i>
                                            </button>
                                            <div class="w-px h-6 bg-slate-200"></div>
                                            <button type="button" onclick="insertList('ul')" class="text-slate-500 hover:text-slate-700 touch-target p-2">
                                                <i class="fas fa-list-ul"></i>
                                            </button>
                                            <button type="button" onclick="insertList('ol')" class="text-slate-500 hover:text-slate-700 touch-target p-2">
                                                <i class="fas fa-list-ol"></i>
                                            </button>
                                        </div>
                                        <p class="text-xs text-slate-500">
                                            <span id="messageCounter">0</span> characters
                                        </p>
                                    </div>
                                </div>

                                <!-- Campaign Settings -->
                                <div class="mb-8">
                                    <h3 class="text-sm font-bold text-slate-700 mb-4">Campaign Settings</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Template Selection -->
                                        <div>
                                            <label class="block text-sm font-medium text-slate-600 mb-2" for="template">
                                                <i class="fas fa-palette mr-1"></i>
                                                Email Template
                                            </label>
                                            <select id="template" name="template" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition touch-target">
                                                <option value="announcement" selected>📢 Announcement Template</option>
                                                <option value="update">🔄 Platform Update</option>
                                                <option value="maintenance">🔧 Maintenance Notice</option>
                                                <option value="promotion">🎉 Special Promotion</option>
                                            </select>
                                        </div>

                                        <!-- Status Filter -->
                                        <div>
                                            <label class="block text-sm font-medium text-slate-600 mb-2" for="status_filter">
                                                <i class="fas fa-filter mr-1"></i>
                                                Filter Schools
                                            </label>
                                            <select id="status_filter" name="status_filter" class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition touch-target">
                                                <option value="all" selected>All Schools (<?php echo $schoolStats['total']; ?>)</option>
                                                <option value="active">Active Schools Only (<?php echo $schoolStats['active']; ?>)</option>
                                                <option value="trial">Trial Schools Only (<?php echo $schoolStats['trial']; ?>)</option>
                                                <option value="operational">Active + Trial (<?php echo $schoolStats['active'] + $schoolStats['trial']; ?>)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Checkbox Options -->
                                    <div class="mt-6 space-y-4">
                                        <div class="flex items-center gap-3">
                                            <div class="relative">
                                                <input type="checkbox" 
                                                       id="include_trial" 
                                                       name="include_trial" 
                                                       value="1" 
                                                       checked
                                                       class="sr-only peer">
                                                <div class="w-6 h-6 bg-white border-2 border-slate-300 rounded-md peer-checked:bg-purple-600 peer-checked:border-purple-600 transition-all flex items-center justify-center">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100 transition"></i>
                                                </div>
                                            </div>
                                            <label for="include_trial" class="text-sm text-slate-700 font-medium cursor-pointer">
                                                Include trial schools in campaign
                                            </label>
                                        </div>

                                        <div class="flex items-center gap-3">
                                            <div class="relative">
                                                <input type="checkbox" 
                                                       id="send_to_admins" 
                                                       name="send_to_admins" 
                                                       value="1" 
                                                       checked
                                                       class="sr-only peer">
                                                <div class="w-6 h-6 bg-white border-2 border-slate-300 rounded-md peer-checked:bg-purple-600 peer-checked:border-purple-600 transition-all flex items-center justify-center">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100 transition"></i>
                                                </div>
                                            </div>
                                            <label for="send_to_admins" class="text-sm text-slate-700 font-medium cursor-pointer">
                                                Send to school administrators only
                                                <span class="text-slate-500 text-xs ml-1">(Recommended)</span>
                                            </label>
                                        </div>

                                        <div class="flex items-center gap-3">
                                            <div class="relative">
                                                <input type="checkbox" 
                                                       id="schedule_later" 
                                                       name="schedule_later" 
                                                       value="1"
                                                       class="sr-only peer">
                                                <div class="w-6 h-6 bg-white border-2 border-slate-300 rounded-md peer-checked:bg-purple-600 peer-checked:border-purple-600 transition-all flex items-center justify-center">
                                                    <i class="fas fa-check text-white text-xs opacity-0 peer-checked:opacity-100 transition"></i>
                                                </div>
                                            </div>
                                            <label for="schedule_later" class="text-sm text-slate-700 font-medium cursor-pointer">
                                                Schedule for later delivery
                                                <span class="text-slate-500 text-xs ml-1">(Coming soon)</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex flex-col sm:flex-row gap-3 pt-6 border-t border-slate-100">
                                    <button type="button" 
                                            onclick="sendTestEmail()" 
                                            class="flex-1 px-6 py-3.5 bg-white border-2 border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 hover:border-slate-300 transition-all touch-target mobile-full">
                                        <div class="flex items-center justify-center gap-2">
                                            <i class="fas fa-flask"></i>
                                            <span>Send Test Email</span>
                                        </div>
                                    </button>
                                    
                                    <button type="submit" 
                                            name="send_campaign" 
                                            onclick="return confirmCampaign()"
                                            class="flex-1 px-6 py-3.5 gradient-bg text-white font-bold rounded-xl hover:opacity-90 transition-all shadow-lg hover:shadow-xl touch-target mobile-full"
                                            <?php echo !$emailService ? 'disabled title="Email service not available"' : ''; ?>>
                                        <div class="flex items-center justify-center gap-2">
                                            <i class="fas fa-paper-plane"></i>
                                            <span>Launch Campaign</span>
                                        </div>
                                        <div class="text-xs font-normal opacity-80 mt-1">
                                            Will reach <span id="launchRecipientEstimate"><?php echo $schoolStats['total']; ?></span> schools
                                        </div>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Preview & Stats Sidebar -->
                    <div class="space-y-6">
                        <!-- Campaign Preview -->
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-bold text-slate-700">Campaign Preview</h3>
                                <span class="tag tag-blue">
                                    <i class="fas fa-eye"></i>
                                    Live Preview
                                </span>
                            </div>
                            <div class="bg-slate-50 rounded-xl p-4 border border-slate-200">
                                <div class="text-xs text-slate-500 mb-2">Subject:</div>
                                <div id="previewSubject" class="font-medium text-slate-900 mb-4">Your subject will appear here</div>
                                
                                <div class="text-xs text-slate-500 mb-2">Recipients:</div>
                                <div id="previewRecipients" class="text-sm text-slate-700">
                                    <div class="flex items-center gap-2 mb-2">
                                        <div class="w-2 h-2 rounded-full bg-slate-500"></div>
                                        <span><?php echo $schoolStats['total']; ?> total schools</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Campaigns -->
                        <div class="card p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-bold text-slate-700">Recent Campaigns</h3>
                                <a href="campaign_history.php" class="text-xs text-purple-600 hover:text-purple-700 font-medium">
                                    View All
                                </a>
                            </div>
                            <div class="space-y-4">
                                <?php if (!empty($recentCampaigns)): ?>
                                    <?php foreach ($recentCampaigns as $campaign): 
                                        $successRate = $campaign['total_recipients'] > 0 
                                            ? round(($campaign['successful_sends'] / $campaign['total_recipients']) * 100) 
                                            : 0;
                                    ?>
                                    <div class="flex items-start justify-between p-3 bg-slate-50 rounded-lg">
                                        <div>
                                            <div class="font-medium text-slate-900 text-sm mb-1"><?php echo htmlspecialchars($campaign['subject']); ?></div>
                                            <div class="text-xs text-slate-500">
                                                <?php echo date('M j, g:i A', strtotime($campaign['sent_at'])); ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm font-bold <?php echo $successRate >= 90 ? 'text-emerald-600' : ($successRate >= 70 ? 'text-amber-600' : 'text-red-600'); ?>">
                                                <?php echo $successRate; ?>%
                                            </div>
                                            <div class="text-xs text-slate-500">
                                                <?php echo $campaign['successful_sends']; ?>/<?php echo $campaign['total_recipients']; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-6">
                                        <div class="w-12 h-12 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-3">
                                            <i class="fas fa-inbox text-slate-400"></i>
                                        </div>
                                        <p class="text-sm text-slate-500">No campaigns yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Tips & Best Practices -->
                        <div class="card p-6 bg-gradient-to-br from-purple-50 to-blue-50 border-purple-100">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center border border-purple-100">
                                    <i class="fas fa-lightbulb text-purple-600"></i>
                                </div>
                                <h3 class="text-sm font-bold text-purple-900">Best Practices</h3>
                            </div>
                            <ul class="space-y-3">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-emerald-500 text-xs mt-1"></i>
                                    <span class="text-sm text-purple-800">Keep subject lines under 60 characters</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-emerald-500 text-xs mt-1"></i>
                                    <span class="text-sm text-purple-800">Personalize with {school_name} variable</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-emerald-500 text-xs mt-1"></i>
                                    <span class="text-sm text-purple-800">Send during business hours (9AM-5PM)</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check text-emerald-500 text-xs mt-1"></i>
                                    <span class="text-sm text-purple-800">Test with small segment first</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card p-6">
                    <h3 class="text-sm font-bold text-slate-700 mb-4">Quick Actions</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                        <button onclick="loadTemplate('welcome')" class="p-4 bg-white border border-slate-200 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition-all touch-target">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-handshake text-blue-600"></i>
                                </div>
                                <div class="text-left">
                                    <div class="font-bold text-slate-900 text-sm">Welcome Email</div>
                                    <div class="text-xs text-slate-500 mt-0.5">New school onboarding</div>
                                </div>
                            </div>
                        </button>
                        
                        <button onclick="loadTemplate('maintenance')" class="p-4 bg-white border border-slate-200 rounded-xl hover:border-amber-300 hover:bg-amber-50 transition-all touch-target">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-amber-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-tools text-amber-600"></i>
                                </div>
                                <div class="text-left">
                                    <div class="font-bold text-slate-900 text-sm">Maintenance Alert</div>
                                    <div class="text-xs text-slate-500 mt-0.5">Scheduled downtime</div>
                                </div>
                            </div>
                        </button>
                        
                        <button onclick="loadTemplate('renewal')" class="p-4 bg-white border border-slate-200 rounded-xl hover:border-emerald-300 hover:bg-emerald-50 transition-all touch-target">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-emerald-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-sync-alt text-emerald-600"></i>
                                </div>
                                <div class="text-left">
                                    <div class="font-bold text-slate-900 text-sm">Subscription Reminder</div>
                                    <div class="text-xs text-slate-500 mt-0.5">Trial ending soon</div>
                                </div>
                            </div>
                        </button>
                        
                        <button onclick="loadTemplate('promotion')" class="p-4 bg-white border border-slate-200 rounded-xl hover:border-purple-300 hover:bg-purple-50 transition-all touch-target">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-gift text-purple-600"></i>
                                </div>
                                <div class="text-left">
                                    <div class="font-bold text-slate-900 text-sm">Special Offer</div>
                                    <div class="text-xs text-slate-500 mt-0.5">Limited time promotion</div>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-black bg-opacity-50" onclick="closePreview()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                <div class="bg-white px-6 pt-6 pb-4">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-slate-900">Email Preview</h3>
                        <button onclick="closePreview()" class="text-slate-400 hover:text-slate-600 touch-target p-2">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 mb-4">
                        <div class="text-xs text-slate-500 mb-1">Subject:</div>
                        <div id="modalSubject" class="font-medium text-slate-900"></div>
                    </div>
                    <div id="previewContent" class="border border-slate-200 rounded-xl bg-white p-6 max-h-[60vh] overflow-y-auto">
                        <!-- Preview will be loaded here -->
                    </div>
                </div>
                <div class="bg-slate-50 px-6 py-4 border-t border-slate-200">
                    <div class="flex justify-end gap-3">
                        <button onclick="closePreview()" class="px-5 py-2.5 text-slate-700 font-medium bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition touch-target">
                            Close Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="fixed inset-0 z-50 hidden bg-white bg-opacity-80">
        <div class="flex items-center justify-center h-full">
            <div class="text-center">
                <div class="w-16 h-16 border-4 border-purple-200 border-t-purple-600 rounded-full animate-spin mx-auto mb-4"></div>
                <div class="text-lg font-bold text-slate-900">Sending Campaign...</div>
                <p class="text-slate-600 mt-2">Please wait while we process your request</p>
            </div>
        </div>
    </div>

    <script>
        // Initialize counters
        const subjectInput = document.getElementById('subject');
        const subjectCounter = document.getElementById('subjectCounter');
        const messageEditor = document.getElementById('messageEditor');
        const messageInput = document.getElementById('messageInput');
        const messageCounter = document.getElementById('messageCounter');
        const previewSubject = document.getElementById('previewSubject');
        const previewRecipients = document.getElementById('previewRecipients');
        
        function updateCounters() {
            // Subject counter
            const subjectLength = subjectInput.value.length;
            subjectCounter.textContent = subjectLength;
            subjectCounter.className = subjectLength > 100 ? 'text-red-500' : (subjectLength > 60 ? 'text-amber-500' : 'text-slate-400');
            
            // Message counter
            const messageLength = messageEditor.textContent.length;
            messageCounter.textContent = messageLength;
            messageCounter.className = messageLength > 5000 ? 'text-red-500' : (messageLength > 2000 ? 'text-amber-500' : 'text-slate-400');
            
            // Update preview
            previewSubject.textContent = subjectInput.value || 'Your subject will appear here';
        }
        
        function updateMessageInput() {
            messageInput.value = messageEditor.innerHTML;
            updateCounters();
        }
        
        // Format text in editor
        function formatText(command) {
            document.execCommand(command, false, null);
            messageEditor.focus();
            updateMessageInput();
        }
        
        function insertList(type) {
            document.execCommand('insert' + (type === 'ul' ? 'UnorderedList' : 'OrderedList'), false, null);
            messageEditor.focus();
            updateMessageInput();
        }
        
        // Load template
        function loadTemplate(template) {
            const templates = {
                welcome: {
                    subject: 'Welcome to <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> - Your School is Ready!',
                    content: '<p>Dear School Administrator,</p><p>Welcome to <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?>! We\'re excited to have your school on board.</p><p><strong>Key features now available:</strong></p><ul><li>Complete student management system</li><li>Automated attendance tracking</li><li>Fee collection and invoicing</li><li>Parent communication portal</li></ul><p>To get started, please log in to your school dashboard.</p><p>Best regards,<br>The <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Team</p>'
                },
                maintenance: {
                    subject: 'Scheduled Maintenance - <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Platform',
                    content: '<p>Dear School Administrator,</p><p>This is to inform you about scheduled maintenance for the <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> platform.</p><p><strong>Maintenance Window:</strong></p><ul><li>Date: [Date]</li><li>Time: [Start Time] - [End Time] (Local Time)</li><li>Duration: Approximately 2 hours</li></ul><p>During this period, the platform will be temporarily unavailable. We recommend completing any urgent tasks before the maintenance window.</p><p>We apologize for any inconvenience and appreciate your understanding.</p><p>Best regards,<br>The <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Team</p>'
                },
                renewal: {
                    subject: 'Important: Your Trial Period is Ending Soon',
                    content: '<p>Dear School Administrator,</p><p>This is a friendly reminder that your free trial period is ending on [Date].</p><p>To continue enjoying all the features of <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?>, please consider upgrading to a paid plan before your trial ends.</p><p><strong>Available Plans:</strong></p><ul><li><strong>Starter Plan:</strong> Perfect for small schools</li><li><strong>Growth Plan:</strong> Best value for growing institutions</li><li><strong>Enterprise Plan:</strong> Full features for large schools</li></ul><p>Upgrade now to avoid any interruption in service.</p><p>Best regards,<br>The <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Team</p>'
                },
                promotion: {
                    subject: 'Special Offer: 20% Off Annual Plans!',
                    content: '<p>Dear School Administrator,</p><p>We\'re excited to offer you a special promotion: <strong>20% discount on annual plans</strong> for a limited time!</p><p>This is the perfect opportunity to lock in savings while getting the most out of <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?>.</p><p><strong>Offer Details:</strong></p><ul><li>Valid until [End Date]</li><li>Applies to all annual subscriptions</li><li>Auto-renews at standard rate</li></ul><p>Don\'t miss out on this exclusive offer for our valued schools.</p><p>Best regards,<br>The <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?> Team</p>'
                }
            };
            
            if (templates[template]) {
                subjectInput.value = templates[template].subject;
                messageEditor.innerHTML = templates[template].content;
                updateMessageInput();
                
                // Show notification
                showNotification(`"${template.replace(/^\w/, c => c.toUpperCase())}" template loaded!`);
            }
        }
        
        // Insert template button
        function insertTemplate() {
            const template = `\n\n<p><strong>Key Benefits:</strong></p>
<ul>
<li>Improved communication with parents</li>
<li>Automated administrative tasks</li>
<li>Real-time reporting and analytics</li>
<li>Secure data management</li>
</ul>
<p><strong>Next Steps:</strong></p>
<ol>
<li>Log in to your school dashboard</li>
<li>Complete your school profile</li>
<li>Add your staff and students</li>
<li>Explore all available features</li>
</ol>`;
            
            messageEditor.innerHTML += template;
            messageEditor.focus();
            updateMessageInput();
        }
        
        // Preview email
        function previewEmail() {
            const subject = subjectInput.value;
            const content = messageEditor.innerHTML;
            
            if (!subject || !content.trim()) {
                showNotification('Please add subject and content first', 'warning');
                return;
            }
            
            document.getElementById('modalSubject').textContent = subject;
            document.getElementById('previewContent').innerHTML = `
                <div style="max-width: 600px; margin: 0 auto; background: white;">
                    <div style="background: linear-gradient(135deg, #8b5cf6, #3b82f6); color: white; padding: 30px; text-align: center; border-radius: 16px 16px 0 0;">
                        <h2 style="margin: 0; font-size: 24px; font-weight: 800;"><?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?></h2>
                        <p style="opacity: 0.9; margin: 8px 0 0 0;">School Management Platform</p>
                    </div>
                    <div style="padding: 30px;">
                        <h3 style="color: #1f2937; margin-top: 0; margin-bottom: 20px;">${subject}</h3>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin: 20px 0;">
                            ${content}
                        </div>
                        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0;">
                            <p style="margin: 0; color: #92400e;"><strong>Note:</strong> This is an automated message sent to all school administrators.</p>
                        </div>
                    </div>
                    <div style="background: #f1f5f9; padding: 20px; text-align: center; border-radius: 0 0 16px 16px; color: #64748b; font-size: 12px;">
                        <p>© ${new Date().getFullYear()} <?php echo defined('APP_NAME') ? APP_NAME : 'AcademixSuite'; ?>. All rights reserved.</p>
                        <p>If you have questions, contact our support team.</p>
                    </div>
                </div>
            `;
            
            document.getElementById('previewModal').classList.remove('hidden');
        }
        
        function closePreview() {
            document.getElementById('previewModal').classList.add('hidden');
        }
        
        // Send test email
        function sendTestEmail() {
            const subject = subjectInput.value;
            const message = messageEditor.innerHTML;
            
            if (!subject || !message.trim()) {
                showNotification('Please add subject and content first', 'warning');
                return;
            }
            
            if (confirm('Send test email to yourself?')) {
                // Create hidden form for test email
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'test_email.php';
                form.style.display = 'none';
                
                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = 'csrf_token';
                csrf.value = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
                form.appendChild(csrf);
                
                const subjectInput = document.createElement('input');
                subjectInput.type = 'hidden';
                subjectInput.name = 'subject';
                subjectInput.value = subject;
                form.appendChild(subjectInput);
                
                const messageInput = document.createElement('input');
                messageInput.type = 'hidden';
                messageInput.name = 'message';
                messageInput.value = message;
                form.appendChild(messageInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Confirm campaign
        function confirmCampaign() {
            const subject = subjectInput.value;
            const message = messageEditor.innerHTML;
            
            if (!subject || !message.trim()) {
                showNotification('Please add subject and content first', 'warning');
                return false;
            }
            
            const recipientCount = getRecipientEstimate();
            
            const confirmed = confirm(`This campaign will send emails to ${recipientCount} school administrators.\n\nSubject: "${subject}"\n\nAre you sure you want to proceed?`);
            
            if (confirmed) {
                document.getElementById('loadingOverlay').classList.remove('hidden');
                return true;
            }
            
            return false;
        }
        
        // Show notification
        function showNotification(message, type = 'success') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-xl shadow-lg animate-slide-in ${type === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-amber-50 border border-amber-200 text-amber-800'}`;
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i>
                    <span class="font-medium">${message}</span>
                </div>
            `;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }

        function getRecipientEstimate() {
            const statusFilter = document.getElementById('status_filter').value;
            const includeTrial = document.getElementById('include_trial').checked;
            const activeCount = <?php echo (int)$schoolStats['active']; ?>;
            const trialCount = <?php echo (int)$schoolStats['trial']; ?>;
            const totalCount = <?php echo (int)$schoolStats['total']; ?>;

            switch (statusFilter) {
                case 'all':
                    return includeTrial ? totalCount : Math.max(totalCount - trialCount, 0);
                case 'active':
                    return includeTrial ? activeCount + trialCount : activeCount;
                case 'trial':
                    return trialCount;
                case 'operational':
                    return includeTrial ? activeCount + trialCount : activeCount;
                default:
                    return totalCount;
            }
        }
        
        // Update preview when filters change
        function updateRecipientPreview() {
            const statusFilter = document.getElementById('status_filter').value;
            const includeTrial = document.getElementById('include_trial').checked;
            let activeCount = <?php echo $schoolStats['active']; ?>;
            let trialCount = <?php echo $schoolStats['trial']; ?>;
            let totalCount = <?php echo $schoolStats['total']; ?>;
            let nonTrialTotal = Math.max(totalCount - trialCount, 0);
            
            let previewHtml = '';
            switch(statusFilter) {
                case 'all':
                    previewHtml = `
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-2 h-2 rounded-full bg-slate-500"></div>
                            <span>${includeTrial ? totalCount : nonTrialTotal} total schools${includeTrial ? '' : ' excluding trials'}</span>
                        </div>
                    `;
                    break;
                case 'active':
                    previewHtml = `
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                            <span>${activeCount} active schools</span>
                        </div>
                        ${includeTrial ? `
                        <div class="flex items-center gap-2 mt-2">
                            <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                            <span>${trialCount} trial schools included</span>
                        </div>` : ''}
                    `;
                    break;
                case 'trial':
                    previewHtml = `
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                            <span>${trialCount} trial schools</span>
                        </div>
                    `;
                    break;
                case 'operational':
                    previewHtml = `
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                            <span>${activeCount} active schools</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-blue-500"></div>
                            <span>${includeTrial ? trialCount : 0} trial schools</span>
                        </div>
                    `;
                    break;
            }
            
            previewRecipients.innerHTML = previewHtml;
            document.getElementById('launchRecipientEstimate').textContent = getRecipientEstimate();
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCounters();
            subjectInput.addEventListener('input', updateCounters);
            messageEditor.addEventListener('input', updateMessageInput);
            document.getElementById('status_filter')?.addEventListener('change', updateRecipientPreview);
            document.getElementById('include_trial')?.addEventListener('change', updateRecipientPreview);
            updateRecipientPreview();
            
            // Set placeholder for contenteditable div
            messageEditor.addEventListener('focus', function() {
                if (this.textContent === '') {
                    this.innerHTML = '';
                }
            });
            
            messageEditor.addEventListener('blur', function() {
                if (this.innerHTML === '<br>') {
                    this.innerHTML = '';
                }
            });
            
            // Add animation classes
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slide-in {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                .animate-slide-in { animation: slide-in 0.3s ease-out; }
            `;
            document.head.appendChild(style);
        });
        
        // Sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar?.classList.toggle('-translate-x-full');
            overlay?.classList.toggle('active');
        }
        
        function mobileSidebarToggle() {
            toggleSidebar();
        }
    </script>
</body>
</html>
<?php
error_log("========== BULK EMAIL SENDER END ==========\n\n");
?>
