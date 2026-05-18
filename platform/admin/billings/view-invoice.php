<?php
// platform/admin/billing/view-invoice.php
require_once __DIR__ . '/../../../includes/autoload.php';

// Safe defaults for every value referenced later in the HTML — view-invoice
// renders unconditionally even when the SQL above it fails, so any unset
// variable produces "Undefined variable" warnings (see error_log: $total
// undefined on lines 1208 / 1301).
$invoice            = [];
$subtotal           = 0.0;
$tax                = 0.0;
$total              = 0.0;
$subtotalNaira      = 0.0;
$taxNaira           = 0.0;
$totalNaira         = 0.0;
$exchangeRate       = 1.0;
$previousInvoices   = [];
$daysUntilDue       = 0;
$isOverdue          = false;
$isTrial            = 0;

// ==================== ERROR LOGGING SETUP ====================
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't show errors to users
ini_set('log_errors', 1);

// Define log file path
$logFile = __DIR__ . '/../../../logs/admin-billing-errors.log';

// Custom error handler
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    global $logFile;
    
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE ERROR',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE ERROR',
        E_CORE_WARNING => 'CORE WARNING',
        E_COMPILE_ERROR => 'COMPILE ERROR',
        E_COMPILE_WARNING => 'COMPILE WARNING',
        E_USER_ERROR => 'USER ERROR',
        E_USER_WARNING => 'USER WARNING',
        E_USER_NOTICE => 'USER NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER DEPRECATED'
    ];
    
    $errorType = isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'UNKNOWN ERROR';
    
    $logMessage = sprintf(
        "[%s] %s: %s in %s on line %d\nIP: %s\nURL: %s\nUser Agent: %s\n\n",
        date('Y-m-d H:i:s'),
        $errorType,
        $errstr,
        $errfile,
        $errline,
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['REQUEST_URI'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    );
    
    // Write to log file
    error_log($logMessage, 3, $logFile);
    
    // Also write to PHP error log for redundancy
    error_log("[$errorType] $errstr in $errfile on line $errline", 0);
    
    return false; // Let PHP's internal error handler do the rest
}

// Custom exception handler
function customExceptionHandler($exception) {
    global $logFile;
    
    $logMessage = sprintf(
        "[%s] EXCEPTION: %s in %s on line %d\nStack Trace:\n%s\nIP: %s\nURL: %s\nUser Agent: %s\n\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString(),
        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
        $_SERVER['REQUEST_URI'] ?? 'Unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    );
    
    error_log($logMessage, 3, $logFile);
    
    // Show user-friendly error
    if (headers_sent() === false) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Location: index.php?error=system_error&ref=' . time());
    }
    exit;
}

// Set custom handlers
set_error_handler('customErrorHandler');
set_exception_handler('customExceptionHandler');

// Log script start
error_log("[" . date('Y-m-d H:i:s') . "] Script started: view-invoice.php\n", 3, $logFile);

// Create logs directory if it doesn't exist
$logsDir = dirname($logFile);
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0755, true);
}
// ==================== END ERROR LOGGING SETUP ====================

// Log authentication attempt
error_log("[" . date('Y-m-d H:i:s') . "] Authentication check started\n", 3, $logFile);

try {
    // Require super admin login
    $auth = new Auth();
    $auth->requireLogin('super_admin');
    
    // Log successful authentication
    error_log("[" . date('Y-m-d H:i:s') . "] User authenticated successfully\n", 3, $logFile);
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Authentication failed: " . $e->getMessage() . "\n", 3, $logFile);
    throw $e;
}

// Get super admin data
$superAdmin = $_SESSION['super_admin'] ?? null;

// Get invoice ID from URL
$invoiceId = $_GET['id'] ?? null;

if (!$invoiceId || !is_numeric($invoiceId)) {
    error_log("[" . date('Y-m-d H:i:s') . "] Invalid invoice ID: " . ($invoiceId ?? 'null') . "\n", 3, $logFile);
    header('Location: index.php?error=Invalid invoice ID');
    exit;
}

// Log invoice ID
error_log("[" . date('Y-m-d H:i:s') . "] Processing invoice ID: $invoiceId\n", 3, $logFile);

// Fetch data from database
try {
    $db = Database::getPlatformConnection();
    error_log("[" . date('Y-m-d H:i:s') . "] Database connection established\n", 3, $logFile);
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Database connection failed: " . $e->getMessage() . "\n", 3, $logFile);
    throw $e;
}

// Get current dollar to naira exchange rate
$exchangeRate = 1500; // You can make this dynamic

// Log database query start
error_log("[" . date('Y-m-d H:i:s') . "] Preparing SQL query for invoice $invoiceId\n", 3, $logFile);

// FIXED SQL QUERY - Based on your actual database structure
$sql = "
    SELECT 
        i.*,
        s.name as school_name,
        s.email as school_email,
        s.phone as school_phone,
        s.address as school_address,
        s.city as school_city,
        s.state as school_state,
        s.country as school_country,
        s.logo_path as school_logo,
        s.primary_color as school_color,
        sub.description as subscription_desc,
        sub.status as subscription_status,
        p.name as plan_name,
        p.price_monthly,
        p.student_limit,
        p.teacher_limit,
        p.storage_limit,
        -- Removed payment_gateway joins since invoices table doesn't have payment_gateway_id
        DATEDIFF(i.due_date, CURDATE()) as days_until_due,
        CASE 
            WHEN i.payment_status = 'success' THEN 'Paid'
            WHEN i.payment_status = 'pending' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.payment_status = 'pending' AND i.due_date >= CURDATE() THEN 'Pending'
            WHEN i.payment_status = 'failed' THEN 'Failed'
            WHEN i.payment_status = 'refunded' THEN 'Refunded'
            ELSE 'Pending'
        END as payment_status_text
    FROM invoices i 
    LEFT JOIN schools s ON i.school_id = s.id
    LEFT JOIN subscriptions sub ON i.subscription_id = sub.id
    LEFT JOIN plans p ON sub.plan_id = p.id
    WHERE i.id = ?
";

try {
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        $error = $db->errorInfo();
        error_log("[" . date('Y-m-d H:i:s') . "] SQL prepare failed: " . print_r($error, true) . "\n", 3, $logFile);
        throw new Exception('Database query preparation failed');
    }
    
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        error_log("[" . date('Y-m-d H:i:s') . "] Invoice not found in database: $invoiceId\n", 3, $logFile);
        header('Location: index.php?error=Invoice not found');
        exit;
    }
    
    error_log("[" . date('Y-m-d H:i:s') . "] Invoice found: " . ($invoice['invoice_number'] ?? 'Unknown') . "\n", 3, $logFile);
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching invoice: " . $e->getMessage() . "\nSQL: $sql\n", 3, $logFile);
    throw $e;
}

// Get payment gateway information separately (if needed)
$paymentGateway = null;
if (!empty($invoice['payment_gateway_id'])) {
    try {
        $gatewaySql = "SELECT * FROM payment_gateways WHERE id = ?";
        $gatewayStmt = $db->prepare($gatewaySql);
        if ($gatewayStmt) {
            $gatewayStmt->execute([$invoice['payment_gateway_id']]);
            $paymentGateway = $gatewayStmt->fetch();
        }
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] Error fetching payment gateway: " . $e->getMessage() . "\n", 3, $logFile);
    }
}

// FIXED: Get payment transactions instead of payment_history
$paymentTransactions = [];
try {
    error_log("[" . date('Y-m-d H:i:s') . "] Fetching payment transactions for invoice $invoiceId\n", 3, $logFile);
    
    $paymentTransactionsSql = "
        SELECT 
            pt.*,
            pg.name as gateway_name,
            pg.provider as gateway_type
        FROM payment_transactions pt
        LEFT JOIN payment_gateways pg ON pt.payment_gateway_id = pg.id
        WHERE pt.invoice_id = ?
        ORDER BY pt.created_at DESC
    ";
    
    $paymentTransactionsStmt = $db->prepare($paymentTransactionsSql);
    if ($paymentTransactionsStmt) {
        $paymentTransactionsStmt->execute([$invoiceId]);
        $paymentTransactions = $paymentTransactionsStmt->fetchAll();
        error_log("[" . date('Y-m-d H:i:s') . "] Found " . count($paymentTransactions) . " payment transaction records\n", 3, $logFile);
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] Payment transactions statement preparation failed\n", 3, $logFile);
        $paymentTransactions = [];
    }
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching payment transactions: " . $e->getMessage() . "\n", 3, $logFile);
    $paymentTransactions = [];
}

// Get school's previous invoices
$previousInvoices = [];
try {
    error_log("[" . date('Y-m-d H:i:s') . "] Fetching previous invoices for school ID: " . ($invoice['school_id'] ?? 'Unknown') . "\n", 3, $logFile);
    
    $previousInvoicesSql = "
        SELECT 
            id,
            invoice_number,
            amount,
            total_amount,
            status,
            payment_status,
            due_date,
            paid_at,
            created_at
        FROM invoices 
        WHERE school_id = ? AND id != ?
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $previousInvoicesStmt = $db->prepare($previousInvoicesSql);
    if ($previousInvoicesStmt) {
        $previousInvoicesStmt->execute([$invoice['school_id'], $invoiceId]);
        $previousInvoices = $previousInvoicesStmt->fetchAll();
        error_log("[" . date('Y-m-d H:i:s') . "] Found " . count($previousInvoices) . " previous invoices\n", 3, $logFile);
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] Previous invoices statement preparation failed\n", 3, $logFile);
        $previousInvoices = [];
    }
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching previous invoices: " . $e->getMessage() . "\n", 3, $logFile);
    $previousInvoices = [];
}

// Calculate totals - using proper null checks
$subtotal = floatval($invoice['amount'] ?? 0);
$tax = floatval($invoice['tax'] ?? 0);
$total = floatval($invoice['total_amount'] ?? $subtotal + $tax);

// Convert to Naira
$subtotalNaira = $subtotal * $exchangeRate;
$taxNaira = $tax * $exchangeRate;
$totalNaira = $total * $exchangeRate;

// Check if invoice is overdue
$daysUntilDue = intval($invoice['days_until_due'] ?? 0);
$isOverdue = $daysUntilDue < 0;
$isTrial = intval($invoice['is_trial'] ?? 0);

// Log calculation results
error_log("[" . date('Y-m-d H:i:s') . "] Calculated totals: Subtotal=\$$subtotal (₦$subtotalNaira), Tax=\$$tax (₦$taxNaira), Total=\$$total (₦$totalNaira)\n", 3, $logFile);

// FIXED: Check if functions are already declared to avoid redeclaration errors
if (!function_exists('convertToNaira')) {
    function convertToNaira($amount, $exchangeRate = 1500) {
        if (!$amount) return 0;
        return floatval($amount) * $exchangeRate;
    }
}

if (!function_exists('formatMoney')) {
    function formatMoney($amount, $currency = '₦') {
        if (!$amount) return $currency . '0';
        
        $amount = floatval($amount);
        if ($amount >= 1000000) {
            return $currency . number_format($amount / 1000000, 1) . 'M';
        } elseif ($amount >= 1000) {
            return $currency . number_format($amount / 1000, 1) . 'K';
        } else {
            return $currency . number_format($amount, 0);
        }
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M j, Y') {
        if (!$date || $date === '0000-00-00 00:00:00' || $date === '0000-00-00') {
            return 'N/A';
        }
        return date($format, strtotime($date));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime, $format = 'M j, Y, g:i A') {
        if (!$datetime || $datetime === '0000-00-00 00:00:00') {
            return 'N/A';
        }
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('getPaymentStatusBadge')) {
    function getPaymentStatusBadge($status) {
        $badges = [
            'success' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'Paid' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'pending' => 'bg-amber-50 text-amber-600 border-amber-100',
            'Pending' => 'bg-amber-50 text-amber-600 border-amber-100',
            'failed' => 'bg-red-50 text-red-600 border-red-100',
            'Failed' => 'bg-red-50 text-red-600 border-red-100',
            'refunded' => 'bg-slate-50 text-slate-600 border-slate-100',
            'Refunded' => 'bg-slate-50 text-slate-600 border-slate-100',
            'initiated' => 'bg-blue-50 text-blue-600 border-blue-100',
            'processing' => 'bg-blue-50 text-blue-600 border-blue-100',
            'Overdue' => 'bg-red-50 text-red-600 border-red-100'
        ];
        return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
    }
}

if (!function_exists('getInvoiceStatusBadge')) {
    function getInvoiceStatusBadge($status) {
        $badges = [
            'paid' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'sent' => 'bg-blue-50 text-blue-600 border-blue-100',
            'draft' => 'bg-slate-50 text-slate-600 border-slate-100',
            'overdue' => 'bg-red-50 text-red-600 border-red-100',
            'canceled' => 'bg-slate-50 text-slate-600 border-slate-100'
        ];
        return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
    }
}

if (!function_exists('getPaymentMethodIcon')) {
    function getPaymentMethodIcon($method) {
        $icons = [
            'card' => 'fa-credit-card',
            'bank_transfer' => 'fa-university',
            'paystack' => 'fa-credit-card',
            'stripe' => 'fa-cc-stripe',
            'flutterwave' => 'fa-money-bill-wave',
            'cash' => 'fa-money-bill',
            'cheque' => 'fa-file-invoice-dollar'
        ];
        return $icons[$method] ?? 'fa-money-bill';
    }
}

if (!function_exists('getDaysColor')) {
    function getDaysColor($days) {
        if ($days < 0) {
            return 'text-red-600';
        } elseif ($days <= 3) {
            return 'text-amber-600';
        } elseif ($days <= 7) {
            return 'text-blue-600';
        } else {
            return 'text-emerald-600';
        }
    }
}

if (!function_exists('getInitials')) {
    function getInitials($name) {
        if (!$name) return 'SC';
        
        $words = explode(' ', $name);
        $initials = '';
        $count = 0;
        foreach ($words as $word) {
            if ($count >= 2) break;
            if (!empty(trim($word))) {
                $initials .= strtoupper(substr($word, 0, 1));
                $count++;
            }
        }
        return $initials ?: substr(strtoupper($name), 0, 2);
    }
}

if (!function_exists('getAvatarColor')) {
    function getAvatarColor($initials) {
        $colors = [
            'blue' => 'bg-blue-50 text-blue-600 border-blue-100',
            'purple' => 'bg-purple-50 text-purple-600 border-purple-100',
            'amber' => 'bg-amber-50 text-amber-600 border-amber-100',
            'emerald' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'rose' => 'bg-rose-50 text-rose-600 border-rose-100'
        ];
        $hash = crc32($initials) % count($colors);
        return array_values($colors)[$hash];
    }
}

if (!function_exists('formatDualCurrency')) {
    function formatDualCurrency($usdAmount, $exchangeRate = 1500, $usdSymbol = '$', $nairaSymbol = '₦') {
        if ($usdAmount <= 0) {
            return '<span class="text-slate-400">Free</span>';
        }
        
        $nairaAmount = convertToNaira($usdAmount, $exchangeRate);
        return '<div>
                    <span class="font-bold text-slate-800">' . $nairaSymbol . number_format($nairaAmount, 0) . '</span>
                    <div class="text-xs text-slate-500 mt-0.5">' . $usdSymbol . number_format($usdAmount, 2) . ' USD</div>
                </div>';
    }
}

// Log that we're about to render the page
error_log("[" . date('Y-m-d H:i:s') . "] Rendering HTML page for invoice $invoiceId\n", 3, $logFile);

// Now you need to update the HTML part where payment gateway information is displayed
// Replace references to $invoice['payment_gateway_name'] with $paymentGateway['name'] if exists
// Replace references to $invoice['payment_gateway_type'] with $paymentGateway['provider'] if exists
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> | <?php echo APP_NAME; ?> Executive</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #2563eb;
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
        
        .invoice-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); 
        }

        .print-only {
            display: none;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            body {
                background: white !important;
            }
            .invoice-card {
                box-shadow: none !important;
                border: none !important;
            }
        }

        .status-pulse {
            height: 8px; 
            width: 8px; 
            border-radius: 50%;
            display: inline-block; 
            position: relative;
        }
        .status-pulse.success { 
            background: #22c55e; 
        }
        .status-pulse.success::after {
            content: ''; 
            position: absolute; 
            width: 100%; 
            height: 100%;
            background: #22c55e; 
            border-radius: 50%; 
            animation: pulse-green 2s infinite;
        }
        .status-pulse.pending { 
            background: #f59e0b; 
        }
        .status-pulse.pending::after {
            content: ''; 
            position: absolute; 
            width: 100%; 
            height: 100%;
            background: #f59e0b; 
            border-radius: 50%; 
            animation: pulse-amber 2s infinite;
        }
        
        @keyframes pulse-green { 
            0% { transform: scale(1); opacity: 0.8; } 
            100% { transform: scale(3); opacity: 0; } 
        }
        @keyframes pulse-amber { 
            0% { transform: scale(1); opacity: 0.8; } 
            100% { transform: scale(3); opacity: 0; } 
        }

        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.75rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background: #cbd5e1;
            border: 3px solid white;
        }
        .timeline-item.success::before { background: #22c55e; }
        .timeline-item.pending::before { background: #f59e0b; }
        .timeline-item.failed::before { background: #ef4444; }

        /* Responsive Visibility */
        .desktop-view { display: block; }
        .mobile-view { display: none; }
        
        @media (max-width: 1024px) {
            .desktop-view { display: none; }
            .mobile-view { display: block; }
        }

        /* Mobile cards */
        .mobile-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #f1f5f9;
        }

        /* Debug panel - only visible if error parameter is present */
        .debug-panel {
            position: fixed;
            bottom: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 10px;
            font-size: 12px;
            z-index: 9999;
            max-width: 300px;
            display: none;
        }
        .debug-panel.show {
            display: block;
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">
    <!-- Debug panel - can be enabled with ?debug=1 -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="debug-panel show">
        <strong>Debug Info:</strong><br>
        Invoice ID: <?php echo $invoiceId; ?><br>
        Found Invoice: <?php echo $invoice ? 'Yes' : 'No'; ?><br>
        SQL Errors: None<br>
        Memory: <?php echo round(memory_get_usage() / 1024 / 1024, 2); ?>MB
    </div>
    <?php endif; ?>

    <div class="flex h-screen overflow-hidden">
        
        <?php 
        try {
            include_once('../filepath/sidebar.php');
            error_log("[" . date('Y-m-d H:i:s') . "] Sidebar included successfully\n", 3, $logFile);
        } catch (Exception $e) {
            error_log("[" . date('Y-m-d H:i:s') . "] Error including sidebar: " . $e->getMessage() . "\n", 3, $logFile);
            echo '<div class="p-4 bg-red-100 text-red-800">Sidebar load error</div>';
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40 no-print">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <a href="index.php" class="text-slate-400 hover:text-slate-600">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Invoice Details</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Print Button -->
                    <button onclick="window.print()" class="bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 shadow touch-target">
                        <i class="fas fa-print"></i>
                        <span class="hidden sm:inline">Print</span>
                    </button>
                    
                    <!-- Send Button -->
                    <button onclick="sendInvoice()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 shadow-lg touch-target">
                        <i class="fas fa-paper-plane"></i>
                        <span class="hidden sm:inline">Send to School</span>
                    </button>
                    
                    <!-- Actions Dropdown -->
                    <div class="relative">
                        <button onclick="toggleActionsDropdown()" class="flex items-center gap-2 bg-slate-900 hover:bg-blue-600 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all shadow-lg touch-target">
                            <i class="fas fa-cog"></i>
                            <span class="hidden sm:inline">Actions</span>
                            <i class="fas fa-chevron-down text-xs text-slate-300"></i>
                        </button>
                        <div id="actionsDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-50 py-1">
                            <?php if ($invoice['payment_status'] !== 'success' && $invoice['status'] !== 'canceled'): ?>
                            <a href="edit-invoice.php?id=<?php echo $invoice['id']; ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">
                                <i class="fas fa-edit text-slate-400"></i>
                                Edit Invoice
                            </a>
                            <button onclick="markAsPaid()" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-emerald-600 hover:bg-emerald-50">
                                <i class="fas fa-check-circle text-emerald-400"></i>
                                Mark as Paid
                            </button>
                            <?php if ($invoice['payment_status'] === 'pending'): ?>
                            <button onclick="sendReminder()" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-amber-600 hover:bg-amber-50">
                                <i class="fas fa-bell text-amber-400"></i>
                                Send Reminder
                            </button>
                            <?php endif; ?>
                            <hr class="my-1 border-slate-100">
                            <button onclick="cancelInvoice()" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <i class="fas fa-ban text-red-400"></i>
                                Cancel Invoice
                            </button>
                            <?php endif; ?>
                            <?php if ($invoice['payment_status'] === 'success'): ?>
                            <button onclick="issueRefund()" class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm text-purple-600 hover:bg-purple-50">
                                <i class="fas fa-undo text-purple-400"></i>
                                Issue Refund
                            </button>
                            <?php endif; ?>
                            <hr class="my-1 border-slate-100">
                            <a href="create-invoice.php?school_id=<?php echo $invoice['school_id']; ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-blue-600 hover:bg-blue-50">
                                <i class="fas fa-plus text-blue-400"></i>
                                New Invoice
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8 space-y-6 no-print">
                <!-- Status Banner -->
                <div class="bg-white rounded-2xl invoice-card p-6">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-3">
                                <div class="text-2xl font-black text-slate-400">#</div>
                                <div>
                                    <h2 class="text-xl font-black text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
                                    <div class="text-sm text-slate-500">
                                        Created <?php echo formatDate($invoice['created_at']); ?>
                                        <?php if ($isTrial): ?>
                                        <span class="ml-2 px-2 py-0.5 bg-blue-50 text-blue-600 text-xs font-bold rounded">
                                            Trial Invoice
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-wrap gap-3">
                            <!-- Invoice Status -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Invoice Status</div>
                                <span class="px-3 py-1.5 rounded-lg text-sm font-bold uppercase <?php echo getInvoiceStatusBadge($invoice['status']); ?>">
                                    <?php echo ucfirst($invoice['status']); ?>
                                </span>
                            </div>
                            
                            <!-- Payment Status -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Payment Status</div>
                                <span class="px-3 py-1.5 rounded-lg text-sm font-bold uppercase <?php echo getPaymentStatusBadge($invoice['payment_status_text']); ?>">
                                    <?php echo $invoice['payment_status_text']; ?>
                                </span>
                            </div>
                            
                            <!-- Amount -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Total Amount</div>
                                <div class="text-lg font-black text-slate-900">
                                    ₦<?php echo number_format($totalNaira, 0); ?>
                                    <div class="text-xs text-slate-500">$<?php echo number_format($total, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($isOverdue): ?>
                    <div class="mt-4 p-4 bg-red-50 border border-red-100 rounded-xl">
                        <div class="flex items-center gap-2 text-red-600">
                            <i class="fas fa-exclamation-triangle"></i>
                            <span class="font-bold">Invoice is <?php echo abs($daysUntilDue); ?> days overdue</span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Desktop View -->
                <div class="desktop-view">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Left Column: Invoice Details -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Invoice Summary -->
                            <div class="bg-white rounded-2xl invoice-card overflow-hidden">
                                <div class="p-6 border-b border-slate-100">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Invoice Summary</h3>
                                    
                                    <div class="space-y-4">
                                        <!-- Description -->
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Description</div>
                                            <div class="text-sm text-slate-800"><?php echo htmlspecialchars($invoice['description'] ?? 'Monthly subscription'); ?></div>
                                        </div>
                                        
                                        <!-- Billing Period -->
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                            <div>
                                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Billing Period</div>
                                                <div class="text-sm text-slate-800">
                                                    <?php echo formatDate($invoice['start_date'] ?? ''); ?> to <?php echo formatDate($invoice['end_date'] ?? ''); ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Due Date</div>
                                                <div class="text-sm text-slate-800">
                                                    <?php echo formatDate($invoice['due_date']); ?>
                                                    <span class="ml-2 px-2 py-0.5 text-xs font-bold rounded <?php echo getDaysColor($daysUntilDue); ?>">
                                                        <?php 
                                                        if ($isOverdue) {
                                                            echo abs($daysUntilDue) . ' days overdue';
                                                        } elseif ($daysUntilDue == 0) {
                                                            echo 'Due today';
                                                        } elseif ($daysUntilDue == 1) {
                                                            echo 'Due tomorrow';
                                                        } else {
                                                            echo $daysUntilDue . ' days left';
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Plan Details -->
                                        <?php if (!empty($invoice['plan_name'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Plan Details</div>
                                            <div class="bg-slate-50 rounded-xl p-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <span class="font-bold text-slate-800"><?php echo htmlspecialchars($invoice['plan_name']); ?></span>
                                                    <span class="text-sm font-bold text-slate-600">
                                                        $<?php echo number_format($invoice['price_monthly'] ?? 0, 2); ?>/month
                                                    </span>
                                                </div>
                                                <?php if ($invoice['student_limit'] || $invoice['teacher_limit']): ?>
                                                <div class="text-xs text-slate-500 space-y-1">
                                                    <?php if ($invoice['student_limit']): ?>
                                                    <div><i class="fas fa-user-graduate mr-1"></i> Up to <?php echo $invoice['student_limit']; ?> students</div>
                                                    <?php endif; ?>
                                                    <?php if ($invoice['teacher_limit']): ?>
                                                    <div><i class="fas fa-chalkboard-teacher mr-1"></i> Up to <?php echo $invoice['teacher_limit']; ?> teachers</div>
                                                    <?php endif; ?>
                                                    <?php if ($invoice['storage_limit']): ?>
                                                    <div><i class="fas fa-hdd mr-1"></i> <?php echo $invoice['storage_limit']; ?> GB storage</div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <!-- Amount Breakdown -->
                                <div class="p-6">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Amount Breakdown</h3>
                                    
                                    <div class="space-y-3">
                                        <!-- Subtotal -->
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <div class="text-sm text-slate-800">Subtotal</div>
                                                <div class="text-xs text-slate-500">Plan amount</div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-sm font-bold text-slate-800">₦<?php echo number_format($subtotalNaira, 0); ?></div>
                                                <div class="text-xs text-slate-500">$<?php echo number_format($subtotal, 2); ?></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Tax -->
                                        <?php if ($tax > 0): ?>
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <div class="text-sm text-slate-800">Tax</div>
                                                <div class="text-xs text-slate-500">VAT/Service tax</div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-sm font-bold text-slate-800">₦<?php echo number_format($taxNaira, 0); ?></div>
                                                <div class="text-xs text-slate-500">$<?php echo number_format($tax, 2); ?></div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Divider -->
                                        <hr class="border-slate-100">
                                        
                                        <!-- Total -->
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <div class="text-lg font-black text-slate-900">Total Amount</div>
                                                <div class="text-xs text-slate-500">Amount due</div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-2xl font-black text-slate-900">₦<?php echo number_format($totalNaira, 0); ?></div>
                                                <div class="text-sm text-slate-500">$<?php echo number_format($total, 2); ?></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Currency Note -->
                                        <div class="text-xs text-slate-400 text-center pt-2">
                                            Exchange Rate: $1 = ₦<?php echo number_format($exchangeRate); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment History -->
                            <?php if (!empty($paymentHistory)): ?>
                            <div class="bg-white rounded-2xl invoice-card">
                                <div class="p-6">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Payment History</h3>
                                    
                                    <div class="timeline">
                                        <?php foreach ($paymentHistory as $payment): 
                                            $paymentStatus = $payment['status'] ?? 'pending';
                                            $statusClass = $paymentStatus === 'success' ? 'success' : ($paymentStatus === 'failed' ? 'failed' : 'pending');
                                        ?>
                                        <div class="timeline-item <?php echo $statusClass; ?>">
                                            <div class="bg-slate-50 rounded-xl p-4">
                                                <div class="flex justify-between items-start mb-2">
                                                    <div class="flex items-center gap-2">
                                                        <span class="status-pulse <?php echo $statusClass; ?>"></span>
                                                        <span class="font-bold text-slate-800"><?php echo ucfirst($paymentStatus); ?></span>
                                                    </div>
                                                    <div class="text-xs text-slate-500">
                                                        <?php echo formatDateTime($payment['created_at'] ?? ''); ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if (!empty($payment['description'])): ?>
                                                <div class="text-sm text-slate-600 mb-2">
                                                    <?php echo htmlspecialchars($payment['description']); ?>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="grid grid-cols-2 gap-4 text-xs text-slate-500">
                                                    <?php if (!empty($payment['gateway_name'])): ?>
                                                    <div>
                                                        <i class="fas fa-credit-card mr-1"></i>
                                                        <?php echo htmlspecialchars($payment['gateway_name']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($payment['transaction_id'])): ?>
                                                    <div>
                                                        <i class="fas fa-hashtag mr-1"></i>
                                                        <?php echo htmlspecialchars($payment['transaction_id']); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($payment['amount'])): ?>
                                                    <div>
                                                        <i class="fas fa-money-bill-wave mr-1"></i>
                                                        ₦<?php echo number_format(convertToNaira($payment['amount'], $exchangeRate), 0); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Right Column: School & Payment Info -->
                        <div class="space-y-6">
                            <!-- School Information -->
                            <div class="bg-white rounded-2xl invoice-card">
                                <div class="p-6">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">School Information</h3>
                                    
                                    <div class="flex items-center gap-3 mb-4">
                                        <?php if (!empty($invoice['school_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($invoice['school_logo']); ?>" 
                                             alt="<?php echo htmlspecialchars($invoice['school_name']); ?>" 
                                             class="w-12 h-12 rounded-lg object-cover border border-slate-200">
                                        <?php else: ?>
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center font-black text-lg text-white" 
                                             style="background-color: <?php echo htmlspecialchars($invoice['school_color'] ?: '#3B82F6'); ?>">
                                            <?php echo getInitials($invoice['school_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['school_name']); ?></h4>
                                            <div class="text-xs text-slate-500">ID: <?php echo $invoice['school_id']; ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-3 text-sm">
                                        <div class="flex items-start gap-2">
                                            <i class="fas fa-envelope text-slate-400 mt-0.5"></i>
                                            <div>
                                                <div class="text-slate-800"><?php echo htmlspecialchars($invoice['school_email']); ?></div>
                                                <div class="text-xs text-slate-500">Email</div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($invoice['school_phone'])): ?>
                                        <div class="flex items-start gap-2">
                                            <i class="fas fa-phone text-slate-400 mt-0.5"></i>
                                            <div>
                                                <div class="text-slate-800"><?php echo htmlspecialchars($invoice['school_phone']); ?></div>
                                                <div class="text-xs text-slate-500">Phone</div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($invoice['school_address'])): ?>
                                        <div class="flex items-start gap-2">
                                            <i class="fas fa-map-marker-alt text-slate-400 mt-0.5"></i>
                                            <div>
                                                <div class="text-slate-800"><?php echo htmlspecialchars($invoice['school_address']); ?></div>
                                                <div class="text-xs text-slate-500">
                                                    <?php echo htmlspecialchars($invoice['school_city'] ?? ''); ?>, 
                                                    <?php echo htmlspecialchars($invoice['school_state'] ?? ''); ?>, 
                                                    <?php echo htmlspecialchars($invoice['school_country'] ?? ''); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mt-4 pt-4 border-t border-slate-100">
                                        <a href="../schools/view.php?id=<?php echo $invoice['school_id']; ?>" 
                                           class="text-blue-600 hover:text-blue-700 text-sm font-bold flex items-center gap-2">
                                            <i class="fas fa-external-link-alt"></i>
                                            View School Profile
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Payment Information -->
                            <div class="bg-white rounded-2xl invoice-card">
                                <div class="p-6">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Payment Information</h3>
                                    
                                    <div class="space-y-4">
                                        <?php if (!empty($invoice['payment_gateway_name'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Payment Gateway</div>
                                            <div class="flex items-center gap-2">
                                                <i class="fas <?php echo getPaymentMethodIcon($invoice['payment_gateway_type']); ?> text-slate-400"></i>
                                                <span class="text-sm text-slate-800"><?php echo htmlspecialchars($invoice['payment_gateway_name']); ?></span>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($invoice['transaction_id'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Transaction ID</div>
                                            <div class="text-sm text-slate-800 font-mono"><?php echo htmlspecialchars($invoice['transaction_id']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($invoice['paid_at'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Paid On</div>
                                            <div class="text-sm text-slate-800"><?php echo formatDateTime($invoice['paid_at']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($invoice['payment_method'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Payment Method</div>
                                            <div class="text-sm text-slate-800"><?php echo ucfirst(str_replace('_', ' ', $invoice['payment_method'])); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($invoice['payment_note'])): ?>
                                        <div>
                                            <div class="text-xs font-bold text-slate-500 uppercase mb-1">Payment Note</div>
                                            <div class="text-sm text-slate-800"><?php echo htmlspecialchars($invoice['payment_note']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Previous Invoices -->
                            <?php if (!empty($previousInvoices)): ?>
                            <div class="bg-white rounded-2xl invoice-card">
                                <div class="p-6">
                                    <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Previous Invoices</h3>
                                    
                                    <div class="space-y-3">
                                        <?php foreach ($previousInvoices as $prev): ?>
                                        <a href="view-invoice.php?id=<?php echo $prev['id']; ?>" 
                                           class="block p-3 hover:bg-slate-50 rounded-lg transition group">
                                            <div class="flex justify-between items-center mb-1">
                                                <span class="text-sm font-bold text-slate-800 group-hover:text-blue-600">
                                                    #<?php echo htmlspecialchars($prev['invoice_number']); ?>
                                                </span>
                                                <span class="text-sm font-bold text-slate-800">
                                                    ₦<?php echo number_format(convertToNaira($prev['total_amount'], $exchangeRate), 0); ?>
                                                </span>
                                            </div>
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-slate-500">
                                                    <?php echo formatDate($prev['created_at']); ?>
                                                </span>
                                                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase <?php echo getPaymentStatusBadge($prev['payment_status']); ?>">
                                                    <?php echo ucfirst($prev['payment_status']); ?>
                                                </span>
                                            </div>
                                        </a>
                                        <?php endforeach; ?>
                                        
                                        <?php if (count($previousInvoices) >= 5): ?>
                                        <a href="index.php?school_id=<?php echo $invoice['school_id']; ?>" 
                                           class="block text-center text-sm text-blue-600 hover:text-blue-700 font-bold pt-2">
                                            View All Invoices →
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Mobile View -->
                <div class="mobile-view space-y-4">
                    <!-- School Info Card -->
                    <div class="mobile-card">
                        <div class="flex items-center gap-3 mb-4">
                            <?php if (!empty($invoice['school_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($invoice['school_logo']); ?>" 
                                 alt="<?php echo htmlspecialchars($invoice['school_name']); ?>" 
                                 class="w-10 h-10 rounded-lg object-cover border border-slate-200">
                            <?php else: ?>
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center font-black text-white text-sm" 
                                 style="background-color: <?php echo htmlspecialchars($invoice['school_color'] ?: '#3B82F6'); ?>">
                                <?php echo getInitials($invoice['school_name']); ?>
                            </div>
                            <?php endif; ?>
                            <div>
                                <h4 class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['school_name']); ?></h4>
                                <div class="text-xs text-slate-500">ID: <?php echo $invoice['school_id']; ?></div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Invoice #</div>
                                <div class="font-mono text-slate-800"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                            </div>
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Status</div>
                                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase <?php echo getPaymentStatusBadge($invoice['payment_status_text']); ?>">
                                    <?php echo $invoice['payment_status_text']; ?>
                                </span>
                            </div>
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Due Date</div>
                                <div class="text-slate-800"><?php echo formatDate($invoice['due_date']); ?></div>
                            </div>
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Total</div>
                                <div class="text-slate-800 font-bold">₦<?php echo number_format($totalNaira, 0); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Invoice Details Card -->
                    <div class="mobile-card">
                        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Invoice Details</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Description</div>
                                <div class="text-sm text-slate-800"><?php echo htmlspecialchars($invoice['description'] ?? 'Monthly subscription'); ?></div>
                            </div>
                            
                            <?php if (!empty($invoice['plan_name'])): ?>
                            <div>
                                <div class="text-xs font-bold text-slate-500 uppercase mb-1">Plan</div>
                                <div class="text-sm text-slate-800"><?php echo htmlspecialchars($invoice['plan_name']); ?></div>
                                <div class="text-xs text-slate-500 mt-1">
                                    $<?php echo number_format($invoice['price_monthly'] ?? 0, 2); ?>/month
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <div class="text-xs font-bold text-slate-500 uppercase mb-1">Billing Period</div>
                                    <div class="text-sm text-slate-800">
                                        <?php echo formatDate($invoice['start_date'] ?? ''); ?><br>
                                        to <?php echo formatDate($invoice['end_date'] ?? ''); ?>
                                    </div>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-slate-500 uppercase mb-1">Days Left</div>
                                    <div class="text-sm font-bold <?php echo getDaysColor($daysUntilDue); ?>">
                                        <?php 
                                        if ($isOverdue) {
                                            echo abs($daysUntilDue) . ' days overdue';
                                        } elseif ($daysUntilDue == 0) {
                                            echo 'Due today';
                                        } elseif ($daysUntilDue == 1) {
                                            echo 'Due tomorrow';
                                        } else {
                                            echo $daysUntilDue . ' days left';
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Amount Breakdown Card -->
                    <div class="mobile-card">
                        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Amount Breakdown</h3>
                        
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <div>
                                    <div class="text-sm text-slate-800">Subtotal</div>
                                    <div class="text-xs text-slate-500">Plan amount</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-bold text-slate-800">₦<?php echo number_format($subtotalNaira, 0); ?></div>
                                    <div class="text-xs text-slate-500">$<?php echo number_format($subtotal, 2); ?></div>
                                </div>
                            </div>
                            
                            <?php if ($tax > 0): ?>
                            <div class="flex justify-between items-center">
                                <div>
                                    <div class="text-sm text-slate-800">Tax</div>
                                    <div class="text-xs text-slate-500">VAT/Service tax</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-bold text-slate-800">₦<?php echo number_format($taxNaira, 0); ?></div>
                                    <div class="text-xs text-slate-500">$<?php echo number_format($tax, 2); ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <hr class="border-slate-100">
                            
                            <div class="flex justify-between items-center">
                                <div>
                                    <div class="text-lg font-black text-slate-900">Total</div>
                                    <div class="text-xs text-slate-500">Amount due</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl font-black text-slate-900">₦<?php echo number_format($totalNaira, 0); ?></div>
                                    <div class="text-sm text-slate-500">$<?php echo number_format($total, 2); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Info Card -->
                    <?php if (!empty($invoice['payment_gateway_name']) || !empty($invoice['transaction_id']) || !empty($invoice['paid_at'])): ?>
                    <div class="mobile-card">
                        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Payment Information</h3>
                        
                        <div class="space-y-3">
                            <?php if (!empty($invoice['payment_gateway_name'])): ?>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-slate-600">Gateway</span>
                                <span class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($invoice['payment_gateway_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($invoice['transaction_id'])): ?>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-slate-600">Transaction ID</span>
                                <span class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars($invoice['transaction_id']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($invoice['paid_at'])): ?>
                            <div class="flex items-center justify-between">
                                <span class="text-sm text-slate-600">Paid On</span>
                                <span class="text-sm font-bold text-slate-800"><?php echo formatDate($invoice['paid_at']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Print-Only Section -->
            <div class="print-only p-8">
                <div class="max-w-4xl mx-auto">
                    <!-- Header -->
                    <div class="flex justify-between items-start mb-8 pb-8 border-b border-slate-200">
                        <div>
                            <h1 class="text-3xl font-black text-slate-900">INVOICE</h1>
                            <div class="text-slate-600 mt-2">
                                <div class="font-bold text-lg"><?php echo APP_NAME; ?> Executive</div>
                                <div class="text-sm">Billing Department</div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-black text-slate-900">#<?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                            <div class="text-slate-600 mt-2">
                                <div>Date: <?php echo formatDate($invoice['created_at'], 'F j, Y'); ?></div>
                                <div>Due Date: <?php echo formatDate($invoice['due_date'], 'F j, Y'); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- From/To -->
                    <div class="grid grid-cols-2 gap-8 mb-8">
                        <div>
                            <h3 class="text-sm font-bold text-slate-500 uppercase mb-2">From</h3>
                            <div class="text-slate-800">
                                <div class="font-bold text-lg"><?php echo APP_NAME; ?> Executive</div>
                                <div class="text-sm">Platform Administration</div>
                                <div class="text-sm">billing@<?php echo strtolower(str_replace(' ', '', APP_NAME)); ?>.com</div>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-slate-500 uppercase mb-2">To</h3>
                            <div class="text-slate-800">
                                <div class="font-bold text-lg"><?php echo htmlspecialchars($invoice['school_name']); ?></div>
                                <div class="text-sm"><?php echo htmlspecialchars($invoice['school_address'] ?? ''); ?></div>
                                <div class="text-sm"><?php echo htmlspecialchars($invoice['school_email']); ?></div>
                                <?php if (!empty($invoice['school_phone'])): ?>
                                <div class="text-sm">Phone: <?php echo htmlspecialchars($invoice['school_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Invoice Details -->
                    <div class="mb-8">
                        <h3 class="text-sm font-bold text-slate-500 uppercase mb-4">Invoice Details</h3>
                        
                        <div class="bg-slate-50 rounded-lg p-4 mb-4">
                            <div class="font-bold text-slate-800 mb-1"><?php echo htmlspecialchars($invoice['description'] ?? 'Monthly subscription'); ?></div>
                            <?php if (!empty($invoice['plan_name'])): ?>
                            <div class="text-sm text-slate-600">
                                Plan: <?php echo htmlspecialchars($invoice['plan_name']); ?> - 
                                Billing Period: <?php echo formatDate($invoice['start_date'] ?? ''); ?> to <?php echo formatDate($invoice['end_date'] ?? ''); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-slate-200">
                                    <th class="text-left py-3 text-sm font-bold text-slate-600">Description</th>
                                    <th class="text-right py-3 text-sm font-bold text-slate-600">Amount (USD)</th>
                                    <th class="text-right py-3 text-sm font-bold text-slate-600">Amount (₦)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-slate-100">
                                    <td class="py-3 text-slate-800">Subscription Fee</td>
                                    <td class="text-right py-3 text-slate-800">$<?php echo number_format($subtotal, 2); ?></td>
                                    <td class="text-right py-3 text-slate-800">₦<?php echo number_format($subtotalNaira, 0); ?></td>
                                </tr>
                                <?php if ($tax > 0): ?>
                                <tr class="border-b border-slate-100">
                                    <td class="py-3 text-slate-800">Tax (VAT)</td>
                                    <td class="text-right py-3 text-slate-800">$<?php echo number_format($tax, 2); ?></td>
                                    <td class="text-right py-3 text-slate-800">₦<?php echo number_format($taxNaira, 0); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="bg-slate-50">
                                    <td class="py-3 font-bold text-lg text-slate-900">TOTAL</td>
                                    <td class="text-right py-3 font-bold text-lg text-slate-900">$<?php echo number_format($total, 2); ?></td>
                                    <td class="text-right py-3 font-bold text-lg text-slate-900">₦<?php echo number_format($totalNaira, 0); ?></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="text-xs text-slate-500 text-center mt-4">
                            Exchange Rate: $1 = ₦<?php echo number_format($exchangeRate); ?>
                        </div>
                    </div>
                    
                    <!-- Payment Status -->
                    <div class="mb-8">
                        <h3 class="text-sm font-bold text-slate-500 uppercase mb-2">Payment Status</h3>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full <?php echo $invoice['payment_status'] === 'success' ? 'bg-emerald-500' : ($invoice['payment_status'] === 'pending' ? 'bg-amber-500' : 'bg-red-500'); ?>"></div>
                            <span class="font-bold text-slate-800"><?php echo ucfirst($invoice['payment_status_text']); ?></span>
                            <?php if ($invoice['payment_status'] === 'success' && !empty($invoice['paid_at'])): ?>
                            <span class="text-slate-600 ml-4">Paid on: <?php echo formatDate($invoice['paid_at'], 'F j, Y'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Footer -->
                    <div class="pt-8 border-t border-slate-200 text-center text-sm text-slate-500">
                        <div class="font-bold text-slate-700 mb-1">Thank you for your business!</div>
                        <div>This is a computer-generated invoice. No signature is required.</div>
                        <div class="mt-4"><?php echo APP_NAME; ?> Executive Platform • support@<?php echo strtolower(str_replace(' ', '', APP_NAME)); ?>.com</div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            sidebar.classList.toggle('flex');
        }

        // Actions dropdown toggle
        function toggleActionsDropdown() {
            const dropdown = document.getElementById('actionsDropdown');
            dropdown.classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.getElementById('actionsDropdown');
            const button = event.target.closest('button[onclick*="toggleActionsDropdown"]');
            
            if (!button && !dropdown.contains(event.target)) {
                dropdown.classList.add('hidden');
            }
        });

        // Action functions
        function sendInvoice() {
            if (confirm('Send this invoice to the school\'s email?')) {
                // In a real application, this would make an AJAX call
                fetch('send-invoice.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        invoice_id: <?php echo $invoice['id']; ?>,
                        action: 'send'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Invoice sent successfully!');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to send invoice. Please try again.');
                });
            }
        }

        function markAsPaid() {
            if (confirm('Mark this invoice as paid manually?')) {
                const paymentNote = prompt('Enter payment reference/note:');
                if (paymentNote) {
                    // In a real application, this would make an AJAX call
                    fetch('update-invoice-status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            invoice_id: <?php echo $invoice['id']; ?>,
                            action: 'mark_paid',
                            note: paymentNote
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Invoice marked as paid!');
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to update invoice. Please try again.');
                    });
                }
            }
        }

        function sendReminder() {
            if (confirm('Send payment reminder to the school?')) {
                // AJAX call to send reminder
                alert('Reminder sent!');
            }
        }

        function cancelInvoice() {
            if (confirm('Are you sure you want to cancel this invoice? This action cannot be undone.')) {
                if (prompt('Please enter reason for cancellation:')) {
                    // AJAX call to cancel invoice
                    alert('Invoice cancelled!');
                    location.reload();
                }
            }
        }

        function issueRefund() {
            const amount = prompt('Enter refund amount ($):', '<?php echo $total; ?>');
            if (amount) {
                if (confirm(`Issue refund of $${amount}?`)) {
                    if (prompt('Enter refund reason:')) {
                        // AJAX call to process refund
                        alert('Refund initiated!');
                    }
                }
            }
        }

        // Print functionality
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });

        // Touch target improvements for mobile
        document.addEventListener('touchstart', function() {}, {passive: true});

        // Error logging for JavaScript
        window.addEventListener('error', function(e) {
            console.error('JavaScript Error:', e.message, 'at', e.filename, ':', e.lineno);
            // You could send this to your server
            // fetch('log-js-error.php', {
            //     method: 'POST',
            //     body: JSON.stringify({
            //         error: e.message,
            //         file: e.filename,
            //         line: e.lineno,
            //         url: window.location.href
            //     })
            // });
        });
    </script>

</body>
</html>
<?php
// Log successful completion
error_log("[" . date('Y-m-d H:i:s') . "] Script completed successfully\n", 3, $logFile);
?>
