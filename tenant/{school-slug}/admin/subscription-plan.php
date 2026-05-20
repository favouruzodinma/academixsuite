<?php

/**
 * School Subscription Management Page
 * Fetches real data from platform database tables: plans, subscriptions, invoices, etc.
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_subscription.log');

error_log("=== SCHOOL SUBSCRIPTION PAGE START ===");

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

// Exchange rate (USD to NGN)
define('EXCHANGE_RATE', 1500);
define('ANNUAL_DISCOUNT', 0.15); // 15% discount

// Initialize variables
$currentSubscription = null;
$availablePlans = [];
$invoices = [];
$paymentGateways = [];
$subscriptionHistory = [];
$usageStats = [
    'students' => 0,
    'teachers' => 0,
    'storage' => 0
];
$adminUser = [
    'name' => 'Administrator',
    'role_name' => 'Admin'
];
$message = '';
$error = '';
$success = false;

// Fetch all active plans from platform database
try {
    $stmt = $platformDb->query("
        SELECT * FROM plans 
        WHERE is_active = 1 
        ORDER BY sort_order, price_monthly ASC
    ");
    $plansData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($plansData as $plan) {
        // Parse features JSON
        $features = [];
        if (!empty($plan['features'])) {
            $features = json_decode($plan['features'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $features = [];
            }
        }
        $plan['features_array'] = $features;
        
        // Convert prices to NGN
        $plan['price_monthly_ngn'] = $plan['price_monthly'] * EXCHANGE_RATE;
        $plan['price_yearly_ngn'] = ($plan['price_yearly'] > 0) 
            ? $plan['price_yearly'] * EXCHANGE_RATE 
            : ($plan['price_monthly'] * 12 * (1 - ANNUAL_DISCOUNT)) * EXCHANGE_RATE;
        
        $availablePlans[$plan['id']] = $plan;
    }
    
    error_log("Fetched " . count($availablePlans) . " active plans");
} catch (Exception $e) {
    error_log("Error fetching plans: " . $e->getMessage());
}

// Fetch school's current subscription
try {
    // Get subscription from platform database
    $subStmt = $platformDb->prepare("
        SELECT s.*, p.name as plan_name, p.description as plan_description,
               p.student_limit, p.teacher_limit, p.campus_limit, p.storage_limit,
               p.features as plan_features, p.price_monthly, p.price_yearly
        FROM subscriptions s
        JOIN plans p ON s.plan_id = p.id
        WHERE s.school_id = ?
        ORDER BY s.id DESC LIMIT 1
    ");
    $subStmt->execute([$school['id']]);
    $currentSubscription = $subStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($currentSubscription) {
        // Parse features
        if (!empty($currentSubscription['plan_features'])) {
            $features = json_decode($currentSubscription['plan_features'], true);
            $currentSubscription['features'] = json_last_error() === JSON_ERROR_NONE ? $features : [];
        }
        
        // Convert to NGN
        $currentSubscription['amount_ngn'] = $currentSubscription['amount'] * EXCHANGE_RATE;
        $currentSubscription['price_monthly_ngn'] = $currentSubscription['price_monthly'] * EXCHANGE_RATE;
        $currentSubscription['price_yearly_ngn'] = ($currentSubscription['price_yearly'] > 0) 
            ? $currentSubscription['price_yearly'] * EXCHANGE_RATE 
            : ($currentSubscription['price_monthly'] * 12 * (1 - ANNUAL_DISCOUNT)) * EXCHANGE_RATE;
    }
} catch (Exception $e) {
    error_log("Error fetching subscription: " . $e->getMessage());
}

// Fetch invoices
try {
    $invoiceStmt = $platformDb->prepare("
        SELECT * FROM invoices 
        WHERE school_id = ? 
        ORDER BY created_at DESC
    ");
    $invoiceStmt->execute([$school['id']]);
    $invoices = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to NGN
    foreach ($invoices as &$invoice) {
        $invoice['amount_ngn'] = ($invoice['amount'] ?? 0) * EXCHANGE_RATE;
        $invoice['total_amount_ngn'] = ($invoice['total_amount'] ?? $invoice['amount'] ?? 0) * EXCHANGE_RATE;
    }
} catch (Exception $e) {
    error_log("Error fetching invoices: " . $e->getMessage());
}

// Fetch payment gateways
try {
    $methodsStmt = $platformDb->prepare("
        SELECT * FROM payment_gateways 
        WHERE (school_id = ? OR school_id IS NULL) AND is_active = 1
        ORDER BY is_default DESC, created_at DESC
    ");
    $methodsStmt->execute([$school['id']]);
    $paymentGateways = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching payment methods: " . $e->getMessage());
}

// Fetch subscription history from audit logs
try {
    $historyStmt = $platformDb->prepare("
        SELECT * FROM audit_logs 
        WHERE school_id = ? AND event LIKE '%subscription%'
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $historyStmt->execute([$school['id']]);
    $auditLogs = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($auditLogs as $log) {
        $newValues = json_decode($log['new_values'], true);
        if ($newValues) {
            $subscriptionHistory[] = [
                'created_at' => $log['created_at'],
                'plan_name' => $newValues['plan_name'] ?? 'Unknown Plan',
                'billing_cycle' => $newValues['billing_cycle'] ?? 'monthly',
                'amount' => ($newValues['amount'] ?? 0) * EXCHANGE_RATE,
                'user_name' => 'System'
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching audit logs: " . $e->getMessage());
}

// Fetch usage stats from school database
if ($schoolDb) {
    try {
        // Student count
        $studentStmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
        $studentStmt->execute();
        $usageStats['students'] = $studentStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        // Teacher count
        $teacherStmt = $schoolDb->prepare("SELECT COUNT(*) as count FROM teachers WHERE status = 'active'");
        $teacherStmt->execute();
        $usageStats['teachers'] = $teacherStmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
        
        // Storage used
        try {
            $storageStmt = $schoolDb->prepare("SELECT COALESCE(SUM(file_size), 0) as total FROM file_storage");
            $storageStmt->execute();
            $usageStats['storage'] = $storageStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        } catch (Exception $e) {
            $usageStats['storage'] = 0;
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

// Handle plan change request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_plan'])) {
    $newPlanId = $_POST['plan_id'] ?? '';
    $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
    
    if (isset($availablePlans[$newPlanId])) {
        try {
            $platformDb->beginTransaction();
            
            $newPlan = $availablePlans[$newPlanId];
            $amount = ($billingCycle === 'yearly' && $newPlan['price_yearly'] > 0) 
                ? $newPlan['price_yearly'] 
                : $newPlan['price_monthly'];
            
            $periodStart = date('Y-m-d H:i:s');
            $periodEnd = date('Y-m-d H:i:s', strtotime('+' . ($billingCycle === 'yearly' ? '1 year' : '1 month')));
            $trialEndsAt = null;
            
            // Check if this is a trial plan (price 0)
            if ($newPlan['price_monthly'] == 0) {
                $trialEndsAt = date('Y-m-d H:i:s', strtotime('+14 days'));
            }
            
            // Update existing subscription or create new one
            if ($currentSubscription) {
                $updateStmt = $platformDb->prepare("
                    UPDATE subscriptions SET
                        plan_id = ?,
                        status = 'active',
                        billing_cycle = ?,
                        amount = ?,
                        current_period_start = ?,
                        current_period_end = ?,
                        trial_ends_at = ?
                    WHERE school_id = ? AND id = ?
                ");
                $updateStmt->execute([
                    $newPlanId,
                    $billingCycle,
                    $amount,
                    $periodStart,
                    $periodEnd,
                    $trialEndsAt,
                    $school['id'],
                    $currentSubscription['id']
                ]);
                $subscriptionId = $currentSubscription['id'];
            } else {
                $insertStmt = $platformDb->prepare("
                    INSERT INTO subscriptions (
                        school_id, plan_id, status, billing_cycle,
                        amount, currency, current_period_start, current_period_end,
                        trial_ends_at, created_at
                    ) VALUES (?, ?, 'active', ?, ?, 'NGN', ?, ?, ?, NOW())
                ");
                $insertStmt->execute([
                    $school['id'],
                    $newPlanId,
                    $billingCycle,
                    $amount,
                    $periodStart,
                    $periodEnd,
                    $trialEndsAt
                ]);
                $subscriptionId = $platformDb->lastInsertId();
            }
            
            // Generate invoice
            $dueDate = date('Y-m-d', strtotime('+7 days'));
            $notes = "Subscription for {$newPlan['name']} plan ({$billingCycle})";
            
            $invoiceStmt = $platformDb->prepare("
                INSERT INTO invoices (
                    school_id, subscription_id, description, amount,
                    total_amount, currency, status, due_date, notes,
                    start_date, end_date, is_trial, created_at
                ) VALUES (?, ?, ?, ?, ?, 'NGN', 'pending', ?, ?, NOW(), ?, ?, NOW())
            ");
            
            $isTrial = ($newPlan['price_monthly'] == 0) ? 1 : 0;
            $invoiceStmt->execute([
                $school['id'],
                $subscriptionId,
                $notes,
                $amount,
                $amount,
                $dueDate,
                $notes,
                $periodStart,
                $periodEnd,
                $isTrial
            ]);
            
            // Create audit log
            $auditStmt = $platformDb->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, event, auditable_type,
                    auditable_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'subscription_change', 'subscriptions', ?, ?, ?, ?, NOW())
            ");
            
            $newValues = json_encode([
                'plan_id' => $newPlanId,
                'plan_name' => $newPlan['name'],
                'billing_cycle' => $billingCycle,
                'amount' => $amount
            ]);
            
            $auditStmt->execute([
                $school['id'],
                $userId,
                'admin',
                $subscriptionId,
                $newValues,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $platformDb->commit();
            $success = true;
            $message = "Plan changed successfully! New invoice has been generated.";
            
            // Refresh subscription data
            $subStmt->execute([$school['id']]);
            $currentSubscription = $subStmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            if ($platformDb->inTransaction()) {
                $platformDb->rollBack();
            }
            $error = "Failed to change plan: " . $e->getMessage();
            error_log("Plan change error: " . $e->getMessage());
        }
    } else {
        $error = "Invalid plan selected.";
    }
}

// Handle invoice payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_invoice'])) {
    $invoiceId = $_POST['invoice_id'] ?? 0;
    $gatewayId = $_POST['payment_gateway_id'] ?? null;
    
    if ($invoiceId) {
        try {
            $platformDb->beginTransaction();
            
            // Update invoice status
            $updateStmt = $platformDb->prepare("
                UPDATE invoices SET 
                    status = 'paid',
                    payment_status = 'success',
                    paid_at = NOW(),
                    payment_gateway_id = ?
                WHERE id = ? AND school_id = ? AND status = 'pending'
            ");
            $updateStmt->execute([$gatewayId, $invoiceId, $school['id']]);
            
            if ($updateStmt->rowCount() > 0) {
                // Create payment record
                $paymentStmt = $platformDb->prepare("
                    INSERT INTO payments (
                        invoice_id, school_id, amount, payment_method,
                        transaction_id, status, paid_at, created_at
                    ) SELECT 
                        id, school_id, total_amount, 'online',
                        CONCAT('TXN', FLOOR(RAND() * 1000000)), 'completed', NOW(), NOW()
                    FROM invoices WHERE id = ? AND school_id = ?
                ");
                $paymentStmt->execute([$invoiceId, $school['id']]);
                
                $platformDb->commit();
                $success = true;
                $message = "Payment successful! Invoice has been marked as paid.";
            } else {
                throw new Exception("Invoice not found or already paid");
            }
        } catch (Exception $e) {
            if ($platformDb->inTransaction()) {
                $platformDb->rollBack();
            }
            $error = "Payment failed: " . $e->getMessage();
            error_log("Payment error: " . $e->getMessage());
        }
    }
}

// Set current plan details
$currentPlanId = $currentSubscription['plan_id'] ?? null;
$currentPlan = null;
if ($currentPlanId && isset($availablePlans[$currentPlanId])) {
    $currentPlan = $availablePlans[$currentPlanId];
}

$billingCycle = $currentSubscription['billing_cycle'] ?? 'monthly';
$currentPrice = $currentSubscription['amount'] ?? 0;
$currentPriceNgn = $currentSubscription['amount_ngn'] ?? 0;

// Calculate days left in period
$daysLeft = 0;
$periodEndDate = null;
$periodStartDate = null;
if (!empty($currentSubscription['current_period_end'])) {
    $endDate = new DateTime($currentSubscription['current_period_end']);
    $today = new DateTime();
    $daysLeft = max(0, $today->diff($endDate)->days);
    $periodEndDate = $endDate->format('M d, Y');
}
if (!empty($currentSubscription['current_period_start'])) {
    $startDate = new DateTime($currentSubscription['current_period_start']);
    $periodStartDate = $startDate->format('M d, Y');
}

// Check if on trial
$isTrial = !empty($currentSubscription['trial_ends_at']) && 
           strtotime($currentSubscription['trial_ends_at']) > time();

$trialEndsAt = !empty($currentSubscription['trial_ends_at']) 
    ? date('M d, Y', strtotime($currentSubscription['trial_ends_at'])) 
    : null;

// Helper functions
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function formatLimit($limit) {
    if ($limit == 0) return 'Unlimited';
    return number_format($limit);
}

function getStatusBadge($status) {
    $badges = [
        'paid' => 'success',
        'pending' => 'warning',
        'overdue' => 'danger',
        'draft' => 'secondary',
        'active' => 'success',
        'trial' => 'info',
        'cancelled' => 'secondary',
        'past_due' => 'danger'
    ];
    return $badges[$status] ?? 'secondary';
}

error_log("=== SCHOOL SUBSCRIPTION PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Manage your school subscription plan and billing">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Plan - <?php echo htmlspecialchars($school['name']); ?></title>
    
    <!-- Your exact styles -->
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>
    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Your exact sidebar -->
    <aside class="sidebar">
        <button type="button" class="sidebar-close-btn">
            <iconify-icon icon="radix-icons:cross-2"></iconify-icon>
        </button>
        <div class="">
            <div class="sidebar-logo d-flex align-items-center justify-content-between">
                <a href="index" class="">
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
                        <?php
                        $avatarPath = $adminUser['avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/leave-request-img2.png';
                        ?>
                        <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Thumbnail" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                        <span class="profile-dropdown__contents">
                            <span class="h6 mb-0 text-md d-block text-primary-light"><?php echo htmlspecialchars($adminUser['name'] ?? 'Admin User'); ?></span>
                            <span class="text-secondary-light text-sm mb-0 d-block"><?php echo htmlspecialchars($adminUser['role_name'] ?? 'Administrator'); ?></span>
                        </span>
                    </span>
                    <span class="profile-dropdown__icon pe-8 text-xl d-flex line-height-1">
                        <i class="ri-arrow-right-s-line"></i>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                    <li>
                        <a href="profile.php" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                            <i class="ri-user-3-line"></i>
                            My Profile
                        </a>
                    </li>
                    <li>
                        <a href="settings.php" class="dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6">
                            <i class="ri-settings-3-line"></i>
                            Settings
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
                <li class="dropdown">
                    <a href="index.php">
                        <i class="ri-home-4-line"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-graduation-cap-line"></i>
                        <span>Students</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="add-new-student.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Add New Student
                            </a>
                        </li>
                        <li>
                            <a href="student-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Student List
                            </a>
                        </li>
                        <li>
                            <a href="student-category.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Student Categories
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-user-follow-line"></i>
                        <span>Teachers</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="add-new-teacher.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Add New Teacher
                            </a>
                        </li>
                        <li>
                            <a href="teacher-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Teacher List
                            </a>
                        </li>
                        <li>
                            <a href="teacher-timetable.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Teacher Timetable
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-list-view"></i>
                        <span>Classes</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="class-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Class List
                            </a>
                        </li>
                        <li>
                            <a href="subject-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Subjects
                            </a>
                        </li>
                        <li>
                            <a href="section-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Sections
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-file-edit-line"></i>
                        <span>Examinations</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="exam-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Exams
                            </a>
                        </li>
                        <li>
                            <a href="exam-schedule.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Exam Schedule
                            </a>
                        </li>
                        <li>
                            <a href="exam-result.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Exam Results
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-money-dollar-circle-line"></i>
                        <span>Fees Collection</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="fees-collect.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Fees Collect
                            </a>
                        </li>
                        <li>
                            <a href="fees-type.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Fees Type
                            </a>
                        </li>
                        <li>
                            <a href="fees-group.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Fees Group
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-calendar-check-line"></i>
                        <span>Attendance</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="student-attendance.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Student Attendance
                            </a>
                        </li>
                        <li>
                            <a href="teacher-attendance.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Teacher Attendance
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-time-line"></i>
                        <span>Leaves</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="leave-types.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Leave Types
                            </a>
                        </li>
                        <li>
                            <a href="leave-requests.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Leave Requests
                            </a>
                        </li>
                    </ul>
                </li>
                <li>
                    <a href="notice-board.php">
                        <i class="ri-booklet-line"></i>
                        <span>Notice Board</span>
                    </a>
                </li>
                <li>
                    <a href="event.php">
                        <i class="ri-calendar-event-line"></i>
                        <span>Events</span>
                    </a>
                </li>
                 <li>
                    <a href="certificate.php">
                        <i class="ri-home-4-line"></i>
                        <span>Certificate </span>
                    </a>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-book-2-line"></i>
                        <span>Library</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="books-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Books List
                            </a>
                        </li>
                        <li>
                            <a href="members-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Members List
                            </a>
                        </li>
                        <li>
                            <a href="member-details.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Members Details
                            </a>
                        </li>
                        <li>
                            <a href="issue-return.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Issue Return
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-money-dollar-circle-line"></i>
                        <span>Accounts</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="income-head.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Income Head
                            </a>
                        </li>
                        <li>
                            <a href="income-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Income List
                            </a>
                        </li>
                        <li>
                            <a href="expense-head.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Expense Head
                            </a>
                        </li>
                        <li>
                            <a href="expense-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Expense List
                            </a>
                        </li>
                        <li>
                            <a href="transaction.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Transaction
                            </a>
                        </li>
                    </ul>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-user-settings-line"></i>
                        <span>HRM</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="employee-list.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Employee List
                            </a>
                        </li>
                        <li>
                            <a href="employee-details.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Employee Details
                            </a>
                        </li>
                        <li>
                            <a href="add-new-employee.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Add New Employee
                            </a>
                        </li>
                        <li>
                            <a href="payroll.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Payroll
                            </a>
                        </li>
                        <li>
                            <a href="designation.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Designation
                            </a>
                        <li>
                            <a href="department.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Department
                            </a>
                        </li>
                    </ul>
                </li>
                <li>
                    <a href="notice-board.php">
                        <i class="ri-booklet-line"></i>
                        <span>Notice Board </span>
                    </a>
                </li>
                <li>
                    <a href="event.php">
                        <i class="ri-calendar-event-line"></i>
                        <span>Event </span>
                    </a>
                </li>
                <li>
                    <a href="message.php">
                        <i class="ri-message-2-line"></i>
                        <span>Message </span>
                    </a>
                </li>
                <li>
                    <a href="subscription-plan.php" class="active">
                        <i class="ri-price-tag-3-line"></i>
                        <span>Subscription Plan </span>
                    </a>
                </li>
                <li>
                    <a href="role-access.php">
                        <i class="ri-macbook-line"></i>
                        <span>Role & Access</span>
                    </a>
                </li>
                <li class="dropdown">
                    <a href="javascript:void(0)">
                        <i class="ri-user-settings-line"></i>
                        <span>Settings</span>
                    </a>
                    <ul class="sidebar-submenu">
                        <li>
                            <a href="general.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                General
                            </a>
                        </li>
                        <li>
                            <a href="notification.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Notification
                            </a>
                        </li>
                        <li>
                            <a href="currencies.php">
                                <i class="ri-circle-fill circle-icon w-auto"></i>
                                Currencies
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </aside>

    <main class="dashboard-main">
        <div class="navbar-header shadow-1">
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
                        <div class="dropdown">
                            <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <?php if (!empty($invoices) && count(array_filter($invoices, fn($i) => $i['status'] == 'pending')) > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Subscription Plan</h1>
                    <div class="">
                        <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light"> / Subscription Plan</span>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="ri-checkbox-circle-line me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="ri-error-warning-line me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Current Plan Summary -->
            <?php if ($currentPlan): ?>
            <div class="row mb-24">
                <div class="col-12">
                    <div class="card bg-primary-600 text-white">
                        <div class="card-body p-24">
                            <div class="row align-items-center">
                                <div class="col-lg-8">
                                    <div class="d-flex align-items-center gap-3 mb-16">
                                        <h4 class="text-white mb-0">Current Plan: <?php echo htmlspecialchars($currentPlan['name']); ?></h4>
                                        <?php if ($isTrial): ?>
                                        <span class="badge bg-warning-600 text-white">Trial</span>
                                        <?php endif; ?>
                                        <span class="badge bg-<?php echo getStatusBadge($currentSubscription['status'] ?? 'active'); ?>-600 text-white">
                                            <?php echo ucfirst($currentSubscription['status'] ?? 'active'); ?>
                                        </span>
                                    </div>
                                    <p class="text-white text-opacity-75 mb-24"><?php echo htmlspecialchars($currentPlan['description'] ?? ''); ?></p>
                                    
                                    <div class="d-flex flex-wrap gap-24">
                                        <div>
                                            <span class="text-white text-opacity-75 d-block mb-4">Billing Cycle</span>
                                            <h6 class="text-white mb-0"><?php echo ucfirst($billingCycle); ?></h6>
                                        </div>
                                        <div>
                                            <span class="text-white text-opacity-75 d-block mb-4">Amount</span>
                                            <h6 class="text-white mb-0">₦<?php echo number_format($currentPriceNgn, 2); ?>/<?php echo $billingCycle == 'yearly' ? 'year' : 'month'; ?></h6>
                                        </div>
                                        <?php if ($isTrial && $trialEndsAt): ?>
                                        <div>
                                            <span class="text-white text-opacity-75 d-block mb-4">Trial Ends</span>
                                            <h6 class="text-white mb-0"><?php echo $trialEndsAt; ?></h6>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($periodEndDate): ?>
                                        <div>
                                            <span class="text-white text-opacity-75 d-block mb-4">Period End</span>
                                            <h6 class="text-white mb-0">
                                                <?php echo $periodEndDate; ?>
                                                <?php if ($daysLeft > 0 && $daysLeft <= 7): ?>
                                                <span class="badge bg-warning-600 text-white ms-2"><?php echo $daysLeft; ?> days left</span>
                                                <?php endif; ?>
                                            </h6>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                                    <div class="d-flex flex-column align-items-lg-end">
                                        <span class="badge bg-white text-primary-600 px-24 py-8 radius-8 fs-6 mb-2">
                                            Plan ID: #<?php echo str_pad($currentPlan['id'], 4, '0', STR_PAD_LEFT); ?>
                                        </span>
                                        <?php if ($periodStartDate): ?>
                                        <span class="text-white text-opacity-75">Started: <?php echo $periodStartDate; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Usage Statistics -->
            <div class="row mb-24">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-primary-light mb-16">Student Usage</h6>
                            <div class="d-flex align-items-center justify-content-between mb-8">
                                <span class="text-secondary-light"><?php echo number_format($usageStats['students']); ?> / <?php echo formatLimit($currentPlan['student_limit'] ?? 0); ?></span>
                                <span class="text-primary-600 fw-semibold">
                                    <?php 
                                    $studentLimit = $currentPlan['student_limit'] ?? 0;
                                    if ($studentLimit > 0) {
                                        echo round(($usageStats['students'] / $studentLimit) * 100) . '%';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary-600" role="progressbar" 
                                     style="width: <?php echo $studentLimit > 0 ? min(100, ($usageStats['students'] / $studentLimit) * 100) : 0; ?>%;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-primary-light mb-16">Teacher Usage</h6>
                            <div class="d-flex align-items-center justify-content-between mb-8">
                                <span class="text-secondary-light"><?php echo number_format($usageStats['teachers']); ?> / <?php echo formatLimit($currentPlan['teacher_limit'] ?? 0); ?></span>
                                <span class="text-primary-600 fw-semibold">
                                    <?php 
                                    $teacherLimit = $currentPlan['teacher_limit'] ?? 0;
                                    if ($teacherLimit > 0) {
                                        echo round(($usageStats['teachers'] / $teacherLimit) * 100) . '%';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary-600" role="progressbar" 
                                     style="width: <?php echo $teacherLimit > 0 ? min(100, ($usageStats['teachers'] / $teacherLimit) * 100) : 0; ?>%;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="text-primary-light mb-16">Storage Usage</h6>
                            <div class="d-flex align-items-center justify-content-between mb-8">
                                <span class="text-secondary-light"><?php echo formatBytes($usageStats['storage']); ?> / <?php echo formatBytes(($currentPlan['storage_limit'] ?? 0) * 1024 * 1024); ?></span>
                                <span class="text-primary-600 fw-semibold">
                                    <?php 
                                    $storageLimit = ($currentPlan['storage_limit'] ?? 0) * 1024 * 1024;
                                    if ($storageLimit > 0) {
                                        echo round(($usageStats['storage'] / $storageLimit) * 100) . '%';
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary-600" role="progressbar" 
                                     style="width: <?php echo $storageLimit > 0 ? min(100, ($usageStats['storage'] / $storageLimit) * 100) : 0; ?>%;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Available Plans -->
            <div class="card h-100 p-0 radius-12 overflow-hidden mt-24">
                <div class="card-body p-40">
                    <div class="row justify-content-center">
                        <div class="col-xxl-12">
                            <div class="text-center">
                                <h4 class="mb-0">Available Plans</h4>
                                <p class="text-secondary-light mt-8">Choose the plan that best fits your school's needs</p>
                            </div>

                            <div class="pricing-tab">
                                <div class="form-switch switch-primary d-flex align-items-center gap-3 mt-28 justify-content-center">
                                    <label class="form-check-label line-height-1 fw-medium text-secondary-light" for="billingToggle">Monthly</label>
                                    <input class="form-check-input choose-plan-input" type="checkbox" role="switch" id="billingToggle">
                                    <label class="form-check-label line-height-1 fw-medium text-secondary-light" for="billingToggle">Annually <span class="badge bg-success-100 text-success-600 ms-2">Save 15%</span></label>
                                </div>
                            </div>

                            <div class="row gy-4 mt-4">
                                <?php foreach ($availablePlans as $planId => $plan): ?>
                                <?php
                                    $isCurrentPlan = ($currentPlanId == $planId);
                                    $planFeatures = $plan['features_array'] ?? [];
                                    $yearlyPrice = $plan['price_yearly_ngn'];
                                    $monthlyPrice = $plan['price_monthly_ngn'];
                                ?>
                                <div class="col-xxl-4 col-sm-6">
                                    <div class="pricing-plan position-relative radius-24 overflow-hidden border <?php echo $isCurrentPlan ? 'border-primary-600 border-2' : 'bg-base'; ?>">
                                        <?php if ($planId == 2): // Professional plan ?>
                                        <span class="bg-primary-600 text-white radius-24 py-8 px-24 text-sm position-absolute end-0 top-0 z-1 rounded-start-top-0 rounded-end-bottom-0">Most Popular</span>
                                        <?php endif; ?>
                                        
                                        <?php if ($isCurrentPlan): ?>
                                        <span class="bg-success-600 text-white radius-24 py-8 px-24 text-sm position-absolute start-0 top-0 z-1 rounded-start-bottom-0 rounded-end-top-0">Current Plan</span>
                                        <?php endif; ?>
                                        
                                        <div class="p-24">
                                            <div class="d-flex align-items-center gap-16 mb-20">
                                                <span class="w-72-px h-72-px d-flex justify-content-center align-items-center radius-16 bg-primary-50">
                                                    <img src="https://academixsuite.com/tenant/assets/images/icons/price-icon<?php echo $planId; ?>.png" alt="<?php echo $plan['name']; ?>">
                                                </span>
                                                <div class="">
                                                    <span class="fw-medium text-md text-secondary-light">For <?php echo $plan['description']; ?></span>
                                                    <h6 class="mb-0"><?php echo $plan['name']; ?></h6>
                                                </div>
                                            </div>
                                            
                                            <h3 class="mb-8">
                                                <span class="price-range" data-monthly="<?php echo $monthlyPrice; ?>" data-yearly="<?php echo $yearlyPrice; ?>">
                                                    ₦<?php echo number_format($monthlyPrice, 0); ?>
                                                </span>
                                                <span class="fw-medium text-md text-secondary-light">/month</span>
                                            </h3>
                                            
                                            <p class="mb-20 text-secondary-light"><?php echo htmlspecialchars($plan['description'] ?? ''); ?></p>
                                            
                                            <span class="mb-16 fw-medium d-block">What's included:</span>
                                            <ul class="mb-24">
                                                <?php foreach (array_slice($planFeatures, 0, 5) as $feature): ?>
                                                <li class="d-flex align-items-center gap-12 mb-12">
                                                    <span class="w-20-px h-20-px d-flex justify-content-center align-items-center bg-primary-600 rounded-circle flex-shrink-0">
                                                        <i class="ri-check-line text-white text-sm"></i>
                                                    </span>
                                                    <span class="text-secondary-light text-md"><?php echo htmlspecialchars($feature); ?></span>
                                                </li>
                                                <?php endforeach; ?>
                                                <?php if (count($planFeatures) > 5): ?>
                                                <li class="text-primary-600 text-sm mt-8">+<?php echo count($planFeatures) - 5; ?> more features</li>
                                                <?php endif; ?>
                                            </ul>
                                            
                                            <div class="mb-16">
                                                <div class="d-flex justify-content-between text-sm mb-8">
                                                    <span class="text-secondary-light">Student Limit:</span>
                                                    <span class="fw-semibold"><?php echo formatLimit($plan['student_limit']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between text-sm mb-8">
                                                    <span class="text-secondary-light">Teacher Limit:</span>
                                                    <span class="fw-semibold"><?php echo formatLimit($plan['teacher_limit']); ?></span>
                                                </div>
                                                <div class="d-flex justify-content-between text-sm">
                                                    <span class="text-secondary-light">Storage:</span>
                                                    <span class="fw-semibold"><?php echo formatBytes(($plan['storage_limit'] ?? 0) * 1024 * 1024); ?></span>
                                                </div>
                                            </div>
                                            
                                            <?php if ($isCurrentPlan): ?>
                                            <button type="button" class="bg-neutral-200 text-secondary-light text-center border border-neutral-300 text-sm btn-sm px-12 py-10 w-100 radius-8" disabled>
                                                Current Plan
                                            </button>
                                            <?php else: ?>
                                            <button type="button" 
                                                    class="bg-primary-600 bg-hover-primary-700 text-white text-center border border-primary-600 text-sm btn-sm px-12 py-10 w-100 radius-8"
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#changePlanModal"
                                                    data-plan-id="<?php echo $plan['id']; ?>"
                                                    data-plan-name="<?php echo $plan['name']; ?>"
                                                    data-plan-price-monthly="<?php echo $monthlyPrice; ?>"
                                                    data-plan-price-yearly="<?php echo $yearlyPrice; ?>">
                                                <?php echo ($currentPriceNgn > $monthlyPrice) ? 'Downgrade' : 'Upgrade'; ?> to <?php echo $plan['name']; ?>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="card mt-24" id="invoices">
                <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                    <h6 class="text-lg fw-semibold mb-0">Billing History & Invoices</h6>
                    <div>
                        <button type="button" class="btn btn-primary-600 btn-sm" onclick="window.print()">
                            <i class="ri-printer-line"></i> Print
                        </button>
                    </div>
                </div>
                <div class="card-body p-24">
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0" id="invoicesTable">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($invoices)): ?>
                                    <?php foreach ($invoices as $invoice): ?>
                                    <tr>
                                        <td>
                                            <span class="text-primary-600"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($invoice['notes'] ?? $invoice['description'] ?? 'Subscription Payment'); ?></td>
                                        <td class="fw-semibold">₦<?php echo number_format($invoice['total_amount_ngn'] ?? $invoice['amount_ngn'] ?? 0, 2); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                            <?php if (strtotime($invoice['due_date']) < time() && $invoice['status'] == 'pending'): ?>
                                            <span class="badge bg-danger-600 text-white ms-2">Overdue</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo getStatusBadge($invoice['status']); ?>-100 text-<?php echo getStatusBadge($invoice['status']); ?>-600 px-24 py-4 radius-4 fw-medium">
                                                <?php echo ucfirst($invoice['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-8">
                                                <button type="button" class="btn btn-sm btn-outline-primary-600" onclick="viewInvoice('<?php echo $invoice['id']; ?>')">
                                                    <i class="ri-eye-line"></i>
                                                </button>
                                                <?php if ($invoice['status'] == 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-success-600" data-bs-toggle="modal" data-bs-target="#payInvoiceModal" data-invoice-id="<?php echo $invoice['id']; ?>" data-invoice-number="<?php echo $invoice['invoice_number']; ?>" data-amount="<?php echo $invoice['total_amount_ngn'] ?? $invoice['amount_ngn'] ?? 0; ?>">
                                                    <i class="ri-bank-card-line"></i>
                                                </button>
                                                <?php elseif ($invoice['status'] == 'paid'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary-600" onclick="downloadReceipt('<?php echo $invoice['id']; ?>')">
                                                    <i class="ri-download-line"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-20">
                                            <p class="text-secondary-light">No invoices found</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="card mt-24">
                <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                    <h6 class="text-lg fw-semibold mb-0">Payment Methods</h6>
                </div>
                <div class="card-body p-24">
                    <div class="row g-4">
                        <?php if (!empty($paymentGateways)): ?>
                            <?php foreach ($paymentGateways as $gateway): ?>
                            <div class="col-md-4">
                                <div class="border radius-12 p-16 <?php echo $gateway['is_default'] ? 'border-primary-600' : ''; ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-16">
                                        <div class="d-flex align-items-center gap-12">
                                            <?php 
                                            $icon = 'ri-bank-card-line';
                                            if ($gateway['provider'] == 'paystack') $icon = 'ri-secure-payment-line';
                                            elseif ($gateway['provider'] == 'flutterwave') $icon = 'ri-flashlight-line';
                                            elseif ($gateway['provider'] == 'stripe') $icon = 'ri-credit-card-line';
                                            elseif ($gateway['provider'] == 'paypal') $icon = 'ri-paypal-line';
                                            ?>
                                            <i class="<?php echo $icon; ?> fs-1 text-primary-600"></i>
                                            <div>
                                                <h6 class="mb-4"><?php echo htmlspecialchars($gateway['name']); ?></h6>
                                                <p class="text-secondary-light text-sm mb-0"><?php echo ucfirst($gateway['provider']); ?> - <?php echo ucfirst($gateway['mode']); ?> mode</p>
                                            </div>
                                        </div>
                                        <?php if ($gateway['is_default']): ?>
                                        <span class="badge bg-primary-600 text-white px-12 py-4">Default</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-center py-20">
                                <i class="ri-bank-card-line fs-1 text-secondary mb-3"></i>
                                <p class="text-secondary-light">No payment methods configured</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Subscription History -->
            <?php if (!empty($subscriptionHistory)): ?>
            <div class="card mt-24">
                <div class="card-header border-bottom bg-base py-16 px-24">
                    <h6 class="text-lg fw-semibold mb-0">Subscription History</h6>
                </div>
                <div class="card-body p-24">
                    <div class="timeline-vertical">
                        <?php foreach ($subscriptionHistory as $history): ?>
                        <div class="timeline-item d-flex gap-16 mb-24">
                            <div class="timeline-dot bg-primary-600 rounded-circle mt-8" style="width: 12px; height: 12px;"></div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center justify-content-between mb-4">
                                    <h6 class="mb-0">Changed to <?php echo htmlspecialchars($history['plan_name']); ?> Plan</h6>
                                    <span class="text-secondary-light text-sm"><?php echo date('M d, Y H:i', strtotime($history['created_at'])); ?></span>
                                </div>
                                <p class="text-secondary-light mb-0">
                                    <i class="ri-calendar-line me-1"></i> Billing: <?php echo ucfirst($history['billing_cycle']); ?> | 
                                    <i class="ri-money-dollar-circle-line me-1"></i> Amount: ₦<?php echo number_format($history['amount'], 2); ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Change Plan Modal -->
    <div class="modal fade" id="changePlanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Subscription Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="plan_id" id="modalPlanId">
                        <input type="hidden" name="billing_cycle" id="modalBillingCycle" value="monthly">
                        
                        <div class="text-center mb-24">
                            <h3 id="modalPlanName" class="mb-8"></h3>
                            <div class="d-flex align-items-center justify-content-center gap-16">
                                <span class="display-6 fw-bold" id="modalPlanPrice"></span>
                                <span class="text-secondary-light" id="modalPlanPeriod"></span>
                            </div>
                        </div>
                        
                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <i class="ri-information-line fs-5"></i>
                            <span>Changing your plan will take effect immediately. A new invoice will be generated.</span>
                        </div>
                        
                        <div class="mb-16">
                            <label class="form-label fw-semibold">Billing Cycle</label>
                            <div class="d-flex gap-16">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="cycle" id="cycleMonthly" value="monthly" checked>
                                    <label class="form-check-label" for="cycleMonthly">Monthly</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="cycle" id="cycleYearly" value="yearly">
                                    <label class="form-check-label" for="cycleYearly">Annually (Save 15%)</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-16">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-control form-select" name="payment_gateway_id" required>
                                <option value="">Select payment method</option>
                                <?php foreach ($paymentGateways as $gateway): ?>
                                <option value="<?php echo $gateway['id']; ?>" <?php echo $gateway['is_default'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gateway['name']); ?> (<?php echo ucfirst($gateway['provider']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="change_plan" class="btn btn-primary-600">Confirm Change</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Pay Invoice Modal -->
    <div class="modal fade" id="payInvoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pay Invoice</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="invoice_id" id="payInvoiceId">
                        
                        <div class="text-center mb-24">
                            <h5 id="payInvoiceNumber" class="mb-8"></h5>
                            <div class="display-6 fw-bold text-primary-600" id="payInvoiceAmount"></div>
                        </div>
                        
                        <div class="mb-16">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select class="form-control form-select" name="payment_gateway_id" required>
                                <option value="">Select payment method</option>
                                <?php foreach ($paymentGateways as $gateway): ?>
                                <option value="<?php echo $gateway['id']; ?>">
                                    <?php echo htmlspecialchars($gateway['name']); ?> (<?php echo ucfirst($gateway['provider']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="pay_invoice" class="btn btn-success-600">Pay Now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Your exact scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#invoicesTable').DataTable({
                pageLength: 10,
                ordering: true,
                order: [[1, 'desc']],
                language: {
                    search: "Search invoices:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ invoices"
                }
            });
            
            // Billing toggle functionality
            let billingToggle = document.getElementById('billingToggle');
            let priceRanges = document.querySelectorAll('.price-range');
            
            function updatePrices(isYearly) {
                priceRanges.forEach(price => {
                    let value = isYearly ? price.dataset.yearly : price.dataset.monthly;
                    price.innerHTML = '₦' + parseFloat(value).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0});
                });
                
                // Update period text
                document.querySelectorAll('.price-range + span').forEach(span => {
                    span.textContent = isYearly ? '/year' : '/month';
                });
            }
            
            if (billingToggle) {
                billingToggle.addEventListener('change', function() {
                    updatePrices(this.checked);
                });
            }
            
            // Change Plan Modal
            $('#changePlanModal').on('show.bs.modal', function(event) {
                let button = $(event.relatedTarget);
                let planId = button.data('plan-id');
                let planName = button.data('plan-name');
                let priceMonthly = button.data('plan-price-monthly');
                let priceYearly = button.data('plan-price-yearly');
                
                let modal = $(this);
                modal.find('#modalPlanId').val(planId);
                modal.find('#modalPlanName').text(planName + ' Plan');
                
                let isYearly = billingToggle ? billingToggle.checked : false;
                let price = isYearly ? priceYearly : priceMonthly;
                let period = isYearly ? '/year' : '/month';
                
                modal.find('#modalPlanPrice').text('₦' + parseFloat(price).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}));
                modal.find('#modalPlanPeriod').text(period);
            });
            
            // Modal cycle radio change
            $('input[name="cycle"]').change(function() {
                let isYearly = $(this).val() === 'yearly';
                $('#modalBillingCycle').val(isYearly ? 'yearly' : 'monthly');
                
                let planId = $('#modalPlanId').val();
                let planBtn = $(`.change-plan-btn[data-plan-id="${planId}"]`);
                
                if (planBtn.length) {
                    let price = isYearly ? 
                        planBtn.data('plan-price-yearly') : 
                        planBtn.data('plan-price-monthly');
                    
                    $('#modalPlanPrice').text('₦' + parseFloat(price).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 0}));
                    $('#modalPlanPeriod').text(isYearly ? '/year' : '/month');
                }
            });
            
            // Pay Invoice Modal
            $('#payInvoiceModal').on('show.bs.modal', function(event) {
                let button = $(event.relatedTarget);
                let invoiceId = button.data('invoice-id');
                let invoiceNumber = button.data('invoice-number');
                let amount = button.data('amount');
                
                let modal = $(this);
                modal.find('#payInvoiceId').val(invoiceId);
                modal.find('#payInvoiceNumber').text('Invoice #' + invoiceNumber);
                modal.find('#payInvoiceAmount').text('₦' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            });
            
            // View Invoice function
            window.viewInvoice = function(invoiceId) {
                window.location.href = 'invoice-details.php?id=' + invoiceId;
            };
            
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