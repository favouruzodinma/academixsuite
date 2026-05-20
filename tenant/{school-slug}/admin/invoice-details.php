<?php

/**
 * View Invoice Details Page
 * Displays detailed invoice information for a specific invoice
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/invoice_view.log');

error_log("=== INVOICE VIEW PAGE START ===");

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get school slug from GLOBALS (set by router)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug");
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Get invoice ID from URL
$invoiceId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$invoiceId) {
    header('Location: subscription-plan.php?error=invalid_invoice');
    exit;
}

// Get school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Verify authentication
if (!isset($_SESSION['school_auth']) || 
    $_SESSION['school_auth']['school_slug'] !== $schoolSlug) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Verify admin access
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
if ($schoolAuth['user_type'] !== 'admin') {
    error_log("ERROR: Non-admin user attempted access");
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. Admin privileges required.');
}

// Load configuration
try {
    require_once __DIR__ . '/../../../includes/autoload.php';
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("System configuration error. Please contact support.");
}

// Connect to platform database
$platformDb = null;
try {
    $platformDb = Database::getPlatformConnection();
    error_log("Platform database connection successful");
} catch (Exception $e) {
    error_log("ERROR connecting to platform database: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Exchange rate
define('EXCHANGE_RATE', 1500);

// Initialize variables
$invoice = null;
$subscription = null;
$plan = null;
$payments = [];
$schoolSettings = [];
$adminUser = [
    'name' => 'Administrator',
    'role_name' => 'Admin'
];
$error = '';

// Fetch invoice details
try {
    $stmt = $platformDb->prepare("
        SELECT i.*, 
               s.plan_id, s.billing_cycle as subscription_cycle, s.status as subscription_status,
               p.name as plan_name, p.description as plan_description
        FROM invoices i
        LEFT JOIN subscriptions s ON i.subscription_id = s.id
        LEFT JOIN plans p ON s.plan_id = p.id
        WHERE i.id = ? AND i.school_id = ?
    ");
    $stmt->execute([$invoiceId, $school['id']]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        header('Location: subscription-plan.php?error=invoice_not_found');
        exit;
    }
    
    // Convert amounts to NGN
    $invoice['amount_ngn'] = ($invoice['amount'] ?? 0) * EXCHANGE_RATE;
    $invoice['tax_ngn'] = ($invoice['tax'] ?? 0) * EXCHANGE_RATE;
    $invoice['total_amount_ngn'] = ($invoice['total_amount'] ?? $invoice['amount'] ?? 0) * EXCHANGE_RATE;
    
    // Get subscription details if available
    if (!empty($invoice['subscription_id'])) {
        $subStmt = $platformDb->prepare("
            SELECT s.*, p.name as plan_name, p.description as plan_description,
                   p.features as plan_features
            FROM subscriptions s
            JOIN plans p ON s.plan_id = p.id
            WHERE s.id = ?
        ");
        $subStmt->execute([$invoice['subscription_id']]);
        $subscription = $subStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($subscription) {
            $subscription['amount_ngn'] = ($subscription['amount'] ?? 0) * EXCHANGE_RATE;
        }
    }
    
    // Get payment records for this invoice
    $paymentStmt = $platformDb->prepare("
        SELECT * FROM payments 
        WHERE invoice_id = ? 
        ORDER BY created_at DESC
    ");
    $paymentStmt->execute([$invoiceId]);
    $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert payment amounts to NGN
    foreach ($payments as &$payment) {
        $payment['amount_ngn'] = ($payment['amount'] ?? 0) * EXCHANGE_RATE;
    }
    
} catch (Exception $e) {
    error_log("Error fetching invoice: " . $e->getMessage());
    $error = "Failed to load invoice details.";
}

// Fetch school settings from school database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
        $settingsStmt->execute([$school['id']]);
        $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($settingsRows as $row) {
            $schoolSettings[$row['key']] = $row['value'];
        }
        
        // Get admin user info
        $userStmt = $schoolDb->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ? AND u.school_id = ?
        ");
        $userStmt->execute([$userId, $school['id']]);
        $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($adminUserData) {
            $adminUser = [
                'name' => $adminUserData['name'] ?? 'Admin User',
                'role_name' => $adminUserData['role_name'] ?? 'Admin',
                'profile_photo' => $adminUserData['profile_photo'] ?? ''
            ];
        }
    } catch (Exception $e) {
        error_log("Error fetching school data: " . $e->getMessage());
    }
}

// Helper function for status badge
function getStatusBadge($status) {
    $badges = [
        'paid' => 'success',
        'pending' => 'warning',
        'overdue' => 'danger',
        'draft' => 'secondary',
        'cancelled' => 'secondary',
        'sent' => 'info'
    ];
    return $badges[$status] ?? 'secondary';
}

// Helper function for payment method icon
function getPaymentMethodIcon($method) {
    $method = strtolower($method ?? '');
    if (strpos($method, 'card') !== false) return 'ri-bank-card-line';
    if (strpos($method, 'bank') !== false) return 'ri-bank-line';
    if (strpos($method, 'mobile') !== false || strpos($method, 'money') !== false) return 'ri-smartphone-line';
    if (strpos($method, 'cash') !== false) return 'ri-money-cny-circle-line';
    if (strpos($method, 'paystack') !== false) return 'ri-secure-payment-line';
    if (strpos($method, 'flutterwave') !== false) return 'ri-flashlight-line';
    return 'ri-bank-card-line';
}

error_log("=== INVOICE VIEW PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="View Invoice Details">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Details - <?php echo htmlspecialchars($school['name']); ?></title>
    
    <!-- Styles -->
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
    
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .invoice-print {
                background: white;
                padding: 2rem;
            }
            body {
                background: white;
            }
            .card {
                border: 1px solid #dee2e6 !important;
                box-shadow: none !important;
            }
        }
        .print-only {
            display: none;
        }
        .invoice-status-badge {
            font-size: 1rem;
            padding: 0.5rem 1.5rem;
        }
        .invoice-detail-label {
            color: #6c757d;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        .invoice-detail-value {
            font-size: 1.125rem;
            font-weight: 600;
        }
    </style>
</head>

<body>
    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700 no-print" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    
    <!-- Print Header (only visible when printing) -->
    <div class="print-only">
        <div class="text-center mb-4">
            <img src="https://academixsuite.com/tenant/assets/images/logo.png" alt="AcademixSuite" height="60">
            <h2 class="mt-3">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h2>
            <p><?php echo htmlspecialchars($school['name']); ?></p>
            <hr>
        </div>
    </div>

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <aside class="sidebar no-print">
        <button type="button" class="sidebar-close-btn">
            <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
        </button>
        <div class="">
            <div class="sidebar-logo d-flex align-items-center justify-content-between">
                <a href="index.html" class="">
                    <img src="https://academixsuite.com/tenant/assets/images/logo.png" alt="site logo" class="light-logo">
                    <img src="https://academixsuite.com/tenant/assets/images/logo-light.png" alt="site logo" class="dark-logo">
                    <img src="https://academixsuite.com/tenant/assets/images/logo-icon.png" alt="site logo" class="logo-icon">
                </a>
                <button type="button" class="text-xxl d-xl-flex d-none line-height-1 sidebar-toggle text-neutral-500" aria-label="Collapse Sidebar">
                    <i class="ri-contract-left-line"></i>
                </button>
            </div>
        </div>
        <!-- User Info start -->
        <div class="mx-16 py-12">
            <div class="dropdown profile-dropdown">
                <button type="button" class="profile-dropdown__button d-flex align-items-center justify-content-between p-10 w-100 overflow-hidden bg-neutral-50 radius-12" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                    <span class="d-flex align-items-start gap-10">
                        <img src="<?php echo !empty($adminUser['profile_photo']) ? htmlspecialchars($adminUser['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/leave-request-img2.png'; ?>" alt="Thumbnail" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                        <span class="profile-dropdown__contents">
                            <span class="h6 mb-0 text-md d-block text-primary-light"><?php echo htmlspecialchars($adminUser['name']); ?></span>
                            <span class="text-secondary-light text-sm mb-0 d-block"><?php echo htmlspecialchars($adminUser['role_name']); ?></span>
                        </span>
                    </span>
                    <span class="profile-dropdown__icon pe-8 text-xl d-flex line-height-1">
                        <i class="ri-arrow-right-s-line"></i>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                    <li>
                        <a href="profile.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                            <i class="ri-user-3-line"></i>
                            My Profile
                        </a>
                    </li>
                    <li>
                        <a href="general.html" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                            <i class="ri-settings-3-line"></i>
                            Setting
                        </a>
                    </li>
                    <li>
                        <a href="../../logout.php?school_slug=<?php echo urlencode($schoolSlug); ?>" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                            <i class="ri-shut-down-line"></i>
                            Log Out
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <!-- User Info end -->
        <div class="sidebar-menu-area">
            <ul class="sidebar-menu" id="sidebar-menu">
                <!-- Sidebar menu items (same as subscription page) -->
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-home-4-line"></i>
                        <span>Dashboard </span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="index.html"><i class="ri-circle-fill circle-icon w-auto"></i>School</a></li>
                        <li><a href="index-2.html"><i class="ri-circle-fill circle-icon w-auto"></i>Student</a></li>
                        <li><a href="index-3.html"><i class="ri-circle-fill circle-icon w-auto"></i>Teacher</a></li>
                        <li><a href="index-4.html"><i class="ri-circle-fill circle-icon w-auto"></i>Parent</a></li>
                        <li><a href="index-5.html"><i class="ri-circle-fill circle-icon w-auto"></i>LMS</a></li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-graduation-cap-line"></i>
                        <span>Students</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li><a href="add-new-student.html"><i class="ri-circle-fill circle-icon w-auto"></i>Add New Student</a></li>
                        <li><a href="student-list.html"><i class="ri-circle-fill circle-icon w-auto"></i>Student List</a></li>
                        <li><a href="suspended-student.html"><i class="ri-circle-fill circle-icon w-auto"></i>Suspend Student</a></li>
                        <li><a href="student-category.html"><i class="ri-circle-fill circle-icon w-auto"></i>Student Categories</a></li>
                        <li><a href="edit-student.html"><i class="ri-circle-fill circle-icon w-auto"></i>Edit Student</a></li>
                        <li><a href="student-details.html"><i class="ri-circle-fill circle-icon w-auto"></i>Student Details</a></li>
                    </ul>
                </li>
                <li>
                    <a href="subscription-plan.php" class="active">
                        <i class="ri-price-tag-3-line"></i>
                        <span>Subscription Plan</span>
                    </a>
                </li>
            </ul>
        </div>
    </aside>

    <main class="dashboard-main">
        <div class="navbar-header shadow-1 no-print">
            <div class="row align-items-center justify-content-between">
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-4">
                        <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                            <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                        </button>
                        <form class="navbar-search">
                            <input type="text" class="bg-transparent" name="search" placeholder="Search...">
                            <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                        </form>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24 no-print">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Invoice Details</h1>
                    <div class="">
                        <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light"> / </span>
                        <a href="subscription-plan.php" class="text-secondary-light hover-text-primary hover-underline">Subscription Plan</a>
                        <span class="text-secondary-light"> / Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-8">
                    <button type="button" class="btn btn-outline-primary-600 btn-sm" onclick="window.print()">
                        <i class="ri-printer-line"></i> Print
                    </button>
                    <a href="subscription-plan.php" class="btn btn-secondary btn-sm">
                        <i class="ri-arrow-left-line"></i> Back
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="ri-error-warning-line me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Invoice Details -->
            <div class="row">
                <div class="col-lg-8">
                    <!-- Main Invoice Card -->
                    <div class="card">
                        <div class="card-body p-24">
                            <!-- Invoice Header -->
                            <div class="row align-items-center mb-24">
                                <div class="col-sm-6">
                                    <h4 class="mb-8">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h4>
                                    <p class="text-secondary-light mb-0">Issued: <?php echo date('F d, Y', strtotime($invoice['created_at'])); ?></p>
                                </div>
                                <div class="col-sm-6 text-sm-end mt-16 mt-sm-0">
                                    <span class="badge bg-<?php echo getStatusBadge($invoice['status']); ?>-600 text-white px-24 py-12 radius-8 fs-6 invoice-status-badge">
                                        <i class="ri-information-line me-2"></i>
                                        <?php echo strtoupper($invoice['status']); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- School & Billing Info -->
                            <div class="row mb-24">
                                <div class="col-sm-6">
                                    <div class="border p-20 radius-12 bg-neutral-50">
                                        <span class="invoice-detail-label">From</span>
                                        <h5 class="mb-4">AcademixSuite</h5>
                                        <p class="text-secondary-light mb-8">support@academixsuite.com</p>
                                        <p class="text-secondary-light mb-0">Lagos, Nigeria</p>
                                    </div>
                                </div>
                                <div class="col-sm-6 mt-16 mt-sm-0">
                                    <div class="border p-20 radius-12 bg-neutral-50">
                                        <span class="invoice-detail-label">To</span>
                                        <h5 class="mb-4"><?php echo htmlspecialchars($school['name']); ?></h5>
                                        <p class="text-secondary-light mb-8"><?php echo htmlspecialchars($school['email'] ?? $school['school_email'] ?? 'N/A'); ?></p>
                                        <p class="text-secondary-light mb-0"><?php echo htmlspecialchars($school['address'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                            </div>

                            <!-- Invoice Items -->
                            <div class="mb-24">
                                <h6 class="mb-16">Invoice Items</h6>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead class="bg-neutral-50">
                                            <tr>
                                                <th>Description</th>
                                                <th class="text-end">Amount (NGN)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>
                                                    <?php echo htmlspecialchars($invoice['notes'] ?? $invoice['description'] ?? 'Subscription Payment'); ?>
                                                    <?php if (!empty($subscription)): ?>
                                                    <br>
                                                    <small class="text-secondary-light">
                                                        Plan: <?php echo htmlspecialchars($subscription['plan_name']); ?> | 
                                                        Cycle: <?php echo ucfirst($subscription['billing_cycle']); ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end fw-semibold">₦<?php echo number_format($invoice['amount_ngn'], 2); ?></td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="border-top">
                                            <tr>
                                                <th class="text-end">Subtotal:</th>
                                                <th class="text-end">₦<?php echo number_format($invoice['amount_ngn'], 2); ?></th>
                                            </tr>
                                            <?php if ($invoice['tax'] > 0): ?>
                                            <tr>
                                                <td class="text-end">Tax (<?php echo $invoice['tax']; ?>%):</td>
                                                <td class="text-end">₦<?php echo number_format($invoice['tax_ngn'], 2); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr class="border-top">
                                                <th class="text-end h5 mb-0">Total:</th>
                                                <th class="text-end h5 mb-0 text-primary-600">₦<?php echo number_format($invoice['total_amount_ngn'], 2); ?></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- Payment Information -->
                            <?php if (!empty($payments)): ?>
                            <div>
                                <h6 class="mb-16">Payment History</h6>
                                <div class="table-responsive">
                                    <table class="table bordered-table">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Transaction ID</th>
                                                <th>Method</th>
                                                <th class="text-end">Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($payments as $payment): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y H:i', strtotime($payment['paid_at'] ?? $payment['created_at'])); ?></td>
                                                <td>
                                                    <span class="text-primary-600"><?php echo htmlspecialchars($payment['transaction_id'] ?? 'N/A'); ?></span>
                                                </td>
                                                <td>
                                                    <i class="<?php echo getPaymentMethodIcon($payment['payment_method']); ?> me-2"></i>
                                                    <?php echo ucfirst($payment['payment_method'] ?? 'Online'); ?>
                                                </td>
                                                <td class="text-end fw-semibold">₦<?php echo number_format($payment['amount_ngn'], 2); ?></td>
                                                <td>
                                                    <span class="badge bg-success-100 text-success-600 px-24 py-4 radius-4">
                                                        <?php echo ucfirst($payment['status'] ?? 'Completed'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Due Date Notice -->
                            <?php if ($invoice['status'] == 'pending'): ?>
                            <div class="alert alert-warning d-flex align-items-center gap-2 mt-24">
                                <i class="ri-alert-line fs-4"></i>
                                <div>
                                    <strong>Payment Due:</strong> Please pay by <?php echo date('F d, Y', strtotime($invoice['due_date'])); ?>
                                    <?php if (strtotime($invoice['due_date']) < time()): ?>
                                    <span class="badge bg-danger-600 text-white ms-2">Overdue</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php elseif ($invoice['status'] == 'paid' && !empty($invoice['paid_at'])): ?>
                            <div class="alert alert-success d-flex align-items-center gap-2 mt-24">
                                <i class="ri-checkbox-circle-line fs-4"></i>
                                <div>
                                    <strong>Payment Received:</strong> Paid on <?php echo date('F d, Y \a\t H:i', strtotime($invoice['paid_at'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <!-- Summary Card -->
                    <div class="card">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Invoice Summary</h6>
                        </div>
                        <div class="card-body p-24">
                            <ul class="list-unstyled">
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Invoice Number:</span>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Issue Date:</span>
                                    <span class="fw-semibold"><?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Due Date:</span>
                                    <span class="fw-semibold <?php echo (strtotime($invoice['due_date']) < time() && $invoice['status'] == 'pending') ? 'text-danger-600' : ''; ?>">
                                        <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                    </span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Status:</span>
                                    <span class="badge bg-<?php echo getStatusBadge($invoice['status']); ?>-600 text-white px-12 py-4">
                                        <?php echo ucfirst($invoice['status']); ?>
                                    </span>
                                </li>
                                <?php if ($invoice['is_trial']): ?>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Trial Invoice:</span>
                                    <span class="badge bg-info-600 text-white px-12 py-4">Yes</span>
                                </li>
                                <?php endif; ?>
                                <li class="d-flex justify-content-between pt-16 border-top">
                                    <span class="h6 mb-0">Total Amount:</span>
                                    <span class="h5 mb-0 text-primary-600">₦<?php echo number_format($invoice['total_amount_ngn'], 2); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Subscription Details Card -->
                    <?php if ($subscription): ?>
                    <div class="card mt-24">
                        <div class="card-header border-bottom bg-base py-16 px-24">
                            <h6 class="text-lg fw-semibold mb-0">Subscription Details</h6>
                        </div>
                        <div class="card-body p-24">
                            <ul class="list-unstyled">
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Plan:</span>
                                    <span class="fw-semibold"><?php echo htmlspecialchars($subscription['plan_name']); ?></span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Billing Cycle:</span>
                                    <span class="fw-semibold"><?php echo ucfirst($subscription['billing_cycle']); ?></span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Amount:</span>
                                    <span class="fw-semibold">₦<?php echo number_format($subscription['amount_ngn'], 2); ?>/<?php echo $subscription['billing_cycle'] == 'yearly' ? 'year' : 'month'; ?></span>
                                </li>
                                <li class="d-flex justify-content-between mb-16">
                                    <span class="text-secondary-light">Period Start:</span>
                                    <span class="fw-semibold"><?php echo date('M d, Y', strtotime($subscription['current_period_start'])); ?></span>
                                </li>
                                <li class="d-flex justify-content-between">
                                    <span class="text-secondary-light">Period End:</span>
                                    <span class="fw-semibold"><?php echo date('M d, Y', strtotime($subscription['current_period_end'])); ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="card mt-24 no-print">
                        <div class="card-body p-24">
                            <?php if ($invoice['status'] == 'pending'): ?>
                            <button type="button" class="btn btn-success-600 w-100 mb-12" data-bs-toggle="modal" data-bs-target="#payInvoiceModal">
                                <i class="ri-bank-card-line me-2"></i>
                                Pay Now
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($invoice['status'] == 'paid'): ?>
                            <button type="button" class="btn btn-primary-600 w-100 mb-12" onclick="downloadReceipt('<?php echo $invoice['id']; ?>')">
                                <i class="ri-download-line me-2"></i>
                                Download Receipt
                            </button>
                            <?php endif; ?>
                            
                            <a href="subscription-plan.php" class="btn btn-outline-secondary w-100">
                                <i class="ri-arrow-left-line me-2"></i>
                                Back to Subscriptions
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-footer no-print">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Pay Invoice Modal -->
    <div class="modal fade" id="payInvoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pay Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="subscription-plan.php">
                    <div class="modal-body">
                        <input type="hidden" name="invoice_id" value="<?php echo $invoice['id']; ?>">
                        
                        <div class="text-center mb-24">
                            <h5 class="mb-8">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></h5>
                            <div class="display-6 fw-bold text-primary-600">
                                ₦<?php echo number_format($invoice['total_amount_ngn'], 2); ?>
                            </div>
                        </div>
                        
                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <i class="ri-information-line fs-5"></i>
                            <span>You'll be redirected to the payment gateway to complete this transaction.</span>
                        </div>
                        
                        <div class="mb-16">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-control form-select" name="payment_gateway_id" required>
                                <option value="">Select payment method</option>
                                <?php 
                                // Fetch payment gateways for the modal
                                try {
                                    $gatewayStmt = $platformDb->prepare("SELECT * FROM payment_gateways WHERE (school_id = ? OR school_id IS NULL) AND is_active = 1 ORDER BY is_default DESC");
                                    $gatewayStmt->execute([$school['id']]);
                                    $gateways = $gatewayStmt->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($gateways as $gateway): 
                                ?>
                                <option value="<?php echo $gateway['id']; ?>" <?php echo $gateway['is_default'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gateway['name']); ?> (<?php echo ucfirst($gateway['provider']); ?>)
                                </option>
                                <?php 
                                    endforeach;
                                } catch (Exception $e) {
                                    error_log("Error fetching gateways for modal: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="pay_invoice" class="btn btn-success-600">Proceed to Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // Download Receipt function
            window.downloadReceipt = function(invoiceId) {
                window.location.href = 'download-receipt.php?id=' + invoiceId;
            };
            
            // Auto-dismiss alerts
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);
        });
    </script>
</body>
</html>
