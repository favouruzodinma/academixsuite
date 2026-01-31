<?php

/**
 * School Admin Dashboard - VIRTUAL VERSION
 * This file serves ALL schools via virtual-router.php
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_dashboard.log');

error_log("=== VIRTUAL DASHBOARD START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants if not defined
if (!defined('APP_NAME')) define('APP_NAME', 'AcademixSuite');
if (!defined('IS_LOCAL')) define('IS_LOCAL', true);

// Start session safely
try {
    if (session_status() === PHP_SESSION_NONE) {
        error_log("Starting session...");
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
        error_log("Session started successfully");
        error_log("Session ID: " . session_id());
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'dashboard.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

error_log("School Slug from Router: " . $schoolSlug);
error_log("User Type: " . $userType);
error_log("Current Page: " . $currentPage);

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
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

error_log("School: ID=" . $school['id'] . ", Name=" . $school['name'] . ", Status=" . $school['status']);

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if ($_SESSION['school_auth']['school_slug'] === $schoolSlug) {
        $isAuthenticated = true;
        error_log("User authenticated for school: " . $schoolSlug);
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = $schoolAuth['user_id'] ?? 0;
$userType = $schoolAuth['user_type'] ?? '';

error_log("User ID: " . $userId . ", User Type: " . $userType);

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    error_log("Loading autoload.php from: " . $autoloadPath);

    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }

    require_once $autoloadPath;
    error_log("Autoload loaded successfully");

    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        error_log("Connecting to school database: " . $school['database_name']);
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    } else {
        error_log("WARNING: School database name is empty");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize variables with safe defaults
$settings = [];
$academicYear = null;
$academicTerm = null;
$totalStudents = 0;
$totalTeachers = 0;
$totalClasses = 0;
$totalSubjects = 0;
$attendanceRate = 0;
$announcements = [];
$upcomingEvents = [];
$recentActivities = [];
$gradeDistribution = [];
$weeklyAttendance = [];
$feeCollectionRate = 0;
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

// Revenue variables
$totalRevenue = 0;
$monthlyRevenue = 0;
$pendingPayments = 0;
$collectionRate = 0;
$recentTransactions = [];
$monthlyRevenueData = [];
$paymentMethodsData = [];

// Check if we have a valid school database connection before querying
if ($schoolDb) {
    try {
        // Get school settings
        error_log("Fetching school settings...");
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'settings'")->fetch();
            if ($tableCheck) {
                $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
                if ($settingsStmt) {
                    $settingsStmt->execute([$school['id']]);
                    $settingsRows = $settingsStmt->fetchAll();
                    foreach ($settingsRows as $row) {
                        $settings[$row['key']] = $row['value'];
                    }
                    error_log("Settings fetched: " . count($settings) . " items");
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching settings: " . $e->getMessage());
        }

        // Get current academic year
        error_log("Fetching current academic year...");
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'academic_years'")->fetch();
            if ($tableCheck) {
                $academicYearStmt = $schoolDb->prepare("
                    SELECT * FROM academic_years 
                    WHERE school_id = ? AND status = 'active' 
                    ORDER BY is_default DESC LIMIT 1
                ");
                if ($academicYearStmt) {
                    $academicYearStmt->execute([$school['id']]);
                    $academicYear = $academicYearStmt->fetch();
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching academic year: " . $e->getMessage());
        }

        // Get current academic term
        if ($academicYear) {
            error_log("Fetching current academic term...");
            try {
                $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'academic_terms'")->fetch();
                if ($tableCheck) {
                    $academicTermStmt = $schoolDb->prepare("
                        SELECT * FROM academic_terms 
                        WHERE school_id = ? AND academic_year_id = ? AND is_default = 1 
                        LIMIT 1
                    ");
                    if ($academicTermStmt) {
                        $academicTermStmt->execute([$school['id'], $academicYear['id']]);
                        $academicTerm = $academicTermStmt->fetch();
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching academic term: " . $e->getMessage());
            }
        }

        // Get school statistics
        error_log("Fetching school statistics...");

        // Total Students
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'students'")->fetch();
            if ($tableCheck) {
                $studentStmt = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM students 
                    WHERE school_id = ? AND status = 'active'
                ");
                if ($studentStmt) {
                    $studentStmt->execute([$school['id']]);
                    $studentResult = $studentStmt->fetch();
                    $totalStudents = $studentResult['count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("Error counting students: " . $e->getMessage());
        }

        // Total Teachers
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'teachers'")->fetch();
            if ($tableCheck) {
                $teacherStmt = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM teachers 
                    WHERE school_id = ? AND is_active = 1
                ");
                if ($teacherStmt) {
                    $teacherStmt->execute([$school['id']]);
                    $teacherResult = $teacherStmt->fetch();
                    $totalTeachers = $teacherResult['count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("Error counting teachers: " . $e->getMessage());
        }

        // Total Classes
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'classes'")->fetch();
            if ($tableCheck) {
                $classStmt = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM classes 
                    WHERE school_id = ? AND is_active = 1
                ");
                if ($classStmt) {
                    $classStmt->execute([$school['id']]);
                    $classResult = $classStmt->fetch();
                    $totalClasses = $classResult['count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("Error counting classes: " . $e->getMessage());
        }

        // Total Subjects
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'subjects'")->fetch();
            if ($tableCheck) {
                $subjectStmt = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM subjects 
                    WHERE school_id = ? AND is_active = 1
                ");
                if ($subjectStmt) {
                    $subjectStmt->execute([$school['id']]);
                    $subjectResult = $subjectStmt->fetch();
                    $totalSubjects = $subjectResult['count'] ?? 0;
                }
            }
        } catch (Exception $e) {
            error_log("Error counting subjects: " . $e->getMessage());
        }

        // Today's attendance
        $today = date('Y-m-d');
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'attendance'")->fetch();
            if ($tableCheck) {
                $attendanceStmt = $schoolDb->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present
                    FROM attendance 
                    WHERE school_id = ? AND date = ?
                ");
                if ($attendanceStmt) {
                    $attendanceStmt->execute([$school['id'], $today]);
                    $attendance = $attendanceStmt->fetch();
                    if ($attendance && $attendance['total'] > 0) {
                        $attendanceRate = round(($attendance['present'] / $attendance['total']) * 100, 1);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching attendance: " . $e->getMessage());
        }

        // Revenue calculations
        error_log("Calculating revenue metrics...");
        try {
            // Check payment_transactions table
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'payment_transactions'")->fetch();
            if ($tableCheck) {
                // Total successful transactions
                $revenueStmt = $schoolDb->prepare("
                    SELECT 
                        SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_revenue,
                        SUM(CASE WHEN status = 'success' AND MONTH(created_at) = MONTH(CURDATE()) THEN amount ELSE 0 END) as monthly_revenue,
                        SUM(CASE WHEN status IN ('pending', 'initiated') THEN amount ELSE 0 END) as pending_amount
                    FROM payment_transactions 
                    WHERE school_id = ?
                ");
                if ($revenueStmt) {
                    $revenueStmt->execute([$school['id']]);
                    $revenueData = $revenueStmt->fetch();

                    if ($revenueData) {
                        $totalRevenue = floatval($revenueData['total_revenue'] ?? 0);
                        $monthlyRevenue = floatval($revenueData['monthly_revenue'] ?? 0);
                        $pendingPayments = floatval($revenueData['pending_amount'] ?? 0);
                    }
                }

                // Get monthly revenue data for last 6 months
                $monthlyStmt = $schoolDb->prepare("
                    SELECT 
                        DATE_FORMAT(created_at, '%Y-%m') as month,
                        DATE_FORMAT(created_at, '%b') as month_name,
                        SUM(amount) as revenue
                    FROM payment_transactions 
                    WHERE school_id = ? 
                    AND status = 'success'
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                    GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
                    ORDER BY month
                ");
                if ($monthlyStmt) {
                    $monthlyStmt->execute([$school['id']]);
                    $monthlyRevenueData = $monthlyStmt->fetchAll();
                }

                // Get payment methods distribution
                $methodsStmt = $schoolDb->prepare("
                    SELECT 
                        payment_method,
                        COUNT(*) as count,
                        SUM(amount) as amount
                    FROM payment_transactions 
                    WHERE school_id = ? 
                    AND status = 'success'
                    AND payment_method IS NOT NULL
                    GROUP BY payment_method
                    ORDER BY amount DESC
                ");
                if ($methodsStmt) {
                    $methodsStmt->execute([$school['id']]);
                    $paymentMethodsData = $methodsStmt->fetchAll();
                }

                // Get recent transactions
                $transactionsStmt = $schoolDb->prepare("
                    SELECT 
                        pt.*,
                        s.first_name as student_first_name,
                        s.last_name as student_last_name,
                        s.admission_number
                    FROM payment_transactions pt
                    LEFT JOIN students s ON pt.student_id = s.id
                    WHERE pt.school_id = ?
                    ORDER BY pt.created_at DESC
                    LIMIT 8
                ");
                if ($transactionsStmt) {
                    $transactionsStmt->execute([$school['id']]);
                    $recentTransactions = $transactionsStmt->fetchAll();
                }
            } else {
                // Fallback to invoices table
                $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'invoices'")->fetch();
                if ($tableCheck) {
                    $invoiceStmt = $schoolDb->prepare("
                        SELECT 
                            SUM(CASE WHEN payment_status = 'success' OR status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
                            SUM(CASE WHEN (payment_status = 'success' OR status = 'paid') AND MONTH(created_at) = MONTH(CURDATE()) THEN total_amount ELSE 0 END) as monthly_revenue,
                            SUM(CASE WHEN payment_status IN ('pending', 'initiated') THEN total_amount ELSE 0 END) as pending_amount
                        FROM invoices 
                        WHERE school_id = ?
                    ");
                    if ($invoiceStmt) {
                        $invoiceStmt->execute([$school['id']]);
                        $invoiceData = $invoiceStmt->fetch();

                        if ($invoiceData) {
                            $totalRevenue = floatval($invoiceData['total_revenue'] ?? 0);
                            $monthlyRevenue = floatval($invoiceData['monthly_revenue'] ?? 0);
                            $pendingPayments = floatval($invoiceData['pending_amount'] ?? 0);
                        }
                    }

                    // Get recent transactions from invoices
                    $transactionsStmt = $schoolDb->prepare("
                        SELECT 
                            i.*,
                            s.first_name as student_first_name,
                            s.last_name as student_last_name,
                            s.admission_number
                        FROM invoices i
                        LEFT JOIN students s ON i.student_id = s.id
                        WHERE i.school_id = ?
                        ORDER BY i.created_at DESC
                        LIMIT 8
                    ");
                    if ($transactionsStmt) {
                        $transactionsStmt->execute([$school['id']]);
                        $recentTransactions = $transactionsStmt->fetchAll();
                    }
                }
            }

            // Calculate collection rate
            $collectionStmt = $schoolDb->prepare("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN payment_status = 'success' OR status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                    SUM(total_amount) as total_amount,
                    SUM(CASE WHEN payment_status = 'success' OR status = 'paid' THEN total_amount ELSE 0 END) as paid_amount
                FROM invoices 
                WHERE school_id = ? AND status NOT IN ('draft', 'canceled')
            ");
            if ($collectionStmt) {
                $collectionStmt->execute([$school['id']]);
                $collectionData = $collectionStmt->fetch();

                if ($collectionData && floatval($collectionData['total_amount'] ?? 0) > 0) {
                    $collectionRate = round((floatval($collectionData['paid_amount'] ?? 0) / floatval($collectionData['total_amount'] ?? 1)) * 100, 1);
                }
            }
        } catch (Exception $e) {
            error_log("Error calculating revenue: " . $e->getMessage());
        }

        // Recent announcements (last 7 days)
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'announcements'")->fetch();
            if ($tableCheck) {
                $announcementStmt = $schoolDb->prepare("
                    SELECT a.*, u.name as created_by_name 
                    FROM announcements a 
                    LEFT JOIN users u ON a.created_by = u.id 
                    WHERE a.school_id = ? AND a.is_published = 1 
                    AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ORDER BY a.created_at DESC 
                    LIMIT 5
                ");
                if ($announcementStmt) {
                    $announcementStmt->execute([$school['id']]);
                    $announcements = $announcementStmt->fetchAll();
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching announcements: " . $e->getMessage());
        }

        // Upcoming events (next 30 days)
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'events'")->fetch();
            if ($tableCheck) {
                $eventStmt = $schoolDb->prepare("
                    SELECT * FROM events 
                    WHERE school_id = ? AND start_date >= CURDATE() 
                    AND start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    ORDER BY start_date ASC 
                    LIMIT 5
                ");
                if ($eventStmt) {
                    $eventStmt->execute([$school['id']]);
                    $upcomingEvents = $eventStmt->fetchAll();
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching events: " . $e->getMessage());
        }

        // Recent activity
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'audit_logs'")->fetch();
            if ($tableCheck) {
                $activityStmt = $schoolDb->prepare("
                    SELECT al.*, u.name as user_name, u.user_type 
                    FROM audit_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    WHERE al.school_id = ? 
                    ORDER BY al.created_at DESC 
                    LIMIT 10
                ");
                if ($activityStmt) {
                    $activityStmt->execute([$school['id']]);
                    $recentActivities = $activityStmt->fetchAll();
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching activity logs: " . $e->getMessage());
        }

        // Grade distribution
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'classes'")->fetch();
            if ($tableCheck) {
                $gradeStmt = $schoolDb->prepare("
                    SELECT 
                        c.name as class_name,
                        COUNT(s.id) as student_count
                    FROM classes c 
                    LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
                    WHERE c.school_id = ? AND c.is_active = 1
                    GROUP BY c.id, c.name
                    ORDER BY c.name
                ");
                if ($gradeStmt) {
                    $gradeStmt->execute([$school['id']]);
                    $gradeDistribution = $gradeStmt->fetchAll();
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching grade distribution: " . $e->getMessage());
        }

        // Weekly attendance trend (last 6 weeks)
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'attendance'")->fetch();
            if ($tableCheck) {
                $weekStmt = $schoolDb->prepare("
                    SELECT 
                        DATE_FORMAT(date, '%Y-%u') as week,
                        CONCAT('Week ', ROW_NUMBER() OVER (ORDER BY MIN(date))) as week_label,
                        AVG(CASE WHEN status = 'present' THEN 1.0 ELSE 0 END) * 100 as attendance_rate
                    FROM attendance 
                    WHERE school_id = ? 
                    AND date >= DATE_SUB(CURDATE(), INTERVAL 6 WEEK)
                    GROUP BY DATE_FORMAT(date, '%Y-%u')
                    ORDER BY week DESC 
                    LIMIT 6
                ");
                if ($weekStmt) {
                    $weekStmt->execute([$school['id']]);
                    $weeklyAttendance = $weekStmt->fetchAll();
                    $weeklyAttendance = array_reverse($weeklyAttendance);
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching weekly attendance: " . $e->getMessage());
        }

        // Fee collection rate for current term
        if ($academicTerm) {
            try {
                $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'invoices'")->fetch();
                if ($tableCheck) {
                    $feeStmt = $schoolDb->prepare("
                        SELECT 
                            COUNT(DISTINCT i.student_id) as total_students,
                            SUM(CASE WHEN i.status = 'paid' THEN 1 ELSE 0 END) as paid_students
                        FROM invoices i 
                        WHERE i.school_id = ? 
                        AND i.academic_term_id = ?
                    ");
                    if ($feeStmt) {
                        $feeStmt->execute([$school['id'], $academicTerm['id']]);
                        $feeData = $feeStmt->fetch();
                        if ($feeData && $feeData['total_students'] > 0) {
                            $feeCollectionRate = round(($feeData['paid_students'] / $feeData['total_students']) * 100, 1);
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching fee collection rate: " . $e->getMessage());
            }
        }

        // Get logged in admin user details
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'users'")->fetch();
            if ($tableCheck) {
                $userStmt = $schoolDb->prepare("
                    SELECT u.*, ur.role_id, r.name as role_name 
                    FROM users u 
                    LEFT JOIN user_roles ur ON u.id = ur.user_id 
                    LEFT JOIN roles r ON ur.role_id = r.id 
                    WHERE u.id = ? AND u.school_id = ?
                ");
                if ($userStmt) {
                    $userStmt->execute([$userId, $school['id']]);
                    $adminUser = $userStmt->fetch();
                    if (!$adminUser) {
                        if (isset($_SESSION['school_auth'][$school['id']]['user_name'])) {
                            $adminUser = [
                                'name' => $_SESSION['school_auth'][$school['id']]['user_name'],
                                'role_name' => 'Administrator'
                            ];
                        } elseif (isset($_SESSION['school_user']['name'])) {
                            $adminUser = [
                                'name' => $_SESSION['school_user']['name'],
                                'role_name' => 'Administrator'
                            ];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching admin user: " . $e->getMessage());
        }

        error_log("All data fetched successfully from school database");
    } catch (Exception $e) {
        error_log("ERROR in database operations: " . $e->getMessage());
    }
} else {
    error_log("School database connection failed or not available, using default values");
}

// Check trial status
$trialWarning = '';
if ($school['status'] === 'trial' && !empty($school['trial_ends_at'])) {
    try {
        $daysLeft = ceil((strtotime($school['trial_ends_at']) - time()) / (60 * 60 * 24));
        if ($daysLeft <= 7 && $daysLeft > 0) {
            $trialWarning = "Your trial expires in {$daysLeft} day" . ($daysLeft > 1 ? 's' : '');
        }
    } catch (Exception $e) {
        error_log("Error calculating trial days: " . $e->getMessage());
    }
}

// Format currency
$currencySymbol = $settings['currency_symbol'] ?? '₦';

error_log("=================== SCHOOL DASHBOARD END ===================");
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

        :root {
            --school-primary: <?php echo $school['primary_color'] ?? '#4f46e5'; ?>;
            --school-secondary: <?php echo $school['secondary_color'] ?? '#10b981'; ?>;
            --school-surface: #ffffff;
            --school-bg: #f8fafc;
        }

        body {
            font-family: 'Inter', sans-serif;
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
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateX(100px);
            }
        }

        .toast-exit {
            animation: fadeOut 0.3s ease forwards;
        }

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
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--school-primary);
            border-radius: 4px 0 0 4px;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .animate-fadeInUp {
            animation: fadeInUp 0.6s ease-out forwards;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-active {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .status-completed {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        @media (max-width: 768px) {
            .glass-header {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                background: white;
            }

            .toast-container {
                left: 20px;
                right: 20px;
                max-width: none;
            }

            .chart-container {
                height: 250px !important;
            }
        }

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

        .tab-button:hover {
            color: #4f46e5;
        }

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
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--metric-color), transparent);
        }

        .metric-primary {
            --metric-color: var(--school-primary);
        }

        .metric-success {
            --metric-color: var(--school-secondary);
        }

        .metric-warning {
            --metric-color: #f59e0b;
        }

        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        .quick-action {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .quick-action:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.08);
        }

        .announcement-card {
            border-left: 4px solid transparent;
            transition: all 0.3s ease;
        }

        .announcement-card:hover {
            transform: translateX(4px);
        }

        .announcement-urgent {
            border-left-color: #ef4444;
        }

        .announcement-important {
            border-left-color: #f59e0b;
        }

        .announcement-info {
            border-left-color: #4f46e5;
        }

        .progress-bar {
            height: 6px;
            border-radius: 3px;
            background: #f1f5f9;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-primary {
            background: linear-gradient(90deg, var(--school-primary), #7c73e9);
        }

        .progress-success {
            background: linear-gradient(90deg, var(--school-secondary), #34d399);
        }

        .progress-warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }

        .avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-weight: 600;
            color: white;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-primary {
            background-color: #e0e7ff;
            color: var(--school-primary);
        }

        .badge-success {
            background-color: #d1fae5;
            color: #059669;
        }

        .badge-warning {
            background-color: #fef3c7;
            color: #d97706;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }

        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--school-primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
            background: white;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        /* Revenue specific styles */
        .revenue-positive {
            color: #10b981;
        }

        .revenue-negative {
            color: #ef4444;
        }

        .revenue-neutral {
            color: #6b7280;
        }

        .currency-format {
            font-feature-settings: "tnum" 1;
        }
    </style>
</head>

<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- New Announcement Modal -->
    <div id="newAnnouncementModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-2xl w-11/12 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Create New Announcement</h3>
                <button onclick="closeModal('newAnnouncementModal')" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="announcementForm" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/process-announcement">
                <div class="space-y-6">
                    <div>
                        <label class="form-label">Announcement Title</label>
                        <input type="text" name="title" id="announcementTitle" class="form-input" placeholder="e.g., Upcoming Parent-Teacher Meetings" required>
                    </div>

                    <div>
                        <label class="form-label">Priority Level</label>
                        <div class="grid grid-cols-3 gap-3">
                            <label class="flex flex-col items-center justify-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 announcement-priority">
                                <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center mb-2">
                                    <i class="fas fa-info-circle text-blue-600"></i>
                                </div>
                                <span class="text-sm font-medium">Information</span>
                                <input type="radio" name="priority" value="info" class="hidden" checked>
                            </label>
                            <label class="flex flex-col items-center justify-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 announcement-priority">
                                <div class="w-10 h-10 rounded-lg bg-amber-50 flex items-center justify-center mb-2">
                                    <i class="fas fa-exclamation-triangle text-amber-600"></i>
                                </div>
                                <span class="text-sm font-medium">Important</span>
                                <input type="radio" name="priority" value="important" class="hidden">
                            </label>
                            <label class="flex flex-col items-center justify-center p-4 border border-slate-200 rounded-xl cursor-pointer hover:bg-slate-50 announcement-priority">
                                <div class="w-10 h-10 rounded-lg bg-red-50 flex items-center justify-center mb-2">
                                    <i class="fas fa-exclamation-circle text-red-600"></i>
                                </div>
                                <span class="text-sm font-medium">Urgent</span>
                                <input type="radio" name="priority" value="urgent" class="hidden">
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Target Audience</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="target[]" value="all" checked class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">All Users</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="target[]" value="students" class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Students</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="target[]" value="teachers" class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Teachers</span>
                            </label>
                            <label class="flex items-center gap-3">
                                <input type="checkbox" name="target[]" value="parents" class="rounded border-slate-300">
                                <span class="text-sm text-slate-700">Parents</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Announcement Content</label>
                        <textarea name="description" id="announcementContent" class="form-input h-40" placeholder="Enter detailed announcement content..." required></textarea>
                    </div>

                    <div>
                        <label class="form-label">Schedule</label>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <input type="date" name="start_date" id="announcementDate" class="form-input">
                                <p class="text-xs text-slate-500 mt-1">Publish Date</p>
                            </div>
                            <div>
                                <input type="date" name="end_date" id="announcementEndDate" class="form-input">
                                <p class="text-xs text-slate-500 mt-1">End Date</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button type="button" onclick="closeModal('newAnnouncementModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                        Publish Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div id="addStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="bg-white rounded-2xl p-8 max-w-2xl w-11/12 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-xl font-black text-slate-900">Add New Student</h3>
                <button onclick="closeModal('addStudentModal')" class="text-slate-400 hover:text-slate-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form id="studentForm" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/process-student">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input" placeholder="John" required>
                    </div>
                    <div>
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" placeholder="Doe" required>
                    </div>
                    <div>
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="form-input" required>
                    </div>
                    <div>
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Class</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php
                            if ($schoolDb) {
                                try {
                                    $classOptionsStmt = $schoolDb->prepare("
                                    SELECT id, name FROM classes 
                                    WHERE school_id = ? AND is_active = 1 
                                    ORDER BY name
                                ");
                                    $classOptionsStmt->execute([$school['id']]);
                                    $classOptions = $classOptionsStmt->fetchAll();
                                    foreach ($classOptions as $class):
                            ?>
                                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php
                                    endforeach;
                                } catch (Exception $e) {
                                    error_log("Error loading classes: " . $e->getMessage());
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Section</label>
                        <select name="section_id" class="form-select">
                            <option value="">Select Section</option>
                        </select>
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-input" placeholder="student@email.com">
                    </div>
                    <div class="md:col-span-2">
                        <label class="form-label">Parent/Guardian Phone</label>
                        <input type="tel" name="parent_phone" class="form-input" placeholder="+234 800 000 0000">
                    </div>
                </div>

                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button type="button" onclick="closeModal('addStudentModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                        Add Student
                    </button>
                </div>
            </form>
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
                        <span class="text-xl font-black tracking-tight text-slate-900"><?php echo htmlspecialchars(strtoupper($school['name'])); ?></span>
                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-0.5">SCHOOL ADMIN</p>
                    </div>
                </div>
            </div>

            <!-- School Quick Info -->
            <div class="p-6 border-b border-slate-100">
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">School ID:</span>
                        <span class="text-sm font-black text-indigo-600"><?php echo $school['id']; ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Status:</span>
                        <span class="badge <?php echo $school['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo ucfirst($school['status']); ?>
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Academic Year:</span>
                        <span class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($academicYear['name'] ?? 'Not Set'); ?></span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="./dashboard.php" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span>Overview</span>
                        </a>
                        <a href="./announcements.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <span>Announcements</span>
                            <span class="badge badge-primary ml-auto"><?php echo count($announcements); ?></span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Student Management</p>
                    <nav class="space-y-1">
                        <a href="./students.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <span>Students Directory</span>
                            <span class="text-xs font-bold text-slate-400 ml-auto"><?php echo $totalStudents; ?></span>
                        </a>
                        <a href="./attendance.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span>Attendance</span>
                        </a>
                        <a href="./grades.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <span>Grades & Reports</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Staff Management</p>
                    <nav class="space-y-1">
                        <a href="./teachers.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <span>Teachers</span>
                            <span class="text-xs font-bold text-slate-400 ml-auto"><?php echo $totalTeachers; ?></span>
                        </a>
                        <a href="./staff.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-users"></i>
                            </div>
                            <span>Staff Directory</span>
                        </a>
                        <a href="./schedule.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <span>Timetable</span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Finance & Revenue</p>
                    <nav class="space-y-1">
                        <a href="./fees.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <span>Fee Management</span>
                        </a>
                        <a href="./finance.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <span>Revenue Analytics</span>
                            <span class="badge badge-success ml-auto"><?php echo $currencySymbol . number_format($totalRevenue, 0); ?></span>
                        </a>
                    </nav>
                </div>

                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">School Operations</p>
                    <nav class="space-y-1">
                        <a href="./settings.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-cog"></i>
                            </div>
                            <span>School Settings</span>
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
                            $initials = '';
                            $nameParts = explode(' ', $adminUser['name'] ?? 'Admin');
                            foreach ($nameParts as $part) {
                                $initials .= strtoupper(substr($part, 0, 1));
                                if (strlen($initials) >= 2) break;
                            }
                            echo $initials ?: 'A';
                            ?>
                        </div>
                        <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-[13px] font-black text-slate-900 truncate"><?php echo htmlspecialchars($adminUser['name'] ?? 'Admin'); ?></p>
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider italic"><?php echo htmlspecialchars($adminUser['role_name'] ?? 'Administrator'); ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">

            <!-- Header -->
            <header class="h-20 glass-header px-6 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">School Overview Dashboard</h1>
                        <?php if ($academicYear): ?>
                            <div class="hidden lg:flex items-center gap-2">
                                <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                                <span class="text-xs font-black text-emerald-600 uppercase tracking-widest"><?php echo htmlspecialchars($academicYear['name']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Revenue Quick Stats -->
                    <div class="hidden md:flex items-center gap-2 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Revenue</p>
                            <p class="text-sm font-black text-emerald-600 currency-format"><?php echo $currencySymbol . number_format($monthlyRevenue, 0); ?></p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="openModal('newAnnouncementModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-bullhorn"></i>
                            <span class="hidden sm:inline">New Announcement</span>
                        </button>
                        <button onclick="openModal('addStudentModal')" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            <i class="fas fa-user-plus"></i>
                            <span class="hidden sm:inline">Add Student</span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab('overview')" data-tab="overview">
                            <i class="fas fa-home mr-2"></i>Overview
                        </button>
                        <button class="tab-button" onclick="switchTab('students')" data-tab="students">
                            <i class="fas fa-user-graduate mr-2"></i>Students
                        </button>
                        <button class="tab-button" onclick="switchTab('teachers')" data-tab="teachers">
                            <i class="fas fa-chalkboard-teacher mr-2"></i>Teachers
                        </button>
                        <button class="tab-button" onclick="switchTab('academics')" data-tab="academics">
                            <i class="fas fa-graduation-cap mr-2"></i>Academics
                        </button>
                        <button class="tab-button" onclick="switchTab('finance')" data-tab="finance">
                            <i class="fas fa-chart-line mr-2"></i>Finance
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Welcome Section -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="glass-card rounded-2xl p-8 bg-gradient-to-r from-indigo-50 to-purple-50 border-indigo-100">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div>
                                <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">Welcome back, <?php echo htmlspecialchars($adminUser['name'] ?? 'Admin'); ?></h2>
                                <p class="text-slate-600 font-medium">Here's what's happening at <?php echo htmlspecialchars($school['name']); ?> today</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="text-right">
                                    <p class="text-sm font-black text-slate-900">Total Revenue</p>
                                    <p class="text-2xl font-black text-emerald-600 currency-format"><?php echo $currencySymbol . number_format($totalRevenue, 0); ?></p>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center">
                                    <i class="fas fa-chart-line text-emerald-600 text-2xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="max-w-7xl mx-auto mb-8">
                    <h3 class="text-lg font-black text-slate-900 mb-4">Quick Actions</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="glass-card quick-action rounded-2xl p-6 text-center hover:border-amber-200" onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/attendance'">
                            <div class="w-12 h-12 rounded-xl bg-amber-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-calendar-check text-amber-600 text-xl"></i>
                            </div>
                            <h4 class="font-bold text-slate-900 mb-1">Take Attendance</h4>
                            <p class="text-sm text-slate-500">Record today's attendance</p>
                        </div>
                        <div class="glass-card quick-action rounded-2xl p-6 text-center hover:border-emerald-200" onclick="openModal('newAnnouncementModal')">
                            <div class="w-12 h-12 rounded-xl bg-emerald-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-bell text-emerald-600 text-xl"></i>
                            </div>
                            <h4 class="font-bold text-slate-900 mb-1">Send Notification</h4>
                            <p class="text-sm text-slate-500">Notify parents/students</p>
                        </div>
                        <div class="glass-card quick-action rounded-2xl p-6 text-center hover:border-blue-200" onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/reports'">
                            <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                            </div>
                            <h4 class="font-bold text-slate-900 mb-1">Generate Reports</h4>
                            <p class="text-sm text-slate-500">Academic/financial reports</p>
                        </div>
                        <div class="glass-card quick-action rounded-2xl p-6 text-center hover:border-green-200" onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/finance'">
                            <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fas fa-chart-line text-green-600 text-xl"></i>
                            </div>
                            <h4 class="font-bold text-slate-900 mb-1">Revenue Report</h4>
                            <p class="text-sm text-slate-500">View financial analytics</p>
                        </div>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Students Card -->
                    <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TOTAL STUDENTS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($totalStudents); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-50 to-purple-50 flex items-center justify-center">
                                <i class="fas fa-user-graduate text-indigo-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-primary" style="width: <?php echo min(($totalStudents / 2000) * 100, 100); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Capacity: <?php echo $totalStudents; ?>/2000</p>
                    </div>

                    <!-- Total Teachers Card -->
                    <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TOTAL TEACHERS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($totalTeachers); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-primary" style="width: <?php echo min(($totalTeachers / 100) * 100, 100); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Capacity: <?php echo $totalTeachers; ?>/100</p>
                    </div>

                    <!-- Attendance Rate Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">ATTENDANCE RATE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $attendanceRate; ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-calendar-check text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-success" style="width: <?php echo $attendanceRate; ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Today's attendance</p>
                    </div>

                    <!-- Fee Collection Card -->
                    <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">FEE COLLECTION</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $feeCollectionRate; ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-receipt text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-warning" style="width: <?php echo $feeCollectionRate; ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Current term collection rate</p>
                    </div>
                </div>

                <!-- Revenue Metrics -->
                <div class="max-w-7xl mx-auto mb-8">
                    <h3 class="text-lg font-black text-slate-900 mb-4">Revenue & Finance Overview</h3>

                    <!-- Revenue Summary Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                        <!-- Total Revenue Card -->
                        <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.5s">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-sm font-bold text-slate-400">TOTAL REVENUE</p>
                                    <p class="text-2xl font-black text-slate-900 currency-format"><?php echo $currencySymbol . number_format($totalRevenue, 2); ?></p>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-50 to-emerald-50 flex items-center justify-center">
                                    <i class="fas fa-money-bill-wave text-emerald-600 text-lg"></i>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-success" style="width: <?php echo min(($totalRevenue / 1000000) * 100, 100); ?>%"></div>
                            </div>
                            <p class="text-xs text-slate-500 mt-2">Lifetime revenue from payments</p>
                        </div>

                        <!-- Monthly Revenue Card -->
                        <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.6s">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-sm font-bold text-slate-400">MONTHLY REVENUE</p>
                                    <p class="text-2xl font-black text-slate-900 currency-format"><?php echo $currencySymbol . number_format($monthlyRevenue, 2); ?></p>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-cyan-50 flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-blue-600 text-lg"></i>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-primary" style="width: <?php echo min(($monthlyRevenue / 100000) * 100, 100); ?>%"></div>
                            </div>
                            <p class="text-xs text-slate-500 mt-2">Current month's revenue</p>
                        </div>

                        <!-- Pending Payments Card -->
                        <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.7s">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-sm font-bold text-slate-400">PENDING PAYMENTS</p>
                                    <p class="text-2xl font-black text-slate-900 currency-format"><?php echo $currencySymbol . number_format($pendingPayments, 2); ?></p>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-orange-50 flex items-center justify-center">
                                    <i class="fas fa-clock text-amber-600 text-lg"></i>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-warning" style="width: <?php echo min(($pendingPayments / 50000) * 100, 100); ?>%"></div>
                            </div>
                            <p class="text-xs text-slate-500 mt-2">Awaiting payment confirmation</p>
                        </div>

                        <!-- Collection Rate Card -->
                        <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.8s">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <p class="text-sm font-bold text-slate-400">COLLECTION RATE</p>
                                    <p class="text-2xl font-black text-slate-900"><?php echo $collectionRate; ?>%</p>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-teal-50 to-emerald-50 flex items-center justify-center">
                                    <i class="fas fa-percentage text-teal-600 text-lg"></i>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-success" style="width: <?php echo $collectionRate; ?>%"></div>
                            </div>
                            <p class="text-xs text-slate-500 mt-2">Invoice collection rate</p>
                        </div>
                    </div>

                    <!-- Revenue Charts -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Monthly Revenue Trend -->
                        <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <h3 class="text-lg font-black text-slate-900">Monthly Revenue Trend</h3>
                                    <p class="text-slate-500">Revenue over last 6 months</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-bold <?php echo $monthlyRevenue > 0 ? 'text-emerald-600' : 'text-slate-400'; ?>">
                                        <i class="fas fa-chart-line mr-1"></i>
                                        <?php echo $currencySymbol . number_format($monthlyRevenue, 2); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="chart-container">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>

                        <!-- Payment Methods Distribution -->
                        <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                            <div class="flex items-center justify-between mb-6">
                                <div>
                                    <h3 class="text-lg font-black text-slate-900">Payment Methods</h3>
                                    <p class="text-slate-500">Distribution by payment type</p>
                                </div>
                                <button onclick="exportRevenueData()" class="px-3 py-1.5 bg-slate-100 text-slate-700 font-bold rounded-lg hover:bg-slate-200 transition text-xs">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                            <div class="chart-container">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="glass-card rounded-2xl p-6 mt-6">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Recent Transactions</h3>
                                <p class="text-slate-500">Latest payments and invoices</p>
                            </div>
                            <button onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/finance'" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                                <i class="fas fa-exchange-alt mr-2"></i>View All
                            </button>
                        </div>

                        <?php if (count($recentTransactions) > 0): ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-slate-100">
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Transaction</th>
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Student</th>
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Amount</th>
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Method</th>
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Status</th>
                                            <th class="text-left py-3 px-4 text-xs font-black text-slate-400 uppercase tracking-wider">Date</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($recentTransactions as $transaction):
                                            $statusClass = '';
                                            $statusText = '';

                                            if (isset($transaction['status'])) {
                                                $status = strtolower($transaction['status']);
                                                if (in_array($status, ['success', 'paid'])) {
                                                    $statusClass = 'status-active';
                                                    $statusText = 'Paid';
                                                } elseif (in_array($status, ['pending', 'initiated', 'processing'])) {
                                                    $statusClass = 'status-pending';
                                                    $statusText = 'Pending';
                                                } elseif (in_array($status, ['failed', 'cancelled', 'refunded'])) {
                                                    $statusClass = 'status-inactive';
                                                    $statusText = ucfirst($status);
                                                } else {
                                                    $statusClass = 'status-pending';
                                                    $statusText = ucfirst($status);
                                                }
                                            } elseif (isset($transaction['payment_status'])) {
                                                $status = strtolower($transaction['payment_status']);
                                                $statusClass = $status === 'success' ? 'status-active' : 'status-pending';
                                                $statusText = ucfirst($status);
                                            }

                                            $amount = $transaction['amount'] ?? $transaction['total_amount'] ?? 0;
                                            $method = $transaction['payment_method'] ?? 'N/A';
                                            $reference = $transaction['transaction_reference'] ?? $transaction['invoice_number'] ?? 'N/A';

                                            $studentName = 'N/A';
                                            if (isset($transaction['student_first_name']) && $transaction['student_first_name']) {
                                                $studentName = htmlspecialchars($transaction['student_first_name'] . ' ' . $transaction['student_last_name']);
                                            } elseif (isset($transaction['admission_number'])) {
                                                $studentName = 'Student ' . $transaction['admission_number'];
                                            }
                                        ?>
                                            <tr class="hover:bg-slate-50 transition">
                                                <td class="py-3 px-4">
                                                    <div>
                                                        <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($reference); ?></p>
                                                        <p class="text-xs text-slate-500"><?php echo isset($transaction['gateway_transaction_id']) ? htmlspecialchars($transaction['gateway_transaction_id']) : 'Payment'; ?></p>
                                                    </div>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <p class="text-sm text-slate-700"><?php echo $studentName; ?></p>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <p class="text-sm font-bold text-emerald-600 currency-format"><?php echo $currencySymbol . number_format($amount, 2); ?></p>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <p class="text-sm text-slate-600"><?php echo htmlspecialchars($method); ?></p>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <span class="status-badge <?php echo $statusClass; ?>">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-4">
                                                    <p class="text-sm text-slate-500">
                                                        <?php echo date('M j, Y', strtotime($transaction['created_at'] ?? 'now')); ?>
                                                    </p>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-exchange-alt text-slate-400 text-xl"></i>
                                </div>
                                <p class="text-slate-500">No transactions recorded yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Attendance Trend Chart -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Attendance Trend</h3>
                                <p class="text-slate-500">Weekly attendance percentage</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold <?php echo $attendanceRate >= 90 ? 'text-emerald-600' : 'text-amber-600'; ?>">
                                    <?php echo $attendanceRate >= 90 ? '<i class="fas fa-arrow-up mr-1"></i>' : '<i class="fas fa-arrow-down mr-1"></i>'; ?>
                                    <?php echo $attendanceRate; ?>%
                                </span>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>

                    <!-- Grade Distribution Chart -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Grade Distribution</h3>
                                <p class="text-slate-500">Student count by class</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="exportGradeData()" class="px-3 py-1.5 bg-slate-100 text-slate-700 font-bold rounded-lg hover:bg-slate-200 transition text-xs">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="gradeDistributionChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Announcements -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6 mb-8 animate-fadeInUp">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-black text-slate-900">Recent Announcements</h3>
                            <p class="text-slate-500">Latest school announcements and updates</p>
                        </div>
                        <button onclick="openModal('newAnnouncementModal')" class="px-4 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                            <i class="fas fa-plus mr-2"></i>New Announcement
                        </button>
                    </div>

                    <?php if (count($announcements) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($announcements as $announcement):
                                $priorityClass = 'announcement-info';
                                $badgeClass = 'badge-primary';
                                $iconClass = 'fa-info-circle text-blue-600';

                                if (isset($announcement['priority'])) {
                                    if ($announcement['priority'] === 'urgent') {
                                        $priorityClass = 'announcement-urgent';
                                        $badgeClass = 'badge-warning';
                                        $iconClass = 'fa-exclamation-circle text-red-600';
                                    } elseif ($announcement['priority'] === 'important') {
                                        $priorityClass = 'announcement-important';
                                        $badgeClass = 'badge-warning';
                                        $iconClass = 'fa-exclamation-triangle text-amber-600';
                                    }
                                }
                            ?>
                                <div class="announcement-card <?php echo $priorityClass; ?> p-4 bg-slate-50 border border-slate-100 rounded-xl">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2 mb-2">
                                                <span class="badge <?php echo $badgeClass; ?>">
                                                    <?php echo isset($announcement['priority']) ? ucfirst($announcement['priority']) : 'Info'; ?>
                                                </span>
                                                <span class="text-xs text-slate-500"><?php echo date('M j, Y • h:i A', strtotime($announcement['created_at'])); ?></span>
                                            </div>
                                            <h4 class="font-bold text-slate-900 mb-1"><?php echo htmlspecialchars($announcement['title'] ?? 'No Title'); ?></h4>
                                            <p class="text-sm text-slate-600">
                                                <?php
                                                $description = $announcement['description'] ?? '';
                                                echo htmlspecialchars(substr($description, 0, 150)) . (strlen($description) > 150 ? '...' : '');
                                                ?>
                                            </p>
                                            <p class="text-xs text-slate-500 mt-2">By: <?php echo htmlspecialchars($announcement['created_by_name'] ?? 'System'); ?></p>
                                        </div>
                                        <button class="p-2 text-slate-400 hover:text-slate-600">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-bullhorn text-slate-400 text-xl"></i>
                            </div>
                            <p class="text-slate-500">No announcements yet. Create your first announcement!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upcoming Events & Recent Activity -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Upcoming Events -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Upcoming Events</h3>
                                <p class="text-slate-500">School events and activities</p>
                            </div>
                            <button onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/events'" class="px-4 py-2 text-indigo-600 font-bold rounded-xl hover:bg-indigo-50 transition">
                                View All
                            </button>
                        </div>

                        <?php if (count($upcomingEvents) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach ($upcomingEvents as $event):
                                    $eventType = strtolower($event['type'] ?? 'other');
                                    $bgClass = '';
                                    $borderClass = '';
                                    $textClass = '';

                                    switch ($eventType) {
                                        case 'holiday':
                                            $bgClass = 'bg-purple-50';
                                            $borderClass = 'border-purple-100';
                                            $textClass = 'text-purple-600';
                                            break;
                                        case 'exam':
                                            $bgClass = 'bg-red-50';
                                            $borderClass = 'border-red-100';
                                            $textClass = 'text-red-600';
                                            break;
                                        case 'meeting':
                                            $bgClass = 'bg-blue-50';
                                            $borderClass = 'border-blue-100';
                                            $textClass = 'text-blue-600';
                                            break;
                                        default:
                                            $bgClass = 'bg-emerald-50';
                                            $borderClass = 'border-emerald-100';
                                            $textClass = 'text-emerald-600';
                                    }
                                ?>
                                    <div class="flex items-center gap-4 p-3 <?php echo $bgClass; ?> border <?php echo $borderClass; ?> rounded-xl">
                                        <div class="flex-shrink-0 w-12 h-12 rounded-lg <?php echo $bgClass; ?> flex flex-col items-center justify-center">
                                            <span class="text-sm font-black <?php echo $textClass; ?>"><?php echo date('M', strtotime($event['start_date'] ?? 'now')); ?></span>
                                            <span class="text-lg font-black <?php echo $textClass; ?>"><?php echo date('j', strtotime($event['start_date'] ?? 'now')); ?></span>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="font-bold text-slate-900 mb-1"><?php echo htmlspecialchars($event['title'] ?? 'Untitled Event'); ?></h4>
                                            <p class="text-xs text-slate-500">
                                                <?php if (isset($event['start_time'])): ?>
                                                    <?php echo date('h:i A', strtotime($event['start_time'])); ?>
                                                <?php endif; ?>
                                                <?php if (isset($event['venue'])): ?> • <?php echo htmlspecialchars($event['venue']); ?><?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="flex-shrink-0">
                                            <span class="badge <?php echo strtolower($event['type'] ?? 'other') === 'holiday' ? 'badge-success' : 'badge-primary'; ?>">
                                                <?php echo ucfirst($event['type'] ?? 'Event'); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-calendar-alt text-slate-400 text-xl"></i>
                                </div>
                                <p class="text-slate-500">No upcoming events scheduled</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Activity -->
                    <div class="glass-card rounded-2xl p-6 animate-fadeInUp">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Recent Activity</h3>
                                <p class="text-slate-500">Latest updates and changes</p>
                            </div>
                            <button onclick="window.location.href='/tenant/<?php echo $schoolSlug; ?>/admin/activity-log'" class="px-4 py-2 text-indigo-600 font-bold rounded-xl hover:bg-indigo-50 transition">
                                View All
                            </button>
                        </div>

                        <?php if (count($recentActivities) > 0): ?>
                            <div class="space-y-4">
                                <?php foreach ($recentActivities as $activity):
                                    $avatarColor = '';
                                    $avatarIcon = '';

                                    $eventType = $activity['event'] ?? '';
                                    switch ($eventType) {
                                        case 'student_created':
                                            $avatarColor = 'from-emerald-500 to-teal-500';
                                            $avatarIcon = 'fa-user-plus';
                                            break;
                                        case 'teacher_created':
                                            $avatarColor = 'from-blue-500 to-cyan-500';
                                            $avatarIcon = 'fa-chalkboard-teacher';
                                            break;
                                        case 'attendance_marked':
                                            $avatarColor = 'from-amber-500 to-orange-500';
                                            $avatarIcon = 'fa-calendar-check';
                                            break;
                                        case 'fee_payment':
                                            $avatarColor = 'from-green-500 to-emerald-500';
                                            $avatarIcon = 'fa-dollar-sign';
                                            break;
                                        default:
                                            $avatarColor = 'from-indigo-500 to-purple-500';
                                            $avatarIcon = 'fa-history';
                                    }
                                ?>
                                    <div class="flex items-center gap-3">
                                        <div class="avatar avatar-md bg-gradient-to-br <?php echo $avatarColor; ?>">
                                            <i class="fas <?php echo $avatarIcon; ?>"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium text-slate-900">
                                                <?php echo ucfirst(str_replace('_', ' ', $eventType)); ?>
                                            </p>
                                            <p class="text-xs text-slate-500">
                                                By <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?> •
                                                <?php echo time_ago($activity['created_at'] ?? date('Y-m-d H:i:s')); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-history text-slate-400 text-xl"></i>
                                </div>
                                <p class="text-slate-500">No recent activity recorded</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        class Toast {
            static show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toastContainer');
                if (!container) return;

                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;

                const icons = {
                    success: 'fa-check-circle',
                    info: 'fa-info-circle',
                    warning: 'fa-exclamation-triangle',
                    error: 'fa-times-circle'
                };

                toast.innerHTML = `
                    <i class="fas ${icons[type] || 'fa-info-circle'} toast-icon"></i>
                    <div class="toast-content">${message}</div>
                    <button class="toast-close" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                container.appendChild(toast);

                toast.offsetHeight;

                setTimeout(() => {
                    toast.style.opacity = '1';
                    toast.style.transform = 'translateX(0)';
                }, 10);

                if (duration > 0) {
                    setTimeout(() => {
                        toast.classList.add('toast-exit');
                        setTimeout(() => {
                            if (toast.parentNode) {
                                toast.remove();
                            }
                        }, 300);
                    }, duration);
                }

                return toast;
            }

            static success(message, duration = 5000) {
                return this.show(message, 'success', duration);
            }

            static info(message, duration = 5000) {
                return this.show(message, 'info', duration);
            }

            static warning(message, duration = 5000) {
                return this.show(message, 'warning', duration);
            }

            static error(message, duration = 5000) {
                return this.show(message, 'error', duration);
            }
        }

        // Chart Instances
        let attendanceChart = null;
        let gradeDistributionChart = null;
        let revenueChart = null;
        let paymentMethodsChart = null;

        // Initialize Charts
        function initializeCharts() {
            // Attendance Trend Chart
            const attendanceCanvas = document.getElementById('attendanceChart');
            if (attendanceCanvas) {
                const attendanceCtx = attendanceCanvas.getContext('2d');

                <?php
                $weeklyLabels = [];
                $weeklyData = [];
                foreach ($weeklyAttendance as $week) {
                    $weeklyLabels[] = $week['week_label'] ?? 'Week';
                    $weeklyData[] = floatval($week['attendance_rate'] ?? 0);
                }
                ?>

                attendanceChart = new Chart(attendanceCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($weeklyLabels); ?>,
                        datasets: [{
                            label: 'Attendance Rate',
                            data: <?php echo json_encode($weeklyData); ?>,
                            borderColor: '<?php echo $school["primary_color"] ?? "#4f46e5"; ?>',
                            backgroundColor: 'rgba(79, 70, 229, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: function(context) {
                                        return `Attendance: ${context.parsed.y}%`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: Math.min(...<?php echo json_encode($weeklyData); ?>) - 5,
                                max: 100,
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                },
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            // Grade Distribution Chart
            const gradeCanvas = document.getElementById('gradeDistributionChart');
            if (gradeCanvas) {
                const gradeCtx = gradeCanvas.getContext('2d');

                <?php
                $gradeLabels = [];
                $gradeData = [];
                foreach ($gradeDistribution as $grade) {
                    $gradeLabels[] = $grade['class_name'] ?? 'Class';
                    $gradeData[] = intval($grade['student_count'] ?? 0);
                }
                ?>

                gradeDistributionChart = new Chart(gradeCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($gradeLabels); ?>,
                        datasets: [{
                            label: 'Students',
                            data: <?php echo json_encode($gradeData); ?>,
                            backgroundColor: '<?php echo $school["primary_color"] ?? "#4f46e5"; ?>',
                            borderWidth: 0,
                            borderRadius: 6,
                            hoverBackgroundColor: '#3730a3'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `Students: ${context.parsed.y}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 20
                                },
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            // Revenue Chart
            const revenueCanvas = document.getElementById('revenueChart');
            if (revenueCanvas) {
                const revenueCtx = revenueCanvas.getContext('2d');

                <?php
                $revenueMonths = [];
                $revenueData = [];

                if (!empty($monthlyRevenueData)) {
                    foreach ($monthlyRevenueData as $month) {
                        $revenueMonths[] = $month['month_name'];
                        $revenueData[] = floatval($month['revenue'] ?? 0);
                    }
                } else {
                    // Generate sample data for last 6 months
                    $currentDate = new DateTime();
                    for ($i = 5; $i >= 0; $i--) {
                        $date = clone $currentDate;
                        $date->modify("-$i months");
                        $revenueMonths[] = $date->format('M');
                        // Generate random revenue data for demo
                        $revenueData[] = $monthlyRevenue * (0.8 + (mt_rand(0, 40) / 100));
                    }
                }
                ?>

                revenueChart = new Chart(revenueCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($revenueMonths); ?>,
                        datasets: [{
                            label: 'Revenue',
                            data: <?php echo json_encode($revenueData); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `Revenue: <?php echo $currencySymbol; ?>${context.parsed.y.toFixed(2)}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '<?php echo $currencySymbol; ?>' + value.toLocaleString();
                                    }
                                },
                                grid: {
                                    drawBorder: false
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            }

            // Payment Methods Chart
            const paymentCanvas = document.getElementById('paymentMethodsChart');
            if (paymentCanvas) {
                const paymentCtx = paymentCanvas.getContext('2d');

                <?php
                $paymentLabels = [];
                $paymentAmounts = [];

                if (!empty($paymentMethodsData)) {
                    foreach ($paymentMethodsData as $method) {
                        $paymentLabels[] = ucfirst($method['payment_method'] ?? 'Unknown');
                        $paymentAmounts[] = floatval($method['amount'] ?? 0);
                    }
                } else {
                    // Sample payment methods data
                    $paymentLabels = ['Card', 'Bank Transfer', 'Mobile Money', 'Cash'];
                    $paymentAmounts = [
                        $totalRevenue * 0.4,
                        $totalRevenue * 0.3,
                        $totalRevenue * 0.2,
                        $totalRevenue * 0.1
                    ];
                }
                ?>

                paymentMethodsChart = new Chart(paymentCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($paymentLabels); ?>,
                        datasets: [{
                            data: <?php echo json_encode($paymentAmounts); ?>,
                            backgroundColor: [
                                '#4f46e5',
                                '#10b981',
                                '#f59e0b',
                                '#ef4444',
                                '#8b5cf6',
                                '#06b6d4'
                            ],
                            borderWidth: 0,
                            hoverOffset: 15
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const value = context.raw;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `<?php echo $currencySymbol; ?>${value.toLocaleString()} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        cutout: '70%'
                    }
                });
            }
        }

        // Mobile sidebar toggle
        function mobileSidebarToggle() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (!sidebar || !overlay) return;

            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });

            if (event && event.target) {
                event.target.classList.add('active');
            }

            Toast.info(`Switched to ${tabName} view`);
            console.log('Switched to tab:', tabName);
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (!modal) return;

            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        // Form submission handlers
        const announcementForm = document.getElementById('announcementForm');
        if (announcementForm) {
            announcementForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);

                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Publishing...';
                submitBtn.disabled = true;

                setTimeout(() => {
                    Toast.success('Announcement published successfully!');
                    closeModal('newAnnouncementModal');
                    this.reset();
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 1500);
            });
        }

        const studentForm = document.getElementById('studentForm');
        if (studentForm) {
            studentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);

                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Adding...';
                submitBtn.disabled = true;

                setTimeout(() => {
                    Toast.success('Student added successfully!');
                    closeModal('addStudentModal');
                    this.reset();
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 1500);
            });
        }

        // Announcement priority selection
        document.querySelectorAll('.announcement-priority').forEach(label => {
            label.addEventListener('click', function() {
                document.querySelectorAll('.announcement-priority').forEach(l => {
                    l.style.borderColor = '#e2e8f0';
                    l.style.backgroundColor = '';
                });

                this.style.borderColor = '#4f46e5';
                this.style.backgroundColor = '#f8fafc';

                const radio = this.querySelector('input[type="radio"]');
                if (radio) radio.checked = true;
            });
        });

        // Load sections when class is selected
        const classSelect = document.querySelector('select[name="class_id"]');
        if (classSelect) {
            classSelect.addEventListener('change', function() {
                const classId = this.value;
                const sectionSelect = document.querySelector('select[name="section_id"]');

                if (!sectionSelect) return;

                if (!classId) {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    return;
                }

                sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
                sectionSelect.disabled = true;

                setTimeout(() => {
                    sectionSelect.innerHTML = '<option value="">Select Section</option>';
                    sectionSelect.innerHTML += '<option value="1">Section A</option>';
                    sectionSelect.innerHTML += '<option value="2">Section B</option>';
                    sectionSelect.innerHTML += '<option value="3">Section C</option>';
                    sectionSelect.disabled = false;
                }, 500);
            });
        }

        // Export functions
        function exportGradeData() {
            Toast.success('Grade distribution data exported!<br>CSV file downloaded.');
        }

        function exportRevenueData() {
            Toast.success('Revenue data exported!<br>CSV file downloaded.');
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            try {
                initializeCharts();
            } catch (error) {
                console.error('Error initializing charts:', error);
            }

            // Add hover effects to quick actions
            const quickActions = document.querySelectorAll('.quick-action');
            quickActions.forEach(action => {
                action.addEventListener('mouseenter', () => {
                    action.style.transform = 'translateY(-4px)';
                });

                action.addEventListener('mouseleave', () => {
                    action.style.transform = 'translateY(0)';
                });
            });

            // Add click effects to announcement cards
            const announcementCards = document.querySelectorAll('.announcement-card');
            announcementCards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    card.style.transform = 'translateX(4px)';
                });

                card.addEventListener('mouseleave', () => {
                    card.style.transform = 'translateX(0)';
                });
            });

            // Welcome toast
            setTimeout(() => {
                Toast.success('Welcome to <?php echo htmlspecialchars($school["name"]); ?> Dashboard!', 4000);
            }, 1000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openModal('newAnnouncementModal');
                Toast.info('New announcement modal opened (Ctrl+N)');
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                openModal('addStudentModal');
                Toast.info('Add student modal opened (Ctrl+S)');
            }

            if (e.key === 'Escape') {
                closeModal('newAnnouncementModal');
                closeModal('addStudentModal');
            }

            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                window.location.reload();
                Toast.info('Dashboard refreshed (Ctrl+R)');
            }
        });
    </script>
</body>

</html>

<?php
// Helper function to format time ago
function time_ago($datetime)
{
    if (empty($datetime)) return 'recently';

    try {
        $time = strtotime($datetime);
        if ($time === false) return 'recently';

        $now = time();
        $diff = $now - $time;

        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M j, Y', $time);
        }
    } catch (Exception $e) {
        return 'recently';
    }
}
?>