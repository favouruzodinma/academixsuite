<?php
// platform/admin/billing/edit-invoice.php
require_once __DIR__ . '/../../../includes/autoload.php';

// ==================== ERROR LOGGING SETUP ====================
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Define log file path
$logFile = __DIR__ . '/../../../logs/admin-billing-edit-errors.log';

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
        E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER DEPRECATED'
    ];
    // E_STRICT was deprecated in PHP 8.4 and removed thereafter. Only map it
    // when the constant is still defined to avoid "Constant E_STRICT is
    // deprecated" notices polluting the log.
    if (defined('E_STRICT')) {
        $errorTypes[E_STRICT] = 'STRICT';
    }
    
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
    
    error_log($logMessage, 3, $logFile);
    error_log("[$errorType] $errstr in $errfile on line $errline", 0);
    
    return false;
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
error_log("[" . date('Y-m-d H:i:s') . "] Script started: edit-invoice.php\n", 3, $logFile);

// Create logs directory if it doesn't exist
$logsDir = dirname($logFile);
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0755, true);
}
// ==================== END ERROR LOGGING SETUP ====================

try {
    // Require super admin login
    $auth = new Auth();
    $auth->requireLogin('super_admin');
    
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

error_log("[" . date('Y-m-d H:i:s') . "] Processing edit for invoice ID: $invoiceId\n", 3, $logFile);

// Fetch database connection
try {
    $db = Database::getPlatformConnection();
    error_log("[" . date('Y-m-d H:i:s') . "] Database connection established\n", 3, $logFile);
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Database connection failed: " . $e->getMessage() . "\n", 3, $logFile);
    throw $e;
}

// Initialize variables
$errors = [];
$success = false;
$invoice = null;
$schools = [];
$paymentGateways = [];

// Fetch invoice details
try {
    error_log("[" . date('Y-m-d H:i:s') . "] Fetching invoice details for ID: $invoiceId\n", 3, $logFile);
    
    $sql = "
        SELECT 
            i.*,
            s.name as school_name,
            s.email as school_email,
            s.phone as school_phone,
            s.address as school_address,
            s.logo_path as school_logo,
            s.primary_color as school_color,
            sub.description as subscription_desc,
            p.name as plan_name,
            p.price_monthly,
            DATEDIFF(i.due_date, CURDATE()) as days_until_due
        FROM invoices i 
        LEFT JOIN schools s ON i.school_id = s.id
        LEFT JOIN subscriptions sub ON i.subscription_id = sub.id
        LEFT JOIN plans p ON sub.plan_id = p.id
        WHERE i.id = ?
    ";
    
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
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching invoice: " . $e->getMessage() . "\n", 3, $logFile);
    throw $e;
}

// Fetch schools for dropdown
try {
    error_log("[" . date('Y-m-d H:i:s') . "] Fetching schools list\n", 3, $logFile);
    
    $schoolsSql = "SELECT id, name, email FROM schools WHERE status = 'active' ORDER BY name";
    $schoolsStmt = $db->query($schoolsSql);
    if ($schoolsStmt) {
        $schools = $schoolsStmt->fetchAll();
        error_log("[" . date('Y-m-d H:i:s') . "] Found " . count($schools) . " active schools\n", 3, $logFile);
    }
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching schools: " . $e->getMessage() . "\n", 3, $logFile);
    $schools = [];
}

// Fetch payment gateways
try {
    error_log("[" . date('Y-m-d H:i:s') . "] Fetching payment gateways\n", 3, $logFile);
    
    $gatewaysSql = "SELECT id, name, provider, is_active FROM payment_gateways WHERE is_active = 1 ORDER BY name";
    $gatewaysStmt = $db->query($gatewaysSql);
    if ($gatewaysStmt) {
        $paymentGateways = $gatewaysStmt->fetchAll();
        error_log("[" . date('Y-m-d H:i:s') . "] Found " . count($paymentGateways) . " active payment gateways\n", 3, $logFile);
    }
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Error fetching payment gateways: " . $e->getMessage() . "\n", 3, $logFile);
    $paymentGateways = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("[" . date('Y-m-d H:i:s') . "] Processing POST request for invoice $invoiceId\n", 3, $logFile);
    
    // Collect and sanitize form data
    $schoolId = filter_input(INPUT_POST, 'school_id', FILTER_VALIDATE_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $tax = filter_input(INPUT_POST, 'tax', FILTER_VALIDATE_FLOAT) ?: 0;
    $totalAmount = $amount + $tax;
    
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'draft';
    $paymentStatus = filter_input(INPUT_POST, 'payment_status', FILTER_SANITIZE_STRING) ?: 'pending';
    $dueDate = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING) ?: '';
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING) ?: '';
    $paymentMethod = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING) ?: '';
    $transactionId = filter_input(INPUT_POST, 'transaction_id', FILTER_SANITIZE_STRING) ?: '';
    $paymentGatewayId = filter_input(INPUT_POST, 'payment_gateway_id', FILTER_VALIDATE_INT);
    
    // Log form data
    error_log("[" . date('Y-m-d H:i:s') . "] Form data received:\n" . print_r($_POST, true) . "\n", 3, $logFile);
    
    // Validation
    if (!$schoolId) {
        $errors[] = 'Please select a valid school';
        error_log("[" . date('Y-m-d H:i:s') . "] Validation error: Invalid school ID\n", 3, $logFile);
    }
    
    if ($amount === false || $amount < 0) {
        $errors[] = 'Please enter a valid positive amount';
        error_log("[" . date('Y-m-d H:i:s') . "] Validation error: Invalid amount\n", 3, $logFile);
    }
    
    if ($tax < 0) {
        $errors[] = 'Tax cannot be negative';
        error_log("[" . date('Y-m-d H:i:s') . "] Validation error: Negative tax\n", 3, $logFile);
    }
    
    if ($dueDate && !strtotime($dueDate)) {
        $errors[] = 'Invalid due date format';
        error_log("[" . date('Y-m-d H:i:s') . "] Validation error: Invalid due date: $dueDate\n", 3, $logFile);
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            error_log("[" . date('Y-m-d H:i:s') . "] Starting database transaction\n", 3, $logFile);
            
            // Update invoice
            $updateSql = "
                UPDATE invoices 
                SET 
                    school_id = ?,
                    amount = ?,
                    tax = ?,
                    total_amount = ?,
                    status = ?,
                    payment_status = ?,
                    due_date = ?,
                    description = ?,
                    notes = ?,
                    payment_method = ?,
                    transaction_id = ?,
                    payment_gateway_id = ?
                WHERE id = ?
            ";
            
            $stmt = $db->prepare($updateSql);
            if (!$stmt) {
                $error = $db->errorInfo();
                error_log("[" . date('Y-m-d H:i:s') . "] Update SQL prepare failed: " . print_r($error, true) . "\n", 3, $logFile);
                throw new Exception('Database update preparation failed');
            }
            
            $params = [
                $schoolId,
                $amount,
                $tax,
                $totalAmount,
                $status,
                $paymentStatus,
                $dueDate ?: null,
                $description,
                $notes,
                $paymentMethod,
                $transactionId,
                $paymentGatewayId,
                $invoiceId
            ];
            
            error_log("[" . date('Y-m-d H:i:s') . "] Executing update with params: " . print_r($params, true) . "\n", 3, $logFile);
            
            $result = $stmt->execute($params);
            $affectedRows = $stmt->rowCount();
            
            if ($result && $affectedRows > 0) {
                // If marked as paid, update paid_at
                if ($paymentStatus === 'success') {
                    $paidAt = filter_input(INPUT_POST, 'paid_at', FILTER_SANITIZE_STRING) ?: date('Y-m-d H:i:s');
                    
                    $paidSql = "UPDATE invoices SET paid_at = ? WHERE id = ?";
                    $paidStmt = $db->prepare($paidSql);
                    if ($paidStmt) {
                        $paidStmt->execute([$paidAt, $invoiceId]);
                        error_log("[" . date('Y-m-d H:i:s') . "] Updated paid_at to: $paidAt\n", 3, $logFile);
                        
                        // Add payment transaction record
                        try {
                            $transactionSql = "
                                INSERT INTO payment_transactions (
                                    school_id,
                                    invoice_id,
                                    payment_gateway_id,
                                    transaction_reference,
                                    amount,
                                    currency,
                                    net_amount,
                                    status,
                                    payment_method,
                                    payer_email,
                                    created_at
                                ) VALUES (?, ?, ?, ?, ?, 'NGN', ?, 'success', ?, ?, NOW())
                            ";
                            
                            $transactionStmt = $db->prepare($transactionSql);
                            if ($transactionStmt) {
                                $schoolEmail = ''; // Get from schools table
                                $getEmailStmt = $db->prepare("SELECT email FROM schools WHERE id = ?");
                                $getEmailStmt->execute([$schoolId]);
                                $schoolData = $getEmailStmt->fetch();
                                $payerEmail = $schoolData['email'] ?? 'manual@admin.com';
                                
                                $transactionReference = $transactionId ?: 'MANUAL_' . date('YmdHis') . '_' . $invoiceId;
                                
                                $transactionStmt->execute([
                                    $schoolId,
                                    $invoiceId,
                                    $paymentGatewayId,
                                    $transactionReference,
                                    $totalAmount,
                                    $totalAmount,
                                    $paymentMethod,
                                    $payerEmail
                                ]);
                                
                                error_log("[" . date('Y-m-d H:i:s') . "] Added payment transaction record\n", 3, $logFile);
                            }
                        } catch (Exception $e) {
                            error_log("[" . date('Y-m-d H:i:s') . "] Error adding payment transaction: " . $e->getMessage() . "\n", 3, $logFile);
                            // Don't fail the whole update if this fails
                        }
                    }
                } else {
                    // If not paid, clear paid_at
                    $clearPaidSql = "UPDATE invoices SET paid_at = NULL WHERE id = ?";
                    $clearStmt = $db->prepare($clearPaidSql);
                    if ($clearStmt) {
                        $clearStmt->execute([$invoiceId]);
                    }
                }
                
                $db->commit();
                $success = true;
                
                error_log("[" . date('Y-m-d H:i:s') . "] Invoice updated successfully. Redirecting...\n", 3, $logFile);
                
                // Redirect to view page
                header('Location: view-invoice.php?id=' . $invoiceId . '&success=Invoice updated successfully');
                exit;
                
            } else {
                error_log("[" . date('Y-m-d H:i:s') . "] No rows affected by update\n", 3, $logFile);
                $errors[] = 'No changes made or invoice not found';
            }
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
                error_log("[" . date('Y-m-d H:i:s') . "] Transaction rolled back due to error\n", 3, $logFile);
            }
            
            error_log("[" . date('Y-m-d H:i:s') . "] Database error: " . $e->getMessage() . "\n", 3, $logFile);
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    } else {
        error_log("[" . date('Y-m-d H:i:s') . "] Form validation failed with errors: " . implode(', ', $errors) . "\n", 3, $logFile);
    }
}

// Get current dollar to naira exchange rate
$exchangeRate = 1500; // You can make this dynamic

// Pre-fill form values from POST or database
$formSchoolId = $_POST['school_id'] ?? ($invoice['school_id'] ?? '');
$formAmount = $_POST['amount'] ?? ($invoice['amount'] ?? 0);
$formTax = $_POST['tax'] ?? ($invoice['tax'] ?? 0);
$formTotal = $formAmount + $formTax;
$formStatus = $_POST['status'] ?? ($invoice['status'] ?? 'draft');
$formPaymentStatus = $_POST['payment_status'] ?? ($invoice['payment_status'] ?? 'pending');
$formDueDate = $_POST['due_date'] ?? ($invoice['due_date'] ? date('Y-m-d', strtotime($invoice['due_date'])) : '');
$formDescription = $_POST['description'] ?? ($invoice['description'] ?? '');
$formNotes = $_POST['notes'] ?? ($invoice['notes'] ?? '');
$formPaymentMethod = $_POST['payment_method'] ?? ($invoice['payment_method'] ?? '');
$formTransactionId = $_POST['transaction_id'] ?? ($invoice['transaction_id'] ?? '');
$formPaymentGatewayId = $_POST['payment_gateway_id'] ?? ($invoice['payment_gateway_id'] ?? '');
$formPaidAt = $_POST['paid_at'] ?? ($invoice['paid_at'] ? date('Y-m-d\TH:i', strtotime($invoice['paid_at'])) : '');

// Check if invoice is overdue
$daysUntilDue = intval($invoice['days_until_due'] ?? 0);
$isOverdue = $daysUntilDue < 0;

// FIXED: Check if functions are already declared
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
        return $currency . number_format($amount, 0);
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

if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $badges = [
            'draft' => 'bg-slate-50 text-slate-600 border-slate-100',
            'sent' => 'bg-blue-50 text-blue-600 border-blue-100',
            'paid' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'overdue' => 'bg-red-50 text-red-600 border-red-100',
            'canceled' => 'bg-slate-50 text-slate-600 border-slate-100'
        ];
        return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
    }
}

if (!function_exists('getPaymentStatusBadge')) {
    function getPaymentStatusBadge($status) {
        $badges = [
            'pending' => 'bg-amber-50 text-amber-600 border-amber-100',
            'initiated' => 'bg-blue-50 text-blue-600 border-blue-100',
            'processing' => 'bg-blue-50 text-blue-600 border-blue-100',
            'success' => 'bg-emerald-50 text-emerald-600 border-emerald-100',
            'failed' => 'bg-red-50 text-red-600 border-red-100',
            'refunded' => 'bg-slate-50 text-slate-600 border-slate-100'
        ];
        return $badges[$status] ?? 'bg-slate-50 text-slate-600 border-slate-100';
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

error_log("[" . date('Y-m-d H:i:s') . "] Rendering edit form for invoice $invoiceId\n", 3, $logFile);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Edit Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?> | <?php echo APP_NAME; ?> Executive</title>
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
        
        .edit-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); 
        }

        /* Form styling */
        .form-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background: white;
            transition: all 0.2s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        
        .readonly-field {
            background-color: #f8fafc;
            border-color: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }
        
        /* Currency input */
        .currency-input {
            position: relative;
        }
        
        .currency-prefix {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-weight: 500;
        }
        
        .currency-input input {
            padding-left: 2.5rem;
        }
        
        /* Responsive */
        .desktop-view { display: block; }
        .mobile-view { display: none; }
        
        @media (max-width: 1024px) {
            .desktop-view { display: none; }
            .mobile-view { display: block; }
        }
        
        .mobile-card {
            background: white;
            border-radius: 16px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid #f1f5f9;
        }
    </style>
</head>
<body class="antialiased overflow-hidden selection:bg-blue-100">
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
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <a href="view-invoice.php?id=<?php echo $invoiceId; ?>" class="text-slate-400 hover:text-slate-600">
                            <i class="fas fa-arrow-left"></i>
                        </a>
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest">Edit Invoice</h1>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Cancel Button -->
                    <a href="view-invoice.php?id=<?php echo $invoiceId; ?>" 
                       class="bg-white hover:bg-slate-50 text-slate-600 border border-slate-200 px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 touch-target">
                        <i class="fas fa-times"></i>
                        <span class="hidden sm:inline">Cancel</span>
                    </a>
                    
                    <!-- Save Button -->
                    <button type="submit" form="editInvoiceForm" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-black transition-all flex items-center gap-2 touch-target">
                        <i class="fas fa-save"></i>
                        <span class="hidden sm:inline">Save Changes</span>
                    </button>
                </div>
            </header>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 p-4 rounded-xl mb-6">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="font-bold">Please fix the following errors:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1 text-sm">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Invoice Header -->
                <div class="bg-white rounded-2xl edit-card p-6 mb-6">
                    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-3">
                                <div class="text-2xl font-black text-slate-400">#</div>
                                <div>
                                    <h2 class="text-xl font-black text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
                                    <div class="text-sm text-slate-500">
                                        Created <?php echo formatDate($invoice['created_at']); ?>
                                        <?php if (intval($invoice['is_trial'] ?? 0)): ?>
                                        <span class="ml-2 px-2 py-0.5 bg-blue-50 text-blue-600 text-xs font-bold rounded">
                                            Trial Invoice
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-wrap gap-3">
                            <!-- Current Status -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Current Status</div>
                                <span class="px-3 py-1.5 rounded-lg text-sm font-bold uppercase <?php echo getStatusBadge($invoice['status']); ?>">
                                    <?php echo ucfirst($invoice['status']); ?>
                                </span>
                            </div>
                            
                            <!-- Current Payment Status -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Payment Status</div>
                                <span class="px-3 py-1.5 rounded-lg text-sm font-bold uppercase <?php echo getPaymentStatusBadge($invoice['payment_status']); ?>">
                                    <?php echo ucfirst($invoice['payment_status']); ?>
                                </span>
                            </div>
                            
                            <!-- Current Amount -->
                            <div class="text-center">
                                <div class="text-xs font-black text-slate-400 uppercase mb-1">Current Amount</div>
                                <div class="text-lg font-black text-slate-900">
                                    $<?php echo number_format($invoice['total_amount'] ?? 0, 2); ?>
                                    <div class="text-xs text-slate-500">
                                        ₦<?php echo number_format(convertToNaira($invoice['total_amount'] ?? 0, $exchangeRate), 0); ?>
                                    </div>
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

                <form id="editInvoiceForm" method="POST" action="" class="space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Left Column: Invoice Details -->
                        <div class="lg:col-span-2 space-y-6">
                            <!-- Basic Information -->
                            <div class="bg-white rounded-2xl edit-card p-6">
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Basic Information</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- School Selection -->
                                    <div class="md:col-span-2">
                                        <label for="school_id" class="form-label">School *</label>
                                        <select id="school_id" name="school_id" class="form-select" required>
                                            <option value="">Select School</option>
                                            <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>" 
                                                    <?php echo $formSchoolId == $school['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($school['name']); ?> 
                                                (<?php echo htmlspecialchars($school['email']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Invoice Number (Readonly) -->
                                    <div>
                                        <label class="form-label">Invoice Number</label>
                                        <input type="text" 
                                               value="<?php echo htmlspecialchars($invoice['invoice_number']); ?>" 
                                               class="form-input readonly-field" 
                                               readonly>
                                    </div>
                                    
                                    <!-- Amount -->
                                    <div>
                                        <label for="amount" class="form-label">Amount (USD) *</label>
                                        <div class="currency-input">
                                            <span class="currency-prefix">$</span>
                                            <input type="number" 
                                                   id="amount" 
                                                   name="amount" 
                                                   value="<?php echo htmlspecialchars($formAmount); ?>" 
                                                   step="0.01" 
                                                   min="0" 
                                                   class="form-input"
                                                   required>
                                        </div>
                                        <div class="text-xs text-slate-500 mt-1" id="amountNaira">
                                            ₦<?php echo number_format(convertToNaira($formAmount, $exchangeRate), 0); ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Tax -->
                                    <div>
                                        <label for="tax" class="form-label">Tax (USD)</label>
                                        <div class="currency-input">
                                            <span class="currency-prefix">$</span>
                                            <input type="number" 
                                                   id="tax" 
                                                   name="tax" 
                                                   value="<?php echo htmlspecialchars($formTax); ?>" 
                                                   step="0.01" 
                                                   min="0" 
                                                   class="form-input">
                                        </div>
                                        <div class="text-xs text-slate-500 mt-1" id="taxNaira">
                                            ₦<?php echo number_format(convertToNaira($formTax, $exchangeRate), 0); ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Total (Calculated) -->
                                    <div>
                                        <label class="form-label">Total Amount</label>
                                        <div class="p-3 bg-slate-50 rounded-xl">
                                            <div class="text-lg font-bold text-slate-900" id="totalUsd">
                                                $<?php echo number_format($formTotal, 2); ?>
                                            </div>
                                            <div class="text-sm text-slate-500" id="totalNaira">
                                                ₦<?php echo number_format(convertToNaira($formTotal, $exchangeRate), 0); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Due Date -->
                                    <div>
                                        <label for="due_date" class="form-label">Due Date</label>
                                        <input type="date" 
                                               id="due_date" 
                                               name="due_date" 
                                               value="<?php echo htmlspecialchars($formDueDate); ?>" 
                                               class="form-input">
                                    </div>
                                </div>
                                
                                <!-- Description -->
                                <div class="mt-4">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea id="description" 
                                              name="description" 
                                              class="form-textarea"
                                              rows="3"><?php echo htmlspecialchars($formDescription); ?></textarea>
                                </div>
                            </div>

                            <!-- Status & Payment -->
                            <div class="bg-white rounded-2xl edit-card p-6">
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Status & Payment</h3>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Invoice Status -->
                                    <div>
                                        <label for="status" class="form-label">Invoice Status</label>
                                        <select id="status" name="status" class="form-select">
                                            <option value="draft" <?php echo $formStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="sent" <?php echo $formStatus === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                            <option value="paid" <?php echo $formStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            <option value="overdue" <?php echo $formStatus === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                            <option value="canceled" <?php echo $formStatus === 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Payment Status -->
                                    <div>
                                        <label for="payment_status" class="form-label">Payment Status</label>
                                        <select id="payment_status" name="payment_status" class="form-select" onchange="togglePaidAtField()">
                                            <option value="pending" <?php echo $formPaymentStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="initiated" <?php echo $formPaymentStatus === 'initiated' ? 'selected' : ''; ?>>Initiated</option>
                                            <option value="processing" <?php echo $formPaymentStatus === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                            <option value="success" <?php echo $formPaymentStatus === 'success' ? 'selected' : ''; ?>>Success</option>
                                            <option value="failed" <?php echo $formPaymentStatus === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                            <option value="refunded" <?php echo $formPaymentStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Paid At (Conditional) -->
                                    <div id="paidAtField" style="display: <?php echo $formPaymentStatus === 'success' ? 'block' : 'none'; ?>;">
                                        <label for="paid_at" class="form-label">Payment Date & Time</label>
                                        <input type="datetime-local" 
                                               id="paid_at" 
                                               name="paid_at" 
                                               value="<?php echo htmlspecialchars($formPaidAt); ?>" 
                                               class="form-input">
                                    </div>
                                    
                                    <!-- Payment Method -->
                                    <div>
                                        <label for="payment_method" class="form-label">Payment Method</label>
                                        <select id="payment_method" name="payment_method" class="form-select">
                                            <option value="">Select method</option>
                                            <option value="card" <?php echo $formPaymentMethod === 'card' ? 'selected' : ''; ?>>Credit/Debit Card</option>
                                            <option value="bank_transfer" <?php echo $formPaymentMethod === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                            <option value="paystack" <?php echo $formPaymentMethod === 'paystack' ? 'selected' : ''; ?>>Paystack</option>
                                            <option value="stripe" <?php echo $formPaymentMethod === 'stripe' ? 'selected' : ''; ?>>Stripe</option>
                                            <option value="flutterwave" <?php echo $formPaymentMethod === 'flutterwave' ? 'selected' : ''; ?>>Flutterwave</option>
                                            <option value="cash" <?php echo $formPaymentMethod === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                            <option value="cheque" <?php echo $formPaymentMethod === 'cheque' ? 'selected' : ''; ?>>Cheque</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Payment Gateway -->
                                    <?php if (!empty($paymentGateways)): ?>
                                    <div>
                                        <label for="payment_gateway_id" class="form-label">Payment Gateway</label>
                                        <select id="payment_gateway_id" name="payment_gateway_id" class="form-select">
                                            <option value="">Select gateway</option>
                                            <?php foreach ($paymentGateways as $gateway): ?>
                                            <option value="<?php echo $gateway['id']; ?>" 
                                                    <?php echo $formPaymentGatewayId == $gateway['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($gateway['name']); ?> 
                                                (<?php echo htmlspecialchars($gateway['provider']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Transaction ID -->
                                    <div class="md:col-span-2">
                                        <label for="transaction_id" class="form-label">Transaction ID / Reference</label>
                                        <input type="text" 
                                               id="transaction_id" 
                                               name="transaction_id" 
                                               value="<?php echo htmlspecialchars($formTransactionId); ?>" 
                                               class="form-input"
                                               placeholder="e.g., TXN123456789">
                                    </div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="bg-white rounded-2xl edit-card p-6">
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Internal Notes</h3>
                                
                                <div>
                                    <label for="notes" class="form-label">Notes (Internal use only)</label>
                                    <textarea id="notes" 
                                              name="notes" 
                                              class="form-textarea"
                                              rows="4"
                                              placeholder="Add any internal notes about this invoice..."><?php echo htmlspecialchars($formNotes); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Summary & Actions -->
                        <div class="space-y-6">
                            <!-- Summary Card -->
                            <div class="bg-white rounded-2xl edit-card p-6">
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest mb-4">Summary</h3>
                                
                                <div class="space-y-4">
                                    <!-- Selected School Info -->
                                    <div class="flex items-center gap-3">
                                        <?php 
                                        $selectedSchool = null;
                                        if ($formSchoolId) {
                                            foreach ($schools as $school) {
                                                if ($school['id'] == $formSchoolId) {
                                                    $selectedSchool = $school;
                                                    break;
                                                }
                                            }
                                        }
                                        ?>
                                        
                                        <?php if (!empty($invoice['school_logo']) && $formSchoolId == $invoice['school_id']): ?>
                                        <img src="<?php echo htmlspecialchars($invoice['school_logo']); ?>" 
                                             alt="School Logo" 
                                             class="w-10 h-10 rounded-lg object-cover border border-slate-200">
                                        <?php else: ?>
                                        <div class="w-10 h-10 rounded-lg flex items-center justify-center font-bold text-white text-sm" 
                                             style="background-color: <?php echo htmlspecialchars($invoice['school_color'] ?: '#3B82F6'); ?>">
                                            <?php 
                                            $schoolName = $selectedSchool ? $selectedSchool['name'] : ($invoice['school_name'] ?? '');
                                            echo getInitials($schoolName);
                                            ?>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-slate-900" id="selectedSchoolName">
                                                <?php echo $selectedSchool ? htmlspecialchars($selectedSchool['name']) : htmlspecialchars($invoice['school_name'] ?? 'Not selected'); ?>
                                            </div>
                                            <div class="text-xs text-slate-500" id="selectedSchoolEmail">
                                                <?php echo $selectedSchool ? htmlspecialchars($selectedSchool['email']) : htmlspecialchars($invoice['school_email'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Plan Info -->
                                    <?php if (!empty($invoice['plan_name'])): ?>
                                    <div class="bg-slate-50 rounded-xl p-3">
                                        <div class="text-xs font-bold text-slate-600 uppercase mb-1">Current Plan</div>
                                        <div class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['plan_name']); ?></div>
                                        <div class="text-sm text-slate-600">
                                            $<?php echo number_format($invoice['price_monthly'] ?? 0, 2); ?>/month
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Due Status -->
                                    <div class="<?php echo $isOverdue ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'; ?> rounded-xl p-3">
                                        <div class="text-xs font-bold uppercase mb-1">
                                            <?php echo $isOverdue ? 'Overdue' : 'Due Status'; ?>
                                        </div>
                                        <div class="font-bold">
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
                                    
                                    <!-- Exchange Rate -->
                                    <div class="bg-blue-50 rounded-xl p-3">
                                        <div class="text-xs font-bold text-blue-600 uppercase mb-1">Exchange Rate</div>
                                        <div class="font-bold text-blue-800">$1 = ₦<?php echo number_format($exchangeRate); ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Save Actions -->
                            <div class="bg-slate-900 rounded-2xl p-6">
                                <h3 class="text-sm font-black text-slate-300 uppercase tracking-widest mb-4">Save Changes</h3>
                                
                                <div class="space-y-3">
                                    <div class="text-xs text-slate-400">
                                        All changes will be logged in the system audit trail.
                                    </div>
                                    
                                    <div class="space-y-2">
                                        <!-- Save Button -->
                                        <button type="submit" 
                                                class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 rounded-xl font-bold text-sm transition-all flex items-center justify-center gap-2 touch-target">
                                            <i class="fas fa-save"></i>
                                            Save Changes
                                        </button>
                                        
                                        <!-- Cancel -->
                                        <a href="view-invoice.php?id=<?php echo $invoiceId; ?>" 
                                           class="block w-full text-center bg-slate-800 hover:bg-slate-700 text-slate-300 py-3 rounded-xl font-bold text-sm transition-all touch-target">
                                            Cancel
                                        </a>
                                    </div>
                                    
                                    <div class="text-xs text-slate-500 pt-2">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Required fields are marked with *
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Form elements
        const amountInput = document.getElementById('amount');
        const taxInput = document.getElementById('tax');
        const paymentStatusSelect = document.getElementById('payment_status');
        const schoolSelect = document.getElementById('school_id');
        const form = document.getElementById('editInvoiceForm');
        const exchangeRate = <?php echo $exchangeRate; ?>;
        
        // School data for updating summary
        const schools = <?php echo json_encode($schools); ?>;
        
        // Update currency displays
        function updateCurrencyDisplays() {
            const amount = parseFloat(amountInput.value) || 0;
            const tax = parseFloat(taxInput.value) || 0;
            const total = amount + tax;
            
            // Update USD displays
            document.getElementById('totalUsd').textContent = '$' + total.toFixed(2);
            
            // Update Naira displays
            document.getElementById('amountNaira').textContent = '₦' + Math.round(amount * exchangeRate).toLocaleString();
            document.getElementById('taxNaira').textContent = '₦' + Math.round(tax * exchangeRate).toLocaleString();
            document.getElementById('totalNaira').textContent = '₦' + Math.round(total * exchangeRate).toLocaleString();
        }
        
        // Update school info in summary
        function updateSchoolInfo() {
            const selectedSchoolId = schoolSelect.value;
            const selectedSchool = schools.find(school => school.id == selectedSchoolId);
            
            const schoolNameElement = document.getElementById('selectedSchoolName');
            const schoolEmailElement = document.getElementById('selectedSchoolEmail');
            
            if (selectedSchool) {
                schoolNameElement.textContent = selectedSchool.name;
                schoolEmailElement.textContent = selectedSchool.email;
            } else {
                // If no school selected or school not found, show default
                const defaultSchoolName = document.querySelector('#selectedSchoolName').dataset.default || 'Not selected';
                const defaultSchoolEmail = document.querySelector('#selectedSchoolEmail').dataset.default || '';
                
                schoolNameElement.textContent = defaultSchoolName;
                schoolEmailElement.textContent = defaultSchoolEmail;
            }
        }
        
        // Toggle paid at field
        function togglePaidAtField() {
            const paidAtField = document.getElementById('paidAtField');
            if (paymentStatusSelect.value === 'success') {
                paidAtField.style.display = 'block';
                
                // Set default paid_at if empty
                const paidAtInput = document.getElementById('paid_at');
                if (!paidAtInput.value) {
                    const now = new Date();
                    const localDateTime = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
                    paidAtInput.value = localDateTime;
                }
            } else {
                paidAtField.style.display = 'none';
            }
        }
        
        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('hidden');
            sidebar.classList.toggle('flex');
        }
        
        // Initialize event listeners
        amountInput.addEventListener('input', updateCurrencyDisplays);
        taxInput.addEventListener('input', updateCurrencyDisplays);
        schoolSelect.addEventListener('change', updateSchoolInfo);
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCurrencyDisplays();
            updateSchoolInfo();
            
            // Store default school info in data attributes
            document.getElementById('selectedSchoolName').dataset.default = 
                document.getElementById('selectedSchoolName').textContent;
            document.getElementById('selectedSchoolEmail').dataset.default = 
                document.getElementById('selectedSchoolEmail').textContent;
        });
        
        // Form validation
        form.addEventListener('submit', function(e) {
            const amount = parseFloat(amountInput.value);
            const tax = parseFloat(taxInput.value) || 0;
            
            if (amount < 0) {
                e.preventDefault();
                alert('Amount cannot be negative');
                amountInput.focus();
                return false;
            }
            
            if (tax < 0) {
                e.preventDefault();
                alert('Tax cannot be negative');
                taxInput.focus();
                return false;
            }
            
            // Add loading state
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitButton.disabled = true;
            
            return true;
        });
        
        // Touch target improvements
        document.addEventListener('touchstart', function() {}, {passive: true});
    </script>
</body>
</html>
<?php
// Log successful completion
error_log("[" . date('Y-m-d H:i:s') . "] Script completed for invoice $invoiceId\n", 3, $logFile);
?>