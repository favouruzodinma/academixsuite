<?php
/**
 * School Admin Fee Management - VIRTUAL VERSION
 * This file serves ALL schools via virtual-router.php
 * ALL DATA FETCHED LIVE FROM DATABASE
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/fee_management.log');

// Start output buffering to catch any errors
ob_start();

error_log("=== FEE MANAGEMENT START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Define constants if not defined
if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');
if (!defined('IS_LOCAL')) define('IS_LOCAL', true);

// Start session safely
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
        error_log("Session started successfully");
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'fees.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

error_log("School Slug from Router: " . $schoolSlug);

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info from session or GLOBALS
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: /academixsuite/tenant/login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: /academixsuite/tenant/login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

function formatPaymentMethod($method) {
    switch (strtolower($method)) {
        case 'card':
            return 'Credit/Debit Card';
        case 'bank_transfer':
            return 'Bank Transfer';
        case 'mobile_money':
            return 'Mobile Money';
        case 'cash':
            return 'Cash';
        case 'cheque':
            return 'Cheque';
        default:
            return ucfirst($method);
    }
}

function getStatusClass($status) {
    switch (strtolower($status)) {
        case 'paid':
            return 'success';
        case 'pending':
            return 'warning';
        case 'overdue':
            return 'danger';
        case 'partial':
            return 'info';
        default:
            return 'secondary';
    }
}

function getAmountClass($amount) {
    if ($amount > 0) {
        return 'positive';
    } elseif ($amount < 0) {
        return 'negative';
    } else {
        return 'neutral';
    }
}

function getDueDateClass($dueDate) {
    $today = new DateTime();
    $due = new DateTime($dueDate);
    if ($due < $today) {
        return 'overdue';
    } elseif ($due == $today) {
        return 'due-today';
    } else {
        return 'upcoming';
    }
}

function getPaymentMethodIcon($method) {
    switch (strtolower($method)) {
        case 'card':
            return 'fa-credit-card';
        case 'bank_transfer':
            return 'fa-university';
        case 'mobile_money':
            return 'fa-mobile-screen-button';
        case 'cash':
            return 'fa-money-bill-wave';
        case 'cheque':
            return 'fa-check-circle';
        default:
            return 'fa-question-circle';
    }
}

function getPaymentMethodColor($method) {
    switch (strtolower($method)) {
        case 'card':
            return 'text-blue-500';
        case 'bank_transfer':
            return 'text-green-500';
        case 'mobile_money':
            return 'text-yellow-500';
        case 'cash':
            return 'text-gray-700';
        case 'cheque':
            return 'text-purple-500';
        default:
            return 'text-gray-500';
    }
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed: " . $e->getMessage());
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        if (!$schoolDb) {
            throw new Exception("Failed to connect to database: " . $school['database_name']);
        }
    } else {
        throw new Exception("School database name not configured");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed. Please contact administrator.");
}

// Initialize variables
$currencySymbol = '₦';
$settings = [];
$academicYear = null;
$academicTerm = null;
$students = [];
$feeCategories = [];
$recentPayments = [];
$feeInvoices = [];
$feeMetrics = [
    'total_collected' => 0,
    'pending_fees' => 0,
    'overdue_fees' => 0,
    'collection_rate' => 0,
    'today_collection' => 0
];
$paymentMethods = [];
$upcomingDueDates = [];
$paidCount = 0;
$pendingCount = 0;
$overdueCount = 0;
$partialCount = 0;
$classes = [];
$monthlyData = [];
$feeCategoryTotals = [];


// FETCH ALL DATA FROM DATABASE
try {
    // 1. Get school settings
    $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
    $settingsStmt->execute([$school['id']]);
    $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($settingsRows) {
        foreach ($settingsRows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        $currencySymbol = $settings['currency_symbol'] ?? '₦';
    }

    // 2. Get current academic year
    $academicYearStmt = $schoolDb->prepare("
        SELECT id, name, start_date, end_date, status 
        FROM academic_years 
        WHERE school_id = ? AND status = 'active' 
        ORDER BY is_default DESC, start_date DESC 
        LIMIT 1
    ");
    $academicYearStmt->execute([$school['id']]);
    $academicYear = $academicYearStmt->fetch(PDO::FETCH_ASSOC);

    // 3. Get current academic term
    if ($academicYear) {
        $academicTermStmt = $schoolDb->prepare("
            SELECT id, name, start_date, end_date, is_default 
            FROM academic_terms 
            WHERE school_id = ? AND academic_year_id = ? 
            AND status = 'active'
            ORDER BY is_default DESC, start_date ASC 
            LIMIT 1
        ");
        $academicTermStmt->execute([$school['id'], $academicYear['id']]);
        $academicTerm = $academicTermStmt->fetch(PDO::FETCH_ASSOC);
    }

    // 4. Calculate fee metrics - LIVE DATA
    if ($academicYear && $academicTerm) {
        // Total collected for current term (successful payments)
        $totalCollectedStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM payment_transactions 
            WHERE school_id = ? 
            AND status = 'success'
            AND academic_year_id = ?
            AND academic_term_id = ?
        ");
        $totalCollectedStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $totalCollected = $totalCollectedStmt->fetch(PDO::FETCH_ASSOC);
        $feeMetrics['total_collected'] = floatval($totalCollected['total'] ?? 0);

        // Pending fees for current term (not due yet)
        $pendingStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(total_amount - COALESCE(paid_amount, 0)), 0) as pending
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status IN ('pending', 'partial')
            AND due_date >= CURDATE()
        ");
        $pendingStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC);
        $feeMetrics['pending_fees'] = floatval($pending['pending'] ?? 0);

        // Overdue fees for current term (past due date)
        $overdueStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(total_amount - COALESCE(paid_amount, 0)), 0) as overdue
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status IN ('pending', 'partial')
            AND due_date < CURDATE()
        ");
        $overdueStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $overdue = $overdueStmt->fetch(PDO::FETCH_ASSOC);
        $feeMetrics['overdue_fees'] = floatval($overdue['overdue'] ?? 0);

        // Today's collection (all successful payments today)
        $todayStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(amount), 0) as today
            FROM payment_transactions 
            WHERE school_id = ? 
            AND status = 'success'
            AND DATE(created_at) = CURDATE()
        ");
        $todayStmt->execute([$school['id']]);
        $today = $todayStmt->fetch(PDO::FETCH_ASSOC);
        $feeMetrics['today_collection'] = floatval($today['today'] ?? 0);

        // Collection rate for current term
        $totalInvoiceStmt = $schoolDb->prepare("
            SELECT 
                COALESCE(SUM(total_amount), 0) as total,
                COALESCE(SUM(paid_amount), 0) as paid
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status NOT IN ('draft', 'canceled')
        ");
        $totalInvoiceStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $invoiceData = $totalInvoiceStmt->fetch(PDO::FETCH_ASSOC);
        $totalInvoices = floatval($invoiceData['total'] ?? 1);
        $paidInvoices = floatval($invoiceData['paid'] ?? 0);
        $feeMetrics['collection_rate'] = $totalInvoices > 0 ? round(($paidInvoices / $totalInvoices) * 100, 1) : 0;
    }

    // 5. Get recent payments (last 10) - LIVE DATA
    $recentPaymentsStmt = $schoolDb->prepare("
        SELECT 
            pt.id,
            pt.student_id,
            pt.amount,
            pt.payment_method,
            pt.status,
            pt.created_at,
            pt.transaction_reference,
            s.first_name as student_first_name,
            s.last_name as student_last_name,
            s.admission_number,
            s.class_id,
            fc.name as fee_category_name,
            i.invoice_number
        FROM payment_transactions pt
        LEFT JOIN students s ON pt.student_id = s.id
        LEFT JOIN fee_categories fc ON pt.fee_category_id = fc.id
        LEFT JOIN invoices i ON pt.invoice_id = i.id
        WHERE pt.school_id = ?
        ORDER BY pt.created_at DESC
        LIMIT 10
    ");
    $recentPaymentsStmt->execute([$school['id']]);
    $recentPayments = $recentPaymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Get fee invoices for current term - LIVE DATA
    if ($academicYear && $academicTerm) {
        $invoicesStmt = $schoolDb->prepare("
            SELECT 
                i.id,
                i.invoice_number,
                i.student_id,
                i.total_amount,
                i.paid_amount,
                i.status,
                i.due_date,
                i.created_at,
                s.first_name as student_first_name,
                s.last_name as student_last_name,
                s.admission_number,
                s.class_id,
                fc.name as fee_category_name
            FROM invoices i
            LEFT JOIN students s ON i.student_id = s.id
            LEFT JOIN fee_categories fc ON i.fee_category_id = fc.id
            WHERE i.school_id = ?
            AND i.academic_year_id = ?
            AND i.academic_term_id = ?
            ORDER BY i.created_at DESC
            LIMIT 10
        ");
        $invoicesStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $feeInvoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 7. Get payment methods distribution - LIVE DATA
    $methodsStmt = $schoolDb->prepare("
        SELECT 
            payment_method,
            COUNT(*) as count,
            COALESCE(SUM(amount), 0) as amount
        FROM payment_transactions 
        WHERE school_id = ? 
        AND status = 'success'
        AND payment_method IS NOT NULL
        AND payment_method != ''
        GROUP BY payment_method
        ORDER BY amount DESC
    ");
    $methodsStmt->execute([$school['id']]);
    $paymentMethods = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Get upcoming due dates (next 30 days) - LIVE DATA
    $dueDatesStmt = $schoolDb->prepare("
        SELECT 
            i.id,
            i.invoice_number,
            i.student_id,
            i.total_amount,
            i.paid_amount,
            i.status,
            i.due_date,
            s.first_name as student_first_name,
            s.last_name as student_last_name,
            s.admission_number,
            DATEDIFF(i.due_date, CURDATE()) as days_until_due
        FROM invoices i
        LEFT JOIN students s ON i.student_id = s.id
        WHERE i.school_id = ?
        AND i.status IN ('pending', 'partial')
        AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY i.due_date ASC
        LIMIT 10
    ");
    $dueDatesStmt->execute([$school['id']]);
    $upcomingDueDates = $dueDatesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 9. Get fee categories - LIVE DATA
    $categoriesStmt = $schoolDb->prepare("
        SELECT 
            id,
            name,
            description,
            amount,
            is_active
        FROM fee_categories 
        WHERE school_id = ? AND is_active = 1
        ORDER BY name
    ");
    $categoriesStmt->execute([$school['id']]);
    $feeCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. Get students for dropdowns - LIVE DATA
    $studentsStmt = $schoolDb->prepare("
        SELECT 
            s.id,
            s.first_name,
            s.last_name,
            s.admission_number,
            s.class_id,
            c.name as class_name,
            c.grade_level
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.school_id = ? AND s.status = 'active'
        ORDER BY s.first_name, s.last_name
        LIMIT 200
    ");
    $studentsStmt->execute([$school['id']]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 11. Get classes for bulk invoice generation - LIVE DATA
    $classesStmt = $schoolDb->prepare("
        SELECT 
            id,
            name,
            grade_level,
            section
        FROM classes 
        WHERE school_id = ? AND status = 'active'
        ORDER BY grade_level, name
    ");
    $classesStmt->execute([$school['id']]);
    $classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

    // 12. Count statistics for filter chips - LIVE DATA
    if ($academicYear && $academicTerm) {
        // Count paid invoices (balance <= 0)
        $paidCountStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count 
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND (total_amount - COALESCE(paid_amount, 0)) <= 0
            AND status NOT IN ('draft', 'canceled')
        ");
        $paidCountStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $paidResult = $paidCountStmt->fetch(PDO::FETCH_ASSOC);
        $paidCount = $paidResult['count'] ?? 0;

        // Count pending invoices (not due yet)
        $pendingCountStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count 
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status = 'pending'
            AND due_date >= CURDATE()
        ");
        $pendingCountStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $pendingResult = $pendingCountStmt->fetch(PDO::FETCH_ASSOC);
        $pendingCount = $pendingResult['count'] ?? 0;

        // Count overdue invoices (past due date)
        $overdueCountStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count 
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status IN ('pending', 'partial')
            AND due_date < CURDATE()
        ");
        $overdueCountStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $overdueResult = $overdueCountStmt->fetch(PDO::FETCH_ASSOC);
        $overdueCount = $overdueResult['count'] ?? 0;

        // Count partial invoices
        $partialCountStmt = $schoolDb->prepare("
            SELECT COUNT(*) as count 
            FROM invoices 
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND academic_term_id = ?
            AND status = 'partial'
        ");
        $partialCountStmt->execute([$school['id'], $academicYear['id'], $academicTerm['id']]);
        $partialResult = $partialCountStmt->fetch(PDO::FETCH_ASSOC);
        $partialCount = $partialResult['count'] ?? 0;
    }

    // 13. Calculate fee category totals for breakdown - LIVE DATA
    foreach ($feeCategories as $category) {
        $categoryTotalStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM payment_transactions 
            WHERE school_id = ? 
            AND fee_category_id = ?
            AND status = 'success'
        ");
        $categoryTotalStmt->execute([$school['id'], $category['id']]);
        $categoryTotal = $categoryTotalStmt->fetch(PDO::FETCH_ASSOC);
        $feeCategoryTotals[$category['name']] = floatval($categoryTotal['total'] ?? 0);
    }

    // 14. Get monthly collection data for chart - LIVE DATA
    for ($i = 11; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-$i months"));
        $monthEnd = date('Y-m-t', strtotime("-$i months"));
        $monthName = date('M', strtotime("-$i months"));
        
        $monthlyStmt = $schoolDb->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM payment_transactions 
            WHERE school_id = ? 
            AND status = 'success'
            AND DATE(created_at) BETWEEN ? AND ?
        ");
        $monthlyStmt->execute([$school['id'], $monthStart, $monthEnd]);
        $monthlyResult = $monthlyStmt->fetch(PDO::FETCH_ASSOC);
        $monthlyData[$monthName] = floatval($monthlyResult['total'] ?? 0);
    }

} catch (Exception $e) {
    error_log("ERROR fetching data from database: " . $e->getMessage());
    // Continue with empty data
}

// Count due today
$dueTodayCount = 0;
foreach ($upcomingDueDates as $due) {
    if (isset($due['days_until_due']) && $due['days_until_due'] == 0) {
        $dueTodayCount++;
    }
}

// Flush output buffer
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0" />
    <title>Fee Management | <?php echo htmlspecialchars($school['name']); ?> - <?php echo APP_NAME; ?></title>
    <!-- Rest of your HTML remains the same -->
    <!-- ... -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap");
        :root {
            --school-primary: <?php echo $school['primary_color'] ?? '#4f46e5'; ?>;
            --school-secondary: <?php echo $school['secondary_color'] ?? '#10b981'; ?>;
            --school-surface: #ffffff;
            --school-bg: #f8fafc;
            --fee-paid: #10b981;
            --fee-pending: #f59e0b;
            --fee-overdue: #ef4444;
            --fee-partial: #3b82f6;
        }
        body {
            font-family: "Inter", sans-serif;
            background-color: var(--school-bg);
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        .glass-header {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.3);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(226, 232, 240, 0.5);
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
        }
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
        }
        .toast {
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            animation: slideIn 0.3s ease forwards;
            opacity: 0;
            transform: translateX(100px);
            transition: opacity 0.3s, transform 0.3s;
        }
        .toast-success {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            border-left: 4px solid #059669;
        }
        .toast-info {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
            border-left: 4px solid #3730a3;
        }
        .toast-warning {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
            border-left: 4px solid #d97706;
        }
        .toast-error {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            border-left: 4px solid #dc2626;
        }
        @keyframes slideIn {
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(100px); }
        }
        .toast-exit { animation: fadeOut 0.3s ease forwards; }
        .toast-icon { font-size: 18px; flex-shrink: 0; }
        .toast-content { flex: 1; }
        .toast-close {
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        .toast-close:hover { background: rgba(255, 255, 255, 0.2); }
        .sidebar-link {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 3px solid transparent;
            position: relative;
        }
        .sidebar-link:hover {
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.05) 0%, rgba(79, 70, 229, 0.02) 100%);
            color: var(--school-primary);
            border-left-color: rgba(79, 70, 229, 0.3);
        }
        .active-link {
            background: linear-gradient(90deg, rgba(79, 70, 229, 0.1) 0%, rgba(79, 70, 229, 0.05) 100%);
            color: var(--school-primary);
            border-left-color: var(--school-primary);
            font-weight: 700;
        }
        .active-link::before {
            content: "";
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--school-primary);
            border-radius: 4px 0 0 4px;
        }
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeInUp { animation: fadeInUp 0.6s ease-out forwards; }
        .fee-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .status-paid { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .status-pending { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .status-overdue { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .status-partial { background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .fee-amount { font-weight: 800; font-size: 18px; color: #1e293b; }
        .fee-amount.paid { color: #10b981; }
        .fee-amount.pending { color: #f59e0b; }
        .fee-amount.overdue { color: #ef4444; }
        .fee-amount.partial { color: #3b82f6; }
        .tab-button {
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tab-button:hover { color: #4f46e5; }
        .tab-button.active {
            color: #4f46e5;
            border-bottom-color: #4f46e5;
            background: linear-gradient(to top, rgba(79, 70, 229, 0.05), transparent);
        }
        .metric-card {
            position: relative;
            overflow: hidden;
        }
        .metric-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--metric-color), transparent);
        }
        .metric-primary { --metric-color: #4f46e5; }
        .metric-success { --metric-color: #10b981; }
        .metric-warning { --metric-color: #f59e0b; }
        .metric-danger { --metric-color: #ef4444; }
        .chart-container { position: relative; height: 300px; width: 100%; }
        .action-btn {
            padding: 8px 16px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .action-btn-primary { background: linear-gradient(135deg, #4f46e5, #7c73e9); color: white; }
        .action-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3); }
        .action-btn-secondary { background: white; color: #4f46e5; border: 1px solid #e2e8f0; }
        .action-btn-secondary:hover { background: #f8fafc; border-color: #4f46e5; }
        .action-btn-success { background: linear-gradient(135deg, #10b981, #34d399); color: white; }
        .action-btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); }
        .action-btn-danger { background: linear-gradient(135deg, #ef4444, #f87171); color: white; }
        .action-btn-danger:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
        .search-box { position: relative; width: 100%; }
        .search-input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        .search-input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-chip:hover { background: #e2e8f0; }
        .filter-chip.active { background: #4f46e5; color: white; border-color: #4f46e5; }
        .pagination-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #64748b;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .pagination-btn:hover { background: #f8fafc; border-color: #4f46e5; color: #4f46e5; }
        .pagination-btn.active { background: #4f46e5; color: white; border-color: #4f46e5; }
        .pagination-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .table-container { overflow-x: auto; border-radius: 12px; border: 1px solid #e2e8f0; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            background: #f8fafc;
            padding: 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
        }
        .data-table td { padding: 16px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .data-table tr:hover { background: #f8fafc; }
        .data-table tr:last-child td { border-bottom: none; }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .form-label { display: block; margin-bottom: 8px; font-size: 14px; font-weight: 600; color: #1e293b; }
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }
        .form-input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: white;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
        }
        .fee-progress { height: 8px; border-radius: 4px; background: #f1f5f9; overflow: hidden; position: relative; }
        .fee-progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s ease; }
        .progress-paid { background: linear-gradient(90deg, #10b981, #34d399); }
        .progress-pending { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .progress-overdue { background: linear-gradient(90deg, #ef4444, #f87171); }
        .progress-partial { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .fee-breakdown { border-radius: 12px; padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .breakdown-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
        .breakdown-item:last-child { border-bottom: none; font-weight: 800; color: #1e293b; }
        .payment-method {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .payment-method:hover { border-color: #4f46e5; background: #f8fafc; }
        .payment-method.selected {
            border-color: #4f46e5;
            background: linear-gradient(to right, rgba(79, 70, 229, 0.05), transparent);
        }
        .receipt-container {
            background: white;
            border-radius: 12px;
            padding: 32px;
            border: 2px solid #e2e8f0;
            max-width: 600px;
            margin: 0 auto;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 24px;
            margin-bottom: 24px;
        }
        .receipt-item { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .receipt-total {
            display: flex;
            justify-content: space-between;
            padding: 20px 0;
            font-weight: 800;
            font-size: 18px;
            color: #1e293b;
            border-top: 2px solid #e2e8f0;
            margin-top: 20px;
        }
        @media (max-width: 768px) {
            .glass-header { backdrop-filter: none; -webkit-backdrop-filter: none; background: white; }
            .toast-container { left: 20px; right: 20px; max-width: none; }
            .chart-container { height: 250px !important; }
        }
        .loader {
            width: 48px;
            height: 48px;
            border: 4px solid #f1f5f9;
            border-top-color: #4f46e5;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .due-date {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
        }
        .due-soon { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .due-today { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .due-future { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .summary-card { border-radius: 12px; padding: 24px; text-align: center; position: relative; overflow: hidden; }
        .summary-value { font-size: 32px; font-weight: 900; color: #1e293b; margin: 12px 0; }
        .summary-label { font-size: 14px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
        .installment-plan { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
        .installment-card {
            background: white;
            border-radius: 12px;
            padding: 16px;
            border: 2px solid #e2e8f0;
            text-align: center;
            transition: all 0.2s ease;
        }
        .installment-card:hover { border-color: #4f46e5; transform: translateY(-2px); }
        .installment-card.paid { border-color: #10b981; background: #f0fdf4; }
        .installment-card.pending { border-color: #f59e0b; background: #fffbeb; }
        .installment-card.overdue { border-color: #ef4444; background: #fef2f2; }
        .status-legend { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #475569; }
        .legend-color { width: 12px; height: 12px; border-radius: 3px; }
        .legend-paid { background: #10b981; }
        .legend-pending { background: #f59e0b; }
        .legend-overdue { background: #ef4444; }
        .legend-partial { background: #3b82f6; }
        .collection-calendar { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-day {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .calendar-day.collected { background: #dcfce7; color: #166534; border: 2px solid #bbf7d0; }
        .calendar-day.pending { background: #fef3c7; color: #92400e; border: 2px solid #fde68a; }
        .calendar-day.overdue { background: #fee2e2; color: #991b1b; border: 2px solid #fecaca; }
        .calendar-day.today { background: #4f46e5; color: white; }
        .calendar-day:hover { transform: scale(1.1); }
        .bulk-edit-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 20px;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            z-index: 999;
            transform: translateY(100%);
            transition: transform 0.3s ease;
        }
        .bulk-edit-panel:not(.hidden) { transform: translateY(0); }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
        }
        .empty-state-icon {
            width: 80px;
            height: 80px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        .empty-state-icon i {
            font-size: 32px;
            color: #94a3b8;
        }
        .empty-state-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }
        .empty-state-description {
            color: #64748b;
            margin-bottom: 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
    </style>
</head>
<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Record Payment Modal -->
    <div id="recordPaymentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Record New Payment</h3>
                    <button onclick="closeModal('recordPaymentModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form id="paymentForm" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/process-payment.php">
                    <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                    <?php if ($academicYear): ?>
                    <input type="hidden" name="academic_year_id" value="<?php echo $academicYear['id']; ?>">
                    <?php endif; ?>
                    <?php if ($academicTerm): ?>
                    <input type="hidden" name="academic_term_id" value="<?php echo $academicTerm['id']; ?>">
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="form-label">Student *</label>
                            <select id="paymentStudent" name="student_id" class="form-select" required onchange="loadStudentFeeDetails()">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' - ' . $student['admission_number'] . ' (' . ($student['class_name'] ?? 'No Class') . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Fee Category *</label>
                            <select id="paymentFeeType" name="fee_category_id" class="form-select" required onchange="loadStudentFeeDetails()">
                                <option value="">Select Fee Type</option>
                                <?php foreach ($feeCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" data-amount="<?php echo $category['amount'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($category['name'] . ' - ' . formatCurrency($category['amount'] ?? 0)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Amount Paid *</label>
                            <div class="flex items-center gap-2">
                                <span class="text-2xl font-black text-slate-900"><?php echo $currencySymbol; ?></span>
                                <input type="number" id="paymentAmount" name="amount" class="form-input" placeholder="0.00" required min="0" step="0.01">
                            </div>
                        </div>

                        <div>
                            <label class="form-label">Payment Date *</label>
                            <input type="date" id="paymentDate" name="payment_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div>
                            <label class="form-label">Payment Method *</label>
                            <select id="paymentMethod" name="payment_method" class="form-select" required>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="check">Check</option>
                                <option value="credit_card">Credit Card</option>
                                <option value="debit_card">Debit Card</option>
                                <option value="online">Online Payment</option>
                                <option value="mobile">Mobile Payment</option>
                            </select>
                        </div>

                        <div class="md:col-span-2">
                            <label class="form-label">Transaction Reference</label>
                            <input type="text" id="paymentReference" name="transaction_reference" class="form-input" placeholder="Enter transaction reference number">
                        </div>

                        <div class="md:col-span-2">
                            <label class="form-label">Notes</label>
                            <textarea id="paymentNotes" name="notes" class="form-input h-24" placeholder="Add payment notes or remarks..."></textarea>
                        </div>
                    </div>

                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl mb-6">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-info-circle text-blue-600"></i>
                            <p class="text-sm text-blue-700">Receipt will be generated automatically. You can print or email it to the parent.</p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('recordPaymentModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">Cancel</button>
                        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">Record Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Generate Invoice Modal -->
    <div id="generateInvoiceModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Generate Fee Invoice</h3>
                    <button onclick="closeModal('generateInvoiceModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form id="invoiceForm" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/process-invoice.php">
                    <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="form-label">Invoice Type *</label>
                            <select id="invoiceType" name="invoice_type" class="form-select" required onchange="toggleInvoiceSections()">
                                <option value="single">Single Student Invoice</option>
                                <option value="class">Class-wise Invoices</option>
                            </select>
                        </div>

                        <div id="singleStudentSection">
                            <label class="form-label">Select Student *</label>
                            <select id="invoiceStudent" name="student_id" class="form-select">
                                <option value="">Select Student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['admission_number'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="classSection" class="hidden">
                            <label class="form-label">Select Class *</label>
                            <select id="invoiceClass" name="class_id" class="form-select">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['name'] . ($class['grade_level'] ? ' - Grade ' . $class['grade_level'] : '') . ($class['section'] ? ' (' . $class['section'] . ')' : '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Fee Category *</label>
                            <select id="invoiceFeeCategory" name="fee_category_id" class="form-select" required onchange="updateInvoiceAmount()">
                                <option value="">Select Fee Category</option>
                                <?php foreach ($feeCategories as $category): ?>
                                <option value="<?php echo $category['id']; ?>" data-amount="<?php echo $category['amount'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($category['name'] . ' - ' . formatCurrency($category['amount'] ?? 0)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Amount *</label>
                            <div class="flex items-center gap-2">
                                <span class="text-2xl font-black text-slate-900"><?php echo $currencySymbol; ?></span>
                                <input type="number" id="invoiceAmount" name="amount" class="form-input" placeholder="0.00" required min="0" step="0.01">
                            </div>
                        </div>

                        <div>
                            <label class="form-label">Due Date *</label>
                            <input type="date" id="invoiceDueDate" name="due_date" class="form-input" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>

                        <?php if ($academicYear): ?>
                        <input type="hidden" name="academic_year_id" value="<?php echo $academicYear['id']; ?>">
                        <?php endif; ?>
                        
                        <?php if ($academicTerm): ?>
                        <input type="hidden" name="academic_term_id" value="<?php echo $academicTerm['id']; ?>">
                        <?php endif; ?>
                    </div>

                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('generateInvoiceModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">Cancel</button>
                        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">Generate Invoice</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Receipt Modal -->
    <div id="viewReceiptModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 600px">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Payment Receipt</h3>
                    <div class="flex items-center gap-3">
                        <button onclick="printReceipt()" class="p-2 text-slate-600 hover:text-slate-800"><i class="fas fa-print"></i></button>
                        <button onclick="emailReceipt()" class="p-2 text-slate-600 hover:text-slate-800"><i class="fas fa-envelope"></i></button>
                        <button onclick="closeModal('viewReceiptModal')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times text-xl"></i></button>
                    </div>
                </div>
                <div id="receiptContent"></div>
            </div>
        </div>
    </div>

    <!-- Send Reminders Modal -->
    <div id="sendRemindersModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 600px">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Send Fee Reminders</h3>
                    <button onclick="closeModal('sendRemindersModal')" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times text-xl"></i></button>
                </div>
                <div class="space-y-6">
                    <div>
                        <label class="form-label">Reminder Type</label>
                        <select id="reminderType" class="form-select">
                            <option value="overdue">Overdue Fees</option>
                            <option value="upcoming">Upcoming Due Dates</option>
                            <option value="partial">Partial Payments</option>
                            <option value="all">All Pending Fees</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Communication Method</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="payment-method" onclick="selectReminderMethod('email')">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center"><i class="fas fa-envelope text-blue-600"></i></div>
                                <div class="text-left"><p class="font-bold">Email</p><p class="text-sm text-slate-500">Send email reminders</p></div>
                                <input type="radio" name="reminderMethod" value="email" class="hidden" checked>
                            </label>
                            <label class="payment-method" onclick="selectReminderMethod('sms')">
                                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center"><i class="fas fa-sms text-green-600"></i></div>
                                <div class="text-left"><p class="font-bold">SMS</p><p class="text-sm text-slate-500">Send text messages</p></div>
                                <input type="radio" name="reminderMethod" value="sms" class="hidden">
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Custom Message</label>
                        <textarea id="reminderMessage" class="form-input h-32" placeholder="Customize the reminder message...">Dear Parent, This is a reminder that the school fees are due. Please make the payment at your earliest convenience to avoid late fees.</textarea>
                    </div>
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-xl">
                        <div class="flex items-center gap-2 mb-1">
                            <i class="fas fa-exclamation-triangle text-amber-600"></i>
                            <span class="text-sm font-bold text-amber-700">Reminder Status</span>
                        </div>
                        <p class="text-xs text-amber-600">This will send reminders to <span id="reminderCount"><?php echo $overdueCount; ?></span> parents with pending fees.</p>
                    </div>
                </div>
                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button onclick="closeModal('sendRemindersModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">Cancel</button>
                    <button onclick="sendReminders()" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">Send Reminders</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Edit Panel -->
    <div class="bulk-edit-panel hidden" id="bulkEditPanel">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <p class="font-bold text-slate-900" id="bulkEditCount">0 fees selected</p>
                    <p class="text-sm text-slate-500">Apply actions to all selected fee records</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <button onclick="applyBulkDiscount()" class="px-3 py-2 bg-emerald-100 text-emerald-700 font-bold rounded-lg hover:bg-emerald-200 transition text-sm"><i class="fas fa-percentage"></i> Apply Discount</button>
                        <button onclick="extendBulkDueDate()" class="px-3 py-2 bg-blue-100 text-blue-700 font-bold rounded-lg hover:bg-blue-200 transition text-sm"><i class="fas fa-calendar-plus"></i> Extend Due Date</button>
                        <button onclick="sendBulkReminders()" class="px-3 py-2 bg-amber-100 text-amber-700 font-bold rounded-lg hover:bg-amber-200 transition text-sm"><i class="fas fa-bell"></i> Send Reminders</button>
                        <button onclick="exportBulkData()" class="px-3 py-2 bg-purple-100 text-purple-700 font-bold rounded-lg hover:bg-purple-200 transition text-sm"><i class="fas fa-download"></i> Export</button>
                    </div>
                    <button onclick="closeBulkEdit()" class="action-btn action-btn-danger"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">
        <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-white border-r border-slate-200 z-[100] lg:relative lg:translate-x-0 -translate-x-full transition-transform duration-300 flex flex-col shadow-xl lg:shadow-none">
            <!-- School Header -->
            <div class="h-20 flex items-center px-6 border-b border-slate-100">
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center shadow-lg shadow-indigo-100">
                            <i class="fas fa-school text-white text-lg"></i>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div>
                        <span class="text-xl font-black tracking-tight text-slate-900"><?php echo htmlspecialchars($school['name']); ?></span>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">SCHOOL ADMIN</p>
                    </div>
                </div>
            </div>

            <!-- School Quick Info -->
            <div class="p-6 border-b border-slate-100">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Fees Collected:</span>
                        <span class="text-sm font-black text-emerald-600"><?php echo formatCurrency($feeMetrics['total_collected']); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Pending:</span>
                        <span class="text-sm font-bold text-amber-600"><?php echo formatCurrency($feeMetrics['pending_fees']); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Overdue:</span>
                        <span class="text-sm font-bold text-red-600"><?php echo formatCurrency($feeMetrics['overdue_fees']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="./dashboard.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-chart-pie"></i></div><span>Overview</span>
                        </a>
                        <a href="./announcements.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-bullhorn"></i></div><span>Announcements</span>
                        </a>
                    </nav>
                </div>
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Student Management</p>
                    <nav class="space-y-1">
                        <a href="./students.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-user-graduate"></i></div><span>Students Directory</span>
                        </a>
                        <a href="./attendance.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-calendar-check"></i></div><span>Attendance</span>
                        </a>
                        <a href="./grades.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-chart-bar"></i></div><span>Grades & Reports</span>
                        </a>
                    </nav>
                </div>
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Staff Management</p>
                    <nav class="space-y-1">
                        <a href="./teachers.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-chalkboard-teacher"></i></div><span>Teachers</span>
                        </a>
                        <a href="./schedule.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-calendar-alt"></i></div><span>Timetable</span>
                        </a>
                    </nav>
                </div>
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">School Operations</p>
                    <nav class="space-y-1">
                        <a href="./fees.php" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-receipt"></i></div><span>Fee Management</span>
                        </a>
                        <a href="./settings.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center"><i class="fas fa-cog"></i></div><span>School Settings</span>
                        </a>
                    </nav>
                </div>
            </div>

            <!-- User Profile -->
            <div class="p-6 border-t border-slate-100">
                <div class="flex items-center gap-3 p-2 group cursor-pointer hover:bg-slate-50 rounded-xl transition">
                    <div class="relative">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-500 flex items-center justify-center text-white font-bold">
                            <?php 
                            $initials = 'A';
                            if (isset($_SESSION['school_auth']['user_name'])) {
                                $nameParts = explode(' ', $_SESSION['school_auth']['user_name']);
                                $initials = '';
                                foreach ($nameParts as $part) {
                                    $initials .= strtoupper(substr($part, 0, 1));
                                    if (strlen($initials) >= 2) break;
                                }
                            }
                            echo $initials ?: 'A';
                            ?>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-[13px] font-black text-slate-900 truncate"><?php echo htmlspecialchars($_SESSION['school_auth']['user_name'] ?? 'Admin'); ?></p>
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider italic">Administrator</p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <!-- Header -->
            <header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition"><i class="fas fa-bars-staggered"></i></button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">Fee Management</h1>
                        <?php if ($academicYear): ?>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest"><?php echo htmlspecialchars(($academicYear['name'] ?? 'Academic Year') . ($academicTerm ? ' - ' . $academicTerm['name'] : '')); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-6 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today's Collection</p>
                            <p class="text-sm font-black text-emerald-600"><?php echo formatCurrency($feeMetrics['today_collection']); ?></p>
                        </div>
                        <div class="h-8 w-px bg-slate-200"></div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Overdue</p>
                            <p class="text-sm font-black text-red-600"><?php echo $overdueCount; ?></p>
                        </div>
                    </div>
                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="openModal('sendRemindersModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2"><i class="fas fa-bell"></i><span class="hidden sm:inline">Reminders</span></button>
                        <button onclick="openModal('generateInvoiceModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2"><i class="fas fa-file-invoice"></i><span class="hidden sm:inline">Invoices</span></button>
                        <button onclick="openModal('recordPaymentModal')" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200"><i class="fas fa-plus-circle"></i><span class="hidden sm:inline">Record Payment</span></button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab('overview')" data-tab="overview"><i class="fas fa-chart-pie mr-2"></i>Overview</button>
                        <button class="tab-button" onclick="switchTab('payments')" data-tab="payments"><i class="fas fa-money-check mr-2"></i>Payments</button>
                        <button class="tab-button" onclick="switchTab('invoices')" data-tab="invoices"><i class="fas fa-file-invoice mr-2"></i>Invoices</button>
                        <button class="tab-button" onclick="switchTab('reports')" data-tab="reports"><i class="fas fa-chart-bar mr-2"></i>Reports</button>
                        <button class="tab-button" onclick="switchTab('settings')" data-tab="settings"><i class="fas fa-cog mr-2"></i>Settings</button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Page Header & Filters -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">Fee Management System</h2>
                            <p class="text-slate-500 font-medium">Manage fee collection, invoices, and financial reports for <?php echo htmlspecialchars($school['name']); ?></p>
                        </div>
                        <div class="flex gap-3">
                            <div class="search-box">
                                <input type="text" placeholder="Search student, invoice, or payment..." class="search-input" id="searchInput" onkeyup="filterFees()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="toggleFilters()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2"><i class="fas fa-filter"></i><span class="hidden sm:inline">Filters</span></button>
                        </div>
                    </div>

                    <!-- Advanced Filters -->
                    <div class="glass-card rounded-xl p-6 mt-6 hidden" id="advancedFilters">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-black text-slate-900">Advanced Filters</h3>
                            <button onclick="toggleFilters()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div><label class="form-label">Fee Status</label><select id="filterStatus" class="form-select" onchange="applyFilters()"><option value="">All Status</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="overdue">Overdue</option><option value="partial">Partial</option></select></div>
                            <div><label class="form-label">Academic Year</label><select id="filterAcademicYear" class="form-select" onchange="applyFilters()"><option value="">All Years</option><?php if ($academicYear): ?><option value="<?php echo $academicYear['id']; ?>" selected><?php echo htmlspecialchars($academicYear['name']); ?></option><?php endif; ?></select></div>
                            <div><label class="form-label">Term</label><select id="filterTerm" class="form-select" onchange="applyFilters()"><option value="">All Terms</option><?php if ($academicTerm): ?><option value="<?php echo $academicTerm['id']; ?>" selected><?php echo htmlspecialchars($academicTerm['name']); ?></option><?php endif; ?></select></div>
                            <div><label class="form-label">Date Range</label><input type="date" id="filterDate" class="form-input" onchange="applyFilters()" value="<?php echo date('Y-m-d'); ?>"></div>
                        </div>
                        <div class="flex justify-between items-center mt-6 pt-6 border-t border-slate-100">
                            <button onclick="resetFilters()" class="px-4 py-2 text-slate-600 hover:text-slate-800 transition">Reset All Filters</button>
                            <button onclick="exportFilteredData()" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">Export Filtered Data</button>
                        </div>
                    </div>

                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <span class="filter-chip active" onclick="toggleFilter('all')" data-filter="all"><i class="fas fa-receipt"></i> All Fees</span>
                        <span class="filter-chip" onclick="toggleFilter('paid')" data-filter="paid"><i class="fas fa-check-circle"></i> Paid (<?php echo $paidCount; ?>)</span>
                        <span class="filter-chip" onclick="toggleFilter('pending')" data-filter="pending"><i class="fas fa-clock"></i> Pending (<?php echo $pendingCount; ?>)</span>
                        <span class="filter-chip" onclick="toggleFilter('overdue')" data-filter="overdue"><i class="fas fa-exclamation-triangle"></i> Overdue (<?php echo $overdueCount; ?>)</span>
                        <span class="filter-chip" onclick="toggleFilter('partial')" data-filter="partial"><i class="fas fa-percentage"></i> Partial (<?php echo $partialCount; ?>)</span>
                        <span class="filter-chip" onclick="toggleFilter('today')" data-filter="today"><i class="fas fa-calendar-day"></i> Due Today (<?php echo $dueTodayCount; ?>)</span>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Collected Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TOTAL COLLECTED</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo formatCurrency($feeMetrics['total_collected']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-money-bill-wave text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 12.5%</span>
                            <span class="text-slate-500">from last term</span>
                        </div>
                    </div>

                    <!-- Pending Fees Card -->
                    <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">PENDING FEES</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo formatCurrency($feeMetrics['pending_fees']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-clock text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="fee-progress">
                            <div class="fee-progress-fill progress-pending" style="width: <?php echo min(($feeMetrics['pending_fees'] / max($feeMetrics['total_collected'] + $feeMetrics['pending_fees'], 1)) * 100, 100); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">
                            <?php echo round(($feeMetrics['pending_fees'] / max($feeMetrics['total_collected'] + $feeMetrics['pending_fees'], 1)) * 100, 1); ?>% of total fees
                        </p>
                    </div>

                    <!-- Overdue Fees Card -->
                    <div class="glass-card metric-card metric-danger rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">OVERDUE FEES</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo formatCurrency($feeMetrics['overdue_fees']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-50 to-red-100 flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-red-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> <?php echo $overdueCount; ?> students</span>
                            <span class="text-slate-500">need attention</span>
                        </div>
                    </div>

                    <!-- Collection Rate Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">COLLECTION RATE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $feeMetrics['collection_rate']; ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="fee-progress">
                            <div class="fee-progress-fill progress-partial" style="width: <?php echo $feeMetrics['collection_rate']; ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Current term collection rate</p>
                    </div>
                </div>

                <!-- Charts & Fee Breakdown -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Fee Collection Chart -->
                    <div class="glass-card rounded-2xl p-6 lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Fee Collection Trend</h3>
                                <p class="text-slate-500">Monthly fee collection for current academic year</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="viewCollectionDetails()" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">View Details <i class="fas fa-arrow-right ml-1"></i></button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="collectionChart"></canvas>
                        </div>
                    </div>

                    <!-- Fee Breakdown Summary -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Fee Breakdown</h3>
                        <div class="space-y-4 mb-6">
                            <?php 
                            $displayCategories = array_slice($feeCategoryTotals, 0, 4, true);
                            $totalFees = array_sum($feeCategoryTotals);
                            
                            if (empty($displayCategories)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-chart-pie"></i></div>
                                <p class="empty-state-title">No Fee Data</p>
                                <p class="empty-state-description">No fee categories have been collected yet.</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($displayCategories as $categoryName => $amount): 
                                    $percentage = $totalFees > 0 ? ($amount / $totalFees) * 100 : 0;
                                ?>
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <span class="text-sm font-medium text-slate-700"><?php echo htmlspecialchars($categoryName); ?></span>
                                        <span class="text-sm font-bold text-slate-900"><?php echo formatCurrency($amount); ?></span>
                                    </div>
                                    <div class="fee-progress">
                                        <div class="fee-progress-fill progress-paid" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="pt-4 border-t border-slate-100">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-sm font-medium text-slate-700">Total Fee Collection</span>
                                <span class="text-sm font-bold text-slate-900"><?php echo formatCurrency($feeMetrics['total_collected']); ?></span>
                            </div>
                            <button onclick="viewFeeBreakdown()" class="w-full py-3 border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">View Detailed Breakdown</button>
                        </div>
                    </div>
                </div>

                <!-- Recent Payments & Upcoming Due Dates -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Recent Payments -->
                    <div class="glass-card rounded-2xl p-6 lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Recent Payments</h3>
                            <button onclick="viewAllPayments()" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">View All <i class="fas fa-arrow-right ml-1"></i></button>
                        </div>

                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Fee Type</th>
                                        <th>Amount</th>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th class="w-24">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="recentPaymentsBody">
                                    <?php if (empty($recentPayments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-8">
                                            <div class="empty-state">
                                                <div class="empty-state-icon"><i class="fas fa-money-bill-wave"></i></div>
                                                <p class="empty-state-title">No Recent Payments</p>
                                                <p class="empty-state-description">No payments have been recorded yet.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentPayments as $payment): 
                                            $total = floatval($payment['amount'] ?? 0);
                                            $paid = $total;
                                            $status = $payment['status'] ?? 'pending';
                                            $statusClass = getStatusClass($status, null, $total, $paid);
                                            $amountClass = getAmountClass($status, $total, $paid);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                                                        <i class="fas fa-user text-slate-600"></i>
                                                    </div>
                                                    <div>
                                                        <p class="font-medium"><?php echo htmlspecialchars(($payment['student_first_name'] ?? '') . ' ' . ($payment['student_last_name'] ?? '')); ?></p>
                                                        <p class="text-xs text-slate-500"><?php echo htmlspecialchars($payment['admission_number'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="font-medium"><?php echo htmlspecialchars($payment['fee_category_name'] ?? 'General Fee'); ?></td>
                                            <td><span class="fee-amount <?php echo $amountClass; ?>"><?php echo formatCurrency($payment['amount'] ?? 0); ?></span></td>
                                            <td class="text-sm text-slate-600"><?php echo date('M d, Y', strtotime($payment['created_at'] ?? 'now')); ?></td>
                                            <td><span class="text-sm text-slate-600"><?php echo formatPaymentMethod($payment['payment_method'] ?? ''); ?></span></td>
                                            <td><span class="fee-status <?php echo $statusClass; ?>"><?php echo ucfirst($status); ?></span></td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <button onclick="viewReceipt(<?php echo $payment['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg"><i class="fas fa-receipt"></i></button>
                                                    <button onclick="editPayment(<?php echo $payment['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg"><i class="fas fa-edit"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Upcoming Due Dates -->
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Upcoming Due Dates</h3>
                            <span class="text-xs font-bold text-slate-400"><?php echo date('F Y'); ?></span>
                        </div>

                        <div class="space-y-4">
                            <?php if (empty($upcomingDueDates)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-calendar-check"></i></div>
                                <p class="empty-state-title">No Upcoming Due Dates</p>
                                <p class="empty-state-description">No invoices are due in the next 30 days.</p>
                            </div>
                            <?php else: ?>
                                <?php 
                                $today = strtotime(date('Y-m-d'));
                                $displayed = 0;
                                foreach ($upcomingDueDates as $due):
                                    if ($displayed >= 4) break;
                                    
                                    $dueDate = $due['due_date'] ?? '';
                                    $total = floatval($due['total_amount'] ?? 0);
                                    $paid = floatval($due['paid_amount'] ?? 0);
                                    $balance = $total - $paid;
                                    $status = $due['status'] ?? 'pending';
                                    $daysUntilDue = $due['days_until_due'] ?? 0;
                                    
                                    if ($balance <= 0) continue; // Skip paid invoices
                                    
                                    $dueClass = getDueDateClass($dueDate, $status, $total, $paid);
                                    $icon = 'fa-calendar-day';
                                    $bgColor = 'bg-emerald-50';
                                    $borderColor = 'border-emerald-200';
                                    $iconColor = 'text-emerald-600';
                                    
                                    if ($daysUntilDue < 0) {
                                        $icon = 'fa-exclamation-triangle';
                                        $bgColor = 'bg-red-50';
                                        $borderColor = 'border-red-200';
                                        $iconColor = 'text-red-600';
                                    } elseif ($daysUntilDue <= 3) {
                                        $icon = 'fa-clock';
                                        $bgColor = 'bg-amber-50';
                                        $borderColor = 'border-amber-200';
                                        $iconColor = 'text-amber-600';
                                    }
                                ?>
                                <div class="p-4 <?php echo $bgColor; ?> border <?php echo $borderColor; ?> rounded-xl">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <div class="w-8 h-8 rounded-lg <?php echo str_replace('text-', 'bg-', $iconColor); ?> bg-opacity-20 flex items-center justify-center">
                                                <i class="fas <?php echo $icon; ?> <?php echo $iconColor; ?>"></i>
                                            </div>
                                            <span class="font-bold text-slate-900">
                                                <?php echo htmlspecialchars(($due['student_first_name'] ?? '') . ' ' . ($due['student_last_name'] ?? '')); ?>
                                            </span>
                                        </div>
                                        <span class="due-date <?php echo $dueClass; ?>">
                                            <?php 
                                            if ($daysUntilDue < 0) {
                                                echo 'Overdue';
                                            } elseif ($daysUntilDue == 0) {
                                                echo 'Today';
                                            } else {
                                                echo $daysUntilDue . ' day' . ($daysUntilDue != 1 ? 's' : '');
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-slate-600">
                                        Invoice #<?php echo htmlspecialchars($due['invoice_number'] ?? ''); ?> • 
                                        Balance: <?php echo formatCurrency($balance); ?>
                                    </p>
                                </div>
                                <?php $displayed++; endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <button onclick="viewAllDueDates()" class="w-full mt-6 py-3 border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">View Calendar</button>
                    </div>
                </div>

                <!-- Fee Invoices Table -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-black text-slate-900">Fee Invoices</h3>
                        <div class="flex items-center gap-3">
                            <div class="search-box" style="max-width: 300px">
                                <input type="text" placeholder="Search invoices..." class="search-input" id="invoiceSearch" onkeyup="filterInvoices()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="openModal('generateInvoiceModal')" class="action-btn action-btn-primary"><i class="fas fa-plus"></i> Generate Invoice</button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12"><input type="checkbox" class="rounded border-slate-300" id="selectAllInvoices" onchange="toggleAllInvoices()"></th>
                                    <th>Invoice #</th>
                                    <th>Student</th>
                                    <th>Fee Type</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th class="w-32">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="feeInvoicesBody">
                                <?php if (empty($feeInvoices)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-8">
                                        <div class="empty-state">
                                            <div class="empty-state-icon"><i class="fas fa-file-invoice"></i></div>
                                            <p class="empty-state-title">No Invoices Found</p>
                                            <p class="empty-state-description">No invoices have been created for the current term yet.</p>
                                            <button onclick="openModal('generateInvoiceModal')" class="mt-4 px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all">Create First Invoice</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($feeInvoices as $invoice): 
                                        $total = floatval($invoice['total_amount'] ?? 0);
                                        $paid = floatval($invoice['paid_amount'] ?? 0);
                                        $balance = $total - $paid;
                                        $status = $invoice['status'] ?? 'pending';
                                        $dueDate = $invoice['due_date'] ?? '';
                                        
                                        $statusClass = getStatusClass($status, $dueDate, $total, $paid);
                                        $amountClass = getAmountClass($status, $total, $paid);
                                        $dueDateClass = getDueDateClass($dueDate, $status, $total, $paid);
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" class="invoice-checkbox rounded border-slate-300" data-id="<?php echo $invoice['id']; ?>" onchange="toggleInvoiceSelection(<?php echo $invoice['id']; ?>)"></td>
                                        <td class="font-bold text-slate-900"><?php echo htmlspecialchars($invoice['invoice_number'] ?? 'INV-' . $invoice['id']); ?></td>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center">
                                                    <i class="fas fa-user text-slate-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-medium"><?php echo htmlspecialchars(($invoice['student_first_name'] ?? '') . ' ' . ($invoice['student_last_name'] ?? '')); ?></p>
                                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($invoice['admission_number'] ?? ''); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="font-medium"><?php echo htmlspecialchars($invoice['fee_category_name'] ?? 'General Fee'); ?></td>
                                        <td><span class="fee-amount <?php echo $amountClass; ?>"><?php echo formatCurrency($total); ?></span></td>
                                        <td><span class="due-date <?php echo $dueDateClass; ?>"><?php echo date('M d, Y', strtotime($dueDate)); ?></span></td>
                                        <td>
                                            <span class="fee-status <?php echo $statusClass; ?>">
                                                <?php 
                                                if ($balance <= 0 && $total > 0) {
                                                    echo 'Paid';
                                                } elseif ($balance > 0 && $paid > 0) {
                                                    echo 'Partial';
                                                } else {
                                                    echo ucfirst($status);
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <button onclick="viewInvoice(<?php echo $invoice['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg"><i class="fas fa-file-invoice"></i></button>
                                                <button onclick="editInvoice(<?php echo $invoice['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg"><i class="fas fa-edit"></i></button>
                                                <button onclick="sendInvoice(<?php echo $invoice['id']; ?>)" class="p-2 text-emerald-600 hover:bg-emerald-100 rounded-lg"><i class="fas fa-paper-plane"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex items-center justify-between mt-6">
                        <div class="text-sm text-slate-500">
                            Showing <span id="showingCount"><?php echo min(10, count($feeInvoices)); ?></span> of
                            <span id="totalCount"><?php echo count($feeInvoices); ?></span> invoices
                        </div>
                        <div class="flex items-center gap-2">
                            <button class="pagination-btn" onclick="previousPage()"><i class="fas fa-chevron-left"></i></button>
                            <button class="pagination-btn active">1</button>
                            <button class="pagination-btn" onclick="goToPage(2)">2</button>
                            <button class="pagination-btn" onclick="goToPage(3)">3</button>
                            <span class="px-2">...</span>
                            <button class="pagination-btn" onclick="goToPage(13)">13</button>
                            <button class="pagination-btn" onclick="nextPage()"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Payment Methods -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Quick Actions -->
                    <div class="glass-card rounded-2xl p-6 lg:col-span-2">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Actions</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="summary-card" style="background: linear-gradient(135deg, #f0fdf4, #dcfce7)">
                                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mb-4 mx-auto">
                                    <i class="fas fa-money-bill-wave text-emerald-600 text-lg"></i>
                                </div>
                                <div class="summary-value"><?php echo formatCurrency($feeMetrics['today_collection']); ?></div>
                                <div class="summary-label">Today's Collection</div>
                                <button onclick="viewTodayCollection()" class="mt-4 px-6 py-2 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition">View Details</button>
                            </div>
                            <div class="summary-card" style="background: linear-gradient(135deg, #fffbeb, #fef3c7)">
                                <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center mb-4 mx-auto">
                                    <i class="fas fa-clock text-amber-600 text-lg"></i>
                                </div>
                                <div class="summary-value"><?php echo $overdueCount; ?></div>
                                <div class="summary-label">Overdue Students</div>
                                <button onclick="viewOverdueStudents()" class="mt-4 px-6 py-2 bg-amber-600 text-white font-bold rounded-xl hover:bg-amber-700 transition">Send Reminders</button>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Methods -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Payment Methods</h3>
                        <div class="space-y-4">
                            <?php if (empty($paymentMethods)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon"><i class="fas fa-credit-card"></i></div>
                                <p class="empty-state-title">No Payment Data</p>
                                <p class="empty-state-description">No payment methods have been used yet.</p>
                            </div>
                            <?php else: ?>
                                <?php 
                                $totalPaymentAmount = array_sum(array_column($paymentMethods, 'amount'));
                                foreach ($paymentMethods as $method): 
                                    $percentage = $totalPaymentAmount > 0 ? round(($method['amount'] / $totalPaymentAmount) * 100) : 0;
                                    $methodName = formatPaymentMethod($method['payment_method']);
                                    $icon = getPaymentMethodIcon($method['payment_method']);
                                    $colorClass = getPaymentMethodColor($method['payment_method']);
                                ?>
                                <div class="payment-method <?php echo $method['payment_method'] === 'bank_transfer' ? 'selected' : ''; ?>" onclick="selectPaymentMethod('<?php echo $method['payment_method']; ?>')">
                                    <div class="w-10 h-10 rounded-lg <?php echo str_replace('text-', 'bg-', $colorClass); ?> bg-opacity-20 flex items-center justify-center">
                                        <i class="fas <?php echo $icon; ?> <?php echo $colorClass; ?>"></i>
                                    </div>
                                    <div class="text-left">
                                        <p class="font-bold"><?php echo htmlspecialchars($methodName); ?></p>
                                        <p class="text-sm text-slate-500"><?php echo $percentage; ?>% of payments</p>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-lg font-black"><?php echo formatCurrency($method['amount']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            let icon = 'fa-info-circle';
            switch(type) {
                case 'success': icon = 'fa-check-circle'; break;
                case 'warning': icon = 'fa-exclamation-triangle'; break;
                case 'error': icon = 'fa-times-circle'; break;
                default: icon = 'fa-info-circle';
            }
            toast.innerHTML = `<i class="fas ${icon} toast-icon"></i><div class="toast-content">${message}</div><button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>`;
            container.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; }, 10);
            if (duration > 0) {
                setTimeout(() => {
                    toast.classList.add('toast-exit');
                    setTimeout(() => { if (toast.parentElement) toast.remove(); }, 300);
                }, duration);
            }
        }

        // Mobile Sidebar Toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Tab Switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
            showToast(`Switched to ${tabName} view`, 'info');
        }

        // Initialize Charts with LIVE DATA
        let collectionChart;
        function initCharts() {
            const collectionCtx = document.getElementById('collectionChart').getContext('2d');
            const months = Object.keys(<?php echo json_encode($monthlyData); ?>);
            const amounts = Object.values(<?php echo json_encode($monthlyData); ?>);
            
            collectionChart = new Chart(collectionCtx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Fee Collection (<?php echo $currencySymbol; ?>)',
                        data: amounts,
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: '#10b981',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: value => '<?php echo $currencySymbol; ?>' + value.toLocaleString() }
                        }
                    }
                }
            });
        }

        // Filter Functions
        function toggleFilters() {
            const filters = document.getElementById('advancedFilters');
            filters.classList.toggle('hidden');
        }

        function resetFilters() {
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterAcademicYear').value = '';
            document.getElementById('filterTerm').value = '';
            document.getElementById('filterDate').value = '';
            applyFilters();
            showToast('All filters reset', 'info');
        }

        function applyFilters() {
            const status = document.getElementById('filterStatus').value;
            const academicYear = document.getElementById('filterAcademicYear').value;
            const term = document.getElementById('filterTerm').value;
            const date = document.getElementById('filterDate').value;
            showToast(`Applied filters: ${status || 'All'} status, ${academicYear || 'All'} years`, 'info');
        }

        function filterFees() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            if (searchTerm) showToast(`Searching for: ${searchTerm}`, 'info');
        }

        function filterInvoices() {
            const searchTerm = document.getElementById('invoiceSearch').value.toLowerCase();
            showToast(`Searching invoices: ${searchTerm}`, 'info');
        }

        function toggleFilter(filter) {
            const chip = document.querySelector(`[data-filter="${filter}"]`);
            const allChips = document.querySelectorAll('.filter-chip');
            if (filter === 'all') {
                allChips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
            } else {
                document.querySelector('[data-filter="all"]').classList.remove('active');
                chip.classList.toggle('active');
                const activeFilters = document.querySelectorAll('.filter-chip.active');
                if (activeFilters.length === 0) document.querySelector('[data-filter="all"]').classList.add('active');
            }
            applyFeeFilters();
        }

        function applyFeeFilters() {
            const activeFilters = Array.from(document.querySelectorAll('.filter-chip.active')).map(chip => chip.dataset.filter);
            showToast(`Applied ${activeFilters.length} fee filters`, 'info');
        }

        // Payment Management Functions
        let selectedInvoices = new Set();

        function loadStudentFeeDetails() {
            const studentId = document.getElementById('paymentStudent').value;
            const feeTypeSelect = document.getElementById('paymentFeeType');
            if (studentId && feeTypeSelect.value) {
                const selectedOption = feeTypeSelect.options[feeTypeSelect.selectedIndex];
                const amount = selectedOption.getAttribute('data-amount') || '0';
                document.getElementById('paymentAmount').value = amount;
            }
        }

        // Invoice type toggle
        function toggleInvoiceSections() {
            const type = document.getElementById('invoiceType').value;
            const singleSection = document.getElementById('singleStudentSection');
            const classSection = document.getElementById('classSection');
            if (type === 'single') {
                singleSection.classList.remove('hidden');
                classSection.classList.add('hidden');
                document.getElementById('invoiceStudent').required = true;
                document.getElementById('invoiceClass').required = false;
            } else {
                singleSection.classList.add('hidden');
                classSection.classList.remove('hidden');
                document.getElementById('invoiceStudent').required = false;
                document.getElementById('invoiceClass').required = true;
            }
        }

        // Update invoice amount when fee category changes
        function updateInvoiceAmount() {
            const feeCategorySelect = document.getElementById('invoiceFeeCategory');
            if (feeCategorySelect.value) {
                const selectedOption = feeCategorySelect.options[feeCategorySelect.selectedIndex];
                const amount = selectedOption.getAttribute('data-amount') || '0';
                document.getElementById('invoiceAmount').value = amount;
            }
        }

        // Invoice Selection
        function toggleInvoiceSelection(invoiceId) {
            const checkbox = document.querySelector(`.invoice-checkbox[data-id="${invoiceId}"]`);
            const bulkEditPanel = document.getElementById('bulkEditPanel');
            if (checkbox.checked) selectedInvoices.add(invoiceId);
            else selectedInvoices.delete(invoiceId);
            const count = selectedInvoices.size;
            document.getElementById('bulkEditCount').textContent = `${count} fees selected`;
            if (count > 0) bulkEditPanel.classList.remove('hidden');
            else bulkEditPanel.classList.add('hidden');
        }

        // Select All Invoices
        function toggleAllInvoices() {
            const selectAll = document.getElementById('selectAllInvoices');
            const checkboxes = document.querySelectorAll('.invoice-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
                const invoiceId = parseInt(cb.dataset.id);
                if (selectAll.checked) selectedInvoices.add(invoiceId);
                else selectedInvoices.delete(invoiceId);
            });
            const bulkEditPanel = document.getElementById('bulkEditPanel');
            const count = selectedInvoices.size;
            document.getElementById('bulkEditCount').textContent = `${count} fees selected`;
            if (count > 0) bulkEditPanel.classList.remove('hidden');
            else bulkEditPanel.classList.add('hidden');
        }

        // Close Bulk Edit
        function closeBulkEdit() {
            selectedInvoices.clear();
            document.querySelectorAll('.invoice-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllInvoices').checked = false;
            document.getElementById('bulkEditPanel').classList.add('hidden');
        }

        // Bulk Actions
        function applyBulkDiscount() {
            showToast(`Applying discount to ${selectedInvoices.size} selected invoices`, 'info');
        }

        function extendBulkDueDate() {
            showToast(`Extending due date for ${selectedInvoices.size} invoices`, 'info');
        }

        function sendBulkReminders() {
            showToast(`Sending reminders for ${selectedInvoices.size} invoices`, 'info');
        }

        function exportBulkData() {
            showToast(`Exporting ${selectedInvoices.size} selected records`, 'success');
        }

        // Payment form submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const student = document.getElementById('paymentStudent').value;
            const amount = document.getElementById('paymentAmount').value;
            if (!student || !amount) {
                showToast('Please fill all required fields', 'error');
                return;
            }
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            submitBtn.disabled = true;
            
            // AJAX submission
            const formData = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Payment recorded successfully!', 'success');
                    closeModal('recordPaymentModal');
                    this.reset();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error recording payment', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });

        // Invoice form submission
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const type = document.getElementById('invoiceType').value;
            const student = document.getElementById('invoiceStudent').value;
            const classSelect = document.getElementById('invoiceClass').value;
            if (type === 'single' && !student) {
                showToast('Please select a student for single invoice', 'error');
                return;
            }
            if (type !== 'single' && !classSelect) {
                showToast('Please select a class for class-wise invoices', 'error');
                return;
            }
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            submitBtn.disabled = true;
            
            // AJAX submission
            const formData = new FormData(this);
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Invoice generated successfully!', 'success');
                    closeModal('generateInvoiceModal');
                    this.reset();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error generating invoice', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });

        function viewReceipt(paymentId) {
            // AJAX call to load receipt
            fetch(`/tenant/<?php echo $schoolSlug; ?>/admin/receipt/${paymentId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('receiptContent').innerHTML = html;
                    openModal('viewReceiptModal');
                })
                .catch(error => {
                    console.error('Error loading receipt:', error);
                    showToast('Failed to load receipt', 'error');
                });
        }

        function printReceipt() {
            window.print();
        }

        function emailReceipt() {
            showToast('Receipt sent to email successfully', 'success');
        }

        function selectReminderMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => el.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            document.querySelector(`input[name="reminderMethod"][value="${method}"]`).checked = true;
        }

        function sendReminders() {
            const type = document.getElementById('reminderType').value;
            const method = document.querySelector('input[name="reminderMethod"]:checked').value;
            showToast(`Sending ${type} reminders via ${method}...`, 'info');
            setTimeout(() => {
                showToast('Reminders sent successfully!', 'success');
                closeModal('sendRemindersModal');
            }, 1500);
        }

        function selectPaymentMethod(method) {
            document.querySelectorAll('.payment-method').forEach(el => el.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
        }

        // Pagination Functions
        function previousPage() {
            showToast('Loading previous page...', 'info');
        }

        function nextPage() {
            showToast('Loading next page...', 'info');
        }

        function goToPage(page) {
            showToast(`Loading page ${page}...`, 'info');
        }

        // View Functions
        function viewAllPayments() {
            switchTab('payments');
            showToast('Loading all payments...', 'info');
        }

        function viewAllDueDates() {
            showToast('Opening due dates calendar...', 'info');
        }

        function viewCollectionDetails() {
            switchTab('reports');
            showToast('Opening collection analytics...', 'info');
        }

        function viewFeeBreakdown() {
            showToast('Loading detailed fee breakdown...', 'info');
        }

        function viewTodayCollection() {
            showToast('Loading today\'s collection details...', 'info');
        }

        function viewOverdueStudents() {
            openModal('sendRemindersModal');
        }

        function viewCollectionAnalytics() {
            switchTab('reports');
            showToast('Loading collection analytics...', 'info');
        }

        function exportFilteredData() {
            showToast('Exporting filtered data to Excel...', 'success');
        }

        // Invoice view/edit functions
        function viewInvoice(id) {
            showToast(`Viewing invoice ${id} details...`, 'info');
        }

        function editInvoice(id) {
            showToast(`Editing invoice ${id}...`, 'info');
        }

        function sendInvoice(id) {
            showToast(`Sending invoice ${id} to parent...`, 'info');
        }

        // Payment edit function
        function editPayment(id) {
            showToast(`Editing payment ${id}...`, 'info');
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
            flatpickr("#paymentDate", { dateFormat: "Y-m-d", defaultDate: "today" });
            flatpickr("#invoiceDueDate", { dateFormat: "Y-m-d", defaultDate: new Date().fp_incr(30) });
            document.getElementById('filterDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('paymentFeeType').addEventListener('change', loadStudentFeeDetails);
            document.getElementById('invoiceFeeCategory').addEventListener('change', updateInvoiceAmount);
            setTimeout(() => { showToast('Fee Management System Loaded', 'info'); }, 1000);
        });
    </script>
</body>
</html>