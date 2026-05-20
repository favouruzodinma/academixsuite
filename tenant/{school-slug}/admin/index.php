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

// Initialize all variables
$settings = [];
$academicYear = null;
$academicTerm = null;
$totalStudents = 0;
$totalTeachers = 0;
$totalClasses = 0;
$totalSubjects = 0;
$attendanceRate = 0;
$attendanceStats = ['present' => 0, 'absent' => 0, 'late' => 0, 'halfday' => 0];
$announcements = [];
$upcomingEvents = [];
$recentActivities = [];
$gradeDistribution = [];
$weeklyAttendance = [];
$feeCollectionRate = 0;
$staffCount = 0;
$totalRevenue = 0;
$monthlyRevenue = 0;
$pendingPayments = 0;
$collectionRate = 0;
$recentTransactions = [];
$monthlyRevenueData = [];
$paymentMethodsData = [];
$leaveRequests = [];
$topTeachers = [];
$newAdmissions = [];
$topStudents = [];
$incomeVsExpense = ['income' => [], 'expense' => []];
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
$trialWarning = '';

// Notifications
$notifications = [];
$unreadCount = 0;

// Check if we have a valid school database connection before querying
if ($schoolDb) {
    try {
        // Get school settings
        error_log("Fetching school settings...");
        try {
            $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
            if ($settingsStmt) {
                $settingsStmt->execute([$school['id']]);
                $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($settingsRows as $row) {
                    $settingKey = $row['setting_key'] ?? $row['key'] ?? null;
                    if ($settingKey === null || $settingKey === '') {
                        continue;
                    }
                    $settings[$settingKey] = $row['setting_value'] ?? $row['value'] ?? '';
                }
                error_log("Settings fetched: " . count($settings) . " items");
            }
        } catch (Exception $e) {
            error_log("Error fetching settings: " . $e->getMessage());
        }

        // Get current academic year
        error_log("Fetching current academic year...");
        try {
            $academicYearStmt = $schoolDb->prepare("
                SELECT * FROM academic_years 
                WHERE school_id = ? AND status = 'active' 
                ORDER BY is_default DESC LIMIT 1
            ");
            if ($academicYearStmt) {
                $academicYearStmt->execute([$school['id']]);
                $academicYear = $academicYearStmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching academic year: " . $e->getMessage());
        }

        // Get current academic term
        if ($academicYear) {
            error_log("Fetching current academic term...");
            try {
                $academicTermStmt = $schoolDb->prepare("
                    SELECT * FROM academic_terms 
                    WHERE school_id = ? AND academic_year_id = ? AND is_default = 1 
                    LIMIT 1
                ");
                if ($academicTermStmt) {
                    $academicTermStmt->execute([$school['id'], $academicYear['id']]);
                    $academicTerm = $academicTermStmt->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                error_log("Error fetching academic term: " . $e->getMessage());
            }
        }

        // Get school statistics
        error_log("Fetching school statistics...");

        // Total Students
        try {
            $studentStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM students 
                WHERE school_id = ? AND status = 'active'
            ");
            if ($studentStmt) {
                $studentStmt->execute([$school['id']]);
                $studentResult = $studentStmt->fetch(PDO::FETCH_ASSOC);
                $totalStudents = $studentResult['count'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error counting students: " . $e->getMessage());
        }

        // Total Teachers
        try {
            $teacherStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM teachers 
                WHERE school_id = ? AND is_active = 1
            ");
            if ($teacherStmt) {
                $teacherStmt->execute([$school['id']]);
                $teacherResult = $teacherStmt->fetch(PDO::FETCH_ASSOC);
                $totalTeachers = $teacherResult['count'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error counting teachers: " . $e->getMessage());
        }

        // Total Classes
        try {
            $classStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM classes 
                WHERE school_id = ? AND is_active = 1
            ");
            if ($classStmt) {
                $classStmt->execute([$school['id']]);
                $classResult = $classStmt->fetch(PDO::FETCH_ASSOC);
                $totalClasses = $classResult['count'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error counting classes: " . $e->getMessage());
        }

        // Total Subjects
        try {
            $subjectStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM subjects 
                WHERE school_id = ? AND is_active = 1
            ");
            if ($subjectStmt) {
                $subjectStmt->execute([$school['id']]);
                $subjectResult = $subjectStmt->fetch(PDO::FETCH_ASSOC);
                $totalSubjects = $subjectResult['count'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error counting subjects: " . $e->getMessage());
        }

        // Staff Count
        try {
            $staffStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM staff 
                WHERE school_id = ? AND is_active = 1
            ");
            if ($staffStmt) {
                $staffStmt->execute([$school['id']]);
                $staffResult = $staffStmt->fetch(PDO::FETCH_ASSOC);
                $staffCount = $staffResult['count'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error counting staff: " . $e->getMessage());
        }

        // Today's attendance stats
        $today = date('Y-m-d');
        try {
            $attendanceStmt = $schoolDb->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'halfday' THEN 1 ELSE 0 END) as halfday
                FROM attendance 
                WHERE school_id = ? AND date = ?
            ");
            if ($attendanceStmt) {
                $attendanceStmt->execute([$school['id'], $today]);
                $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
                if ($attendance && $attendance['total'] > 0) {
                    $attendanceStats['present'] = $attendance['present'] ?? 0;
                    $attendanceStats['absent'] = $attendance['absent'] ?? 0;
                    $attendanceStats['late'] = $attendance['late'] ?? 0;
                    $attendanceStats['halfday'] = $attendance['halfday'] ?? 0;
                    $attendanceRate = round(($attendanceStats['present'] / $attendance['total']) * 100, 1);
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching attendance: " . $e->getMessage());
        }

        // Revenue metrics
        error_log("Calculating revenue metrics...");
        try {
            // Check payment_transactions table
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
                $revenueData = $revenueStmt->fetch(PDO::FETCH_ASSOC);

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
                $monthlyRevenueData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
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
                $paymentMethodsData = $methodsStmt->fetchAll(PDO::FETCH_ASSOC);
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
                $recentTransactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Calculate collection rate from invoices
            $collectionStmt = $schoolDb->prepare("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_invoices,
                    SUM(total_amount) as total_amount,
                    SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_amount
                FROM invoices 
                WHERE school_id = ? AND status NOT IN ('draft', 'cancelled')
            ");
            if ($collectionStmt) {
                $collectionStmt->execute([$school['id']]);
                $collectionData = $collectionStmt->fetch(PDO::FETCH_ASSOC);

                if ($collectionData && floatval($collectionData['total_amount'] ?? 0) > 0) {
                    $collectionRate = round((floatval($collectionData['paid_amount'] ?? 0) / floatval($collectionData['total_amount'] ?? 1)) * 100, 1);
                }
            }
        } catch (Exception $e) {
            error_log("Error calculating revenue: " . $e->getMessage());
        }

        // Recent announcements
        try {
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
                $announcements = $announcementStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching announcements: " . $e->getMessage());
        }

        // Upcoming events
        try {
            $eventStmt = $schoolDb->prepare("
                SELECT * FROM events 
                WHERE school_id = ? AND start_date >= CURDATE() 
                AND start_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                ORDER BY start_date ASC 
                LIMIT 5
            ");
            if ($eventStmt) {
                $eventStmt->execute([$school['id']]);
                $upcomingEvents = $eventStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching events: " . $e->getMessage());
        }

        // Recent activity logs
        try {
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
                $recentActivities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching activity logs: " . $e->getMessage());
        }

        // Grade distribution
        try {
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
                $gradeDistribution = $gradeStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching grade distribution: " . $e->getMessage());
        }

        // Weekly attendance trend
        try {
            $weekStmt = $schoolDb->prepare("
                SELECT 
                    DATE_FORMAT(date, '%Y-%u') as week,
                    MIN(date) as week_start,
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
                $weeklyAttendance = $weekStmt->fetchAll(PDO::FETCH_ASSOC);
                $weeklyAttendance = array_reverse($weeklyAttendance);
            }
        } catch (Exception $e) {
            error_log("Error fetching weekly attendance: " . $e->getMessage());
        }

        // Fee collection rate for current term
        if ($academicTerm) {
            try {
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
                    $feeData = $feeStmt->fetch(PDO::FETCH_ASSOC);
                    if ($feeData && $feeData['total_students'] > 0) {
                        $feeCollectionRate = round(($feeData['paid_students'] / $feeData['total_students']) * 100, 1);
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching fee collection rate: " . $e->getMessage());
            }
        }

        // Get logged in admin user details
        try {
            $userStmt = $schoolDb->prepare("
                SELECT u.*, r.name as role_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ? AND u.school_id = ?
            ");
            if ($userStmt) {
                $userStmt->execute([$userId, $school['id']]);
                $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($adminUserData) {
                    $adminUser = $adminUserData;
                } elseif (isset($_SESSION['school_user']['name'])) {
                    $adminUser = [
                        'name' => $_SESSION['school_user']['name'],
                        'role_name' => 'Administrator'
                    ];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching admin user: " . $e->getMessage());
        }

        // Get leave requests
        try {
            $leaveStmt = $schoolDb->prepare("
                SELECT lr.*, u.name as user_name, u.user_type,
                       lt.name as leave_type_name
                FROM leave_requests lr
                LEFT JOIN users u ON lr.user_id = u.id
                LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
                WHERE lr.school_id = ? AND lr.status = 'pending'
                ORDER BY lr.created_at DESC
                LIMIT 10
            ");
            if ($leaveStmt) {
                $leaveStmt->execute([$school['id']]);
                $leaveRequests = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching leave requests: " . $e->getMessage());
        }

        // ==================== NOTIFICATIONS ====================
        try {
            // Fetch recent notifications for current user
            $notifStmt = $schoolDb->prepare("
                SELECT * FROM notifications
                WHERE school_id = ? AND user_id = ?
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC
                LIMIT 10
            ");
            if ($notifStmt) {
                $notifStmt->execute([$school['id'], $userId]);
                $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Count unread notifications
            $unreadStmt = $schoolDb->prepare("
                SELECT COUNT(*) as unread FROM notifications
                WHERE school_id = ? AND user_id = ? AND is_read = 0
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
            if ($unreadStmt) {
                $unreadStmt->execute([$school['id'], $userId]);
                $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread'] ?? 0;
            }
        } catch (Exception $e) {
            error_log("Error fetching notifications: " . $e->getMessage());
        }

        // ==================== TOP TEACHERS ====================
        try {
            // Check if staff_attendance table exists
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'staff_attendance'");
            $hasStaffAttendance = $tableCheck->rowCount() > 0;

            if ($hasStaffAttendance) {
                // Get teacher attendance rate over last 30 days
                $teacherStmt = $schoolDb->prepare("
                    SELECT 
                        u.id,
                        u.name,
                        u.email,
                        u.profile_photo as avatar,
                        COUNT(CASE WHEN sa.status = 'present' THEN 1 END) * 100.0 / COUNT(sa.id) as attendance_rate,
                        COUNT(DISTINCT cs.id) as subject_count
                    FROM users u
                    INNER JOIN teachers t ON u.id = t.user_id AND t.school_id = ?
                    LEFT JOIN staff_attendance sa ON u.id = sa.user_id 
                        AND sa.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        AND sa.school_id = ?
                    LEFT JOIN class_subjects cs ON cs.teacher_id = u.id
                    WHERE u.school_id = ? AND u.user_type = 'teacher' AND u.is_active = 1
                    GROUP BY u.id
                    HAVING COUNT(sa.id) > 0
                    ORDER BY attendance_rate DESC
                    LIMIT 5
                ");
                $teacherStmt->execute([$school['id'], $school['id'], $school['id']]);
                $topTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // If no attendance data or table missing, fallback to subject count
            if (empty($topTeachers)) {
                $teacherStmt = $schoolDb->prepare("
                    SELECT 
                        u.id,
                        u.name,
                        u.email,
                        u.profile_photo as avatar,
                        COUNT(DISTINCT cs.id) as subject_count
                    FROM users u
                    INNER JOIN teachers t ON u.id = t.user_id AND t.school_id = ?
                    LEFT JOIN class_subjects cs ON cs.teacher_id = u.id
                    WHERE u.school_id = ? AND u.user_type = 'teacher' AND u.is_active = 1
                    GROUP BY u.id
                    ORDER BY subject_count DESC
                    LIMIT 5
                ");
                $teacherStmt->execute([$school['id'], $school['id']]);
                $topTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Error fetching top teachers: " . $e->getMessage());
            $topTeachers = [];
        }

        // ==================== NEW ADMISSIONS (Boys/Girls) ====================
        $boysAdmissions = 0;
        $girlsAdmissions = 0;
        $totalAdmissions = 0;
        try {
            // First query: Get boys count (handling different gender formats)
            $boysStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM students 
                WHERE school_id = ? 
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND (
                    LOWER(gender) IN ('male', 'm', 'boy', 'boys', '1') 
                    OR gender = 'Male' 
                    OR gender = 'MALE'
                )
            ");
            if ($boysStmt) {
                $boysStmt->execute([$school['id']]);
                $boysResult = $boysStmt->fetch(PDO::FETCH_ASSOC);
                $boysAdmissions = (int)($boysResult['count'] ?? 0);
            }
            
            // Second query: Get girls count (handling different gender formats)
            $girlsStmt = $schoolDb->prepare("
                SELECT COUNT(*) as count FROM students 
                WHERE school_id = ? 
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND (
                    LOWER(gender) IN ('female', 'f', 'girl', 'girls', '2') 
                    OR gender = 'female' 
                    OR gender = 'FEMALE'
                )
            ");
            if ($girlsStmt) {
                $girlsStmt->execute([$school['id']]);
                $girlsResult = $girlsStmt->fetch(PDO::FETCH_ASSOC);
                $girlsAdmissions = (int)($girlsResult['count'] ?? 0);
            }
            
            // Alternative query if gender column has different values
            if ($boysAdmissions === 0 && $girlsAdmissions === 0) {
                $genderStmt = $schoolDb->prepare("
                    SELECT 
                        gender,
                        COUNT(*) as count
                    FROM students 
                    WHERE school_id = ? 
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND gender IS NOT NULL 
                    AND gender != ''
                    GROUP BY gender
                ");
                if ($genderStmt) {
                    $genderStmt->execute([$school['id']]);
                    $genderResults = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($genderResults as $row) {
                        $gender = strtolower(trim($row['gender']));
                        if (in_array($gender, ['male', 'm', 'boy', '1'])) {
                            $boysAdmissions += (int)$row['count'];
                        } elseif (in_array($gender, ['female', 'f', 'girl', '2'])) {
                            $girlsAdmissions += (int)$row['count'];
                        }
                    }
                }
            }
            
            $totalAdmissions = $boysAdmissions + $girlsAdmissions;
        } catch (Exception $e) {
            error_log("Error fetching new admissions: " . $e->getMessage());
        }

        // ==================== TOP STUDENTS ====================
        try {
            // Check if exam_grades table exists
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'exam_grades'");
            $hasExamGrades = $tableCheck->rowCount() > 0;

            if ($hasExamGrades) {
                $studentStmt = $schoolDb->prepare("
                    SELECT 
                        s.id,
                        s.first_name,
                        s.last_name,
                        s.admission_number,
                        c.name as class_name,
                        s.profile_photo as avatar,
                        AVG(eg.marks_obtained * 100.0 / eg.total_marks) as avg_percentage
                    FROM students s
                    LEFT JOIN classes c ON s.class_id = c.id
                    LEFT JOIN exam_grades eg ON s.id = eg.student_id AND eg.school_id = ?
                    WHERE s.school_id = ? AND s.status = 'active'
                    GROUP BY s.id
                    HAVING avg_percentage IS NOT NULL
                    ORDER BY avg_percentage DESC
                    LIMIT 5
                ");
                $studentStmt->execute([$school['id'], $school['id']]);
                $topStudents = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // If no exam grades, fallback to recent students (or empty)
            if (empty($topStudents)) {
                $studentStmt = $schoolDb->prepare("
                    SELECT 
                        s.id,
                        s.first_name,
                        s.last_name,
                        s.admission_number,
                        c.name as class_name,
                        s.profile_photo as avatar
                    FROM students s
                    LEFT JOIN classes c ON s.class_id = c.id
                    WHERE s.school_id = ? AND s.status = 'active'
                    ORDER BY s.created_at DESC
                    LIMIT 5
                ");
                $studentStmt->execute([$school['id']]);
                $topStudents = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
                // Add a placeholder percentage
                foreach ($topStudents as &$student) {
                    $student['avg_percentage'] = 0;
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching top students: " . $e->getMessage());
            $topStudents = [];
        }

        // Get income vs expense data for last 6 months
        try {
            // Income from transactions
            $incomeStmt = $schoolDb->prepare("
                SELECT 
                    DATE_FORMAT(created_at, '%b') as month,
                    SUM(amount) as income
                FROM payment_transactions 
                WHERE school_id = ? 
                AND status = 'success'
                AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
                ORDER BY MIN(created_at)
            ");
            if ($incomeStmt) {
                $incomeStmt->execute([$school['id']]);
                $incomeData = $incomeStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Expense from expense records
            $expenseStmt = $schoolDb->prepare("
                SELECT 
                    DATE_FORMAT(expense_date, '%b') as month,
                    SUM(amount) as expense
                FROM expenses 
                WHERE school_id = ? 
                AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                GROUP BY DATE_FORMAT(expense_date, '%Y-%m'), DATE_FORMAT(expense_date, '%b')
                ORDER BY MIN(expense_date)
            ");
            if ($expenseStmt) {
                $expenseStmt->execute([$school['id']]);
                $expenseData = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Merge data
            $months = [];
            foreach ($incomeData as $inc) {
                $months[$inc['month']] = [
                    'income' => $inc['income'],
                    'expense' => 0
                ];
            }
            foreach ($expenseData as $exp) {
                if (isset($months[$exp['month']])) {
                    $months[$exp['month']]['expense'] = $exp['expense'];
                } else {
                    $months[$exp['month']] = [
                        'income' => 0,
                        'expense' => $exp['expense']
                    ];
                }
            }
            
            // Sort by month order
            $monthOrder = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            uksort($months, function($a, $b) use ($monthOrder) {
                return array_search($a, $monthOrder) - array_search($b, $monthOrder);
            });

            foreach ($months as $month => $values) {
                $incomeVsExpense['months'][] = $month;
                $incomeVsExpense['income'][] = $values['income'] / 1000; // Convert to thousands
                $incomeVsExpense['expense'][] = $values['expense'] / 1000;
            }
        } catch (Exception $e) {
            error_log("Error fetching income vs expense: " . $e->getMessage());
        }

        error_log("All data fetched successfully from school database");
    } catch (Exception $e) {
        error_log("ERROR in database operations: " . $e->getMessage());
    }
} else {
    error_log("School database connection failed or not available");
}

// Check trial status
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

// Helper function for time ago
function timeAgo($datetime) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'just now';
}

error_log("=================== SCHOOL DASHBOARD END ===================");
?>
<!-- meta tags and other links -->
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Modern Education Admin Dashboard for schools">
    <meta name="keywords" content="Education Admin Dashboard, School Admin Panel">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
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
    
    
    
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
    
    <?php include_once('includes/sidebar.php') ?>

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
                                <?php if ($unreadCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                                <div class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                    <div>
                                        <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                    </div>
                                    <span class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?php echo count($notifications); ?></span>
                                </div>
                                <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                    <?php if (!empty($notifications)): ?>
                                        <?php foreach ($notifications as $notif): ?>
                                        <a href="notification.php?id=<?php echo $notif['id']; ?>" class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between">
                                            <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                                <span class="w-44-px h-44-px bg-success-subtle text-success-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                    <iconify-icon icon="bitcoin-icons:verify-outline" class="icon text-xxl"></iconify-icon>
                                                </span>
                                                <div>
                                                    <h6 class="text-md fw-semibold mb-4"><?php echo htmlspecialchars($notif['title']); ?></h6>
                                                    <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?php echo htmlspecialchars(substr($notif['message'] ?? '', 0, 50)) . '...'; ?></p>
                                                </div>
                                            </div>
                                            <span class="text-sm text-secondary-light flex-shrink-0">
                                                <?php echo timeAgo($notif['created_at']); ?>
                                            </span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-20">
                                            <p class="text-secondary-light">No new notifications</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-center py-12 px-16">
                                    <a href="notifications.php" class="text-primary-600 fw-semibold text-md hover-underline">See All Notifications</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h6 class="fw-semibold mb-0">Dashboard - <?php echo htmlspecialchars($school['name']); ?></h6>
                    <p class="text-neutral-600 mt-4 mb-0">
                        <?php echo htmlspecialchars($school['address'] ?? 'Manage your school, track attendance, expense, and revenue.'); ?>
                        <?php if (!empty($trialWarning)): ?>
                            <span class="text-warning-600 fw-semibold ms-2">⚠️ <?php echo $trialWarning; ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="">
                    <?php if ($academicYear): ?>
                    <span class="badge bg-primary-100 text-primary-600 px-16 py-8 radius-8">
                        Academic Year: <?php echo htmlspecialchars($academicYear['name']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-24">
                <div class="row gy-4">
                    <div class="col-xxl-8">
                        <div class="row gy-4">
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-1 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-warning-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon1.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Total Students</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo number_format($totalStudents); ?></h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <?php if ($attendanceRate > 0): ?>
                                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                                <?php echo $attendanceRate; ?>%
                                                <iconify-icon icon="bxs:up-arrow" class="text-xs"></iconify-icon>
                                            </span>
                                            Attendance Rate Today
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-2 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-blue-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon2.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Total Teachers</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo number_format($totalTeachers); ?></h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <?php if ($totalClasses > 0): ?>
                                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                                <?php echo $totalClasses; ?> Classes
                                            </span>
                                            <?php echo $totalSubjects; ?> Subjects
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-3 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-purple-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon3.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Total Revenue</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo $currencySymbol . ' ' . number_format($totalRevenue, 2); ?></h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                                <?php echo $currencySymbol . ' ' . number_format($monthlyRevenue, 2); ?>
                                            </span>
                                            This Month
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-4 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-primary-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon4.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Pending Payments</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo $currencySymbol . ' ' . number_format($pendingPayments, 2); ?></h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                                <?php echo $collectionRate; ?>%
                                            </span>
                                            Collection Rate
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-5 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-success-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon5.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Active Classes</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo number_format($totalClasses); ?></h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                                <?php echo $totalSubjects; ?> Subjects
                                            </span>
                                            Across Classes
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4 col-sm-6">
                                <div class="card shadow-1 radius-8 gradient-bg-end-6 h-100">
                                    <div class="card-body p-20">
                                        <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                            <div class="w-44-px h-44-px bg-cyan-600 rounded-circle d-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/dashboard-icon6.png" alt="Icon">
                                            </div>
                                            <p class="fw-medium text-primary-light mb-1">Fee Collection</p>
                                        </div>
                                        <h6 class="mb-0"><?php echo $feeCollectionRate; ?>%</h6>
                                        <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                            <?php if ($academicTerm): ?>
                                            <span class="text-xs"><?php echo htmlspecialchars($academicTerm['name']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4">
                        <div class="card h-100">
                            <div class="card-body p-0">
                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                    <h6 class="text-lg mb-0">Student Attendance</h6>
                                </div>
                                <div class="p-20">
                                    <?php
                                    $totalAttendance = array_sum($attendanceStats);
                                    $presentPercent = $totalAttendance > 0 ? round(($attendanceStats['present'] / $totalAttendance) * 100, 1) : 0;
                                    $absentPercent = $totalAttendance > 0 ? round(($attendanceStats['absent'] / $totalAttendance) * 100, 1) : 0;
                                    $latePercent = $totalAttendance > 0 ? round(($attendanceStats['late'] / $totalAttendance) * 100, 1) : 0;
                                    $halfdayPercent = $totalAttendance > 0 ? round(($attendanceStats['halfday'] / $totalAttendance) * 100, 1) : 0;
                                    ?>
                                    <div class="d-flex gap-6">
                                        <div class="h-44-px bg-primary-600 rounded" style="width: <?php echo $presentPercent; ?>%;"></div>
                                        <div class="h-44-px bg-warning-600 rounded" style="width: <?php echo $absentPercent; ?>%;"></div>
                                        <div class="h-44-px bg-purple-600 rounded" style="width: <?php echo $latePercent; ?>%;"></div>
                                        <div class="h-44-px bg-success-600 rounded" style="width: <?php echo $halfdayPercent; ?>%;"></div>
                                    </div>
                                    <div class="mt-32 d-flex flex-column gap-24">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="w-12-px h-12-px radius-2 bg-primary-600"></span>
                                                <span class="text-neutral-600">Present</span>
                                            </div>
                                            <span class="fw-semibold text-primary-light"><?php echo number_format($presentPercent, 1); ?>% (<?php echo $attendanceStats['present']; ?>)</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="w-12-px h-12-px radius-2 bg-warning-600"></span>
                                                <span class="text-neutral-600">Absent</span>
                                            </div>
                                            <span class="fw-semibold text-primary-light"><?php echo number_format($absentPercent, 1); ?>% (<?php echo $attendanceStats['absent']; ?>)</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="w-12-px h-12-px radius-2 bg-purple-600"></span>
                                                <span class="text-neutral-600">Late</span>
                                            </div>
                                            <span class="fw-semibold text-primary-light"><?php echo number_format($latePercent, 1); ?>% (<?php echo $attendanceStats['late']; ?>)</span>
                                        </div>
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="w-12-px h-12-px radius-2 bg-success-600"></span>
                                                <span class="text-neutral-600">Half day</span>
                                            </div>
                                            <span class="fw-semibold text-primary-light"><?php echo number_format($halfdayPercent, 1); ?>% (<?php echo $attendanceStats['halfday']; ?>)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="row gy-4">
                            <div class="col-xxl-8">
                                <div class="row gy-4">
                                    <div class="col-12">
                                        <div class="card h-100">
                                            <div class="card-body p-0">
                                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                                    <h6 class="text-lg mb-0">Revenue Statistic (Last 6 Months)</h6>
                                                </div>
                                                <div class="p-20">
                                                    <ul class="d-flex flex-wrap align-items-center justify-content-center mb-16 gap-3">
                                                        <li class="d-flex align-items-center gap-8">
                                                            <span class="w-12-px h-12-px radius-2 rotate-45-deg bg-primary-600"></span>
                                                            <span class="text-secondary-light text-sm fw-semibold">
                                                                Total Fee:
                                                                <span class="text-primary-light fw-bold"><?php echo $currencySymbol . number_format($totalRevenue, 2); ?></span>
                                                            </span>
                                                        </li>
                                                        <li class="d-flex align-items-center gap-8">
                                                            <span class="w-12-px h-12-px radius-2 rotate-45-deg bg-warning-600"></span>
                                                            <span class="text-secondary-light text-sm font-semibold">
                                                                Collected:
                                                                <span class="text-primary-light fw-bold"><?php echo $currencySymbol . number_format($totalRevenue - $pendingPayments, 2); ?></span>
                                                            </span>
                                                        </li>
                                                    </ul>
                                                    <div id="revenueStatistic" data-monthly='<?php echo json_encode($monthlyRevenueData); ?>'></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card h-100">
                                            <div class="card-body p-0">
                                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                                    <h6 class="text-lg mb-0">Notice Board</h6>
                                                    <a href="notice-board.php" class="text-primary-600">View All</a>
                                                </div>
                                                <div class="ps-20 pt-20 pb-20">
                                                    <div class="pe-20 d-flex flex-column gap-20 max-h-462-px overflow-y-auto scroll-sm">
                                                        <?php if (!empty($announcements)): ?>
                                                            <?php foreach ($announcements as $notice): ?>
                                                            <div class="d-flex align-items-start gap-16">
                                                                <img src="<?php echo $notice['created_by_avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/notice-board-img1.png'; ?>" alt="Thumbnail" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                                                                <div class="">
                                                                    <h6 class="mb-4 text-lg"><?php echo htmlspecialchars($notice['created_by_name'] ?? 'Admin'); ?></h6>
                                                                    <p class="text-secondary-light text-sm mb-0"><?php echo htmlspecialchars($notice['content'] ?? $notice['description'] ?? $notice['title'] ?? ''); ?></p>
                                                                    <span class="text-secondary-light text-sm mb-0 mt-4">
                                                                        <?php echo date('d M Y', strtotime($notice['created_at'])); ?>
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="text-center py-20">
                                                                <p class="text-secondary-light">No notices available</p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card h-100">
                                            <div class="card-body p-0">
                                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                                    <h6 class="text-lg mb-0">Leave Requests</h6>
                                                    <a href="leave-requests.php" class="text-primary-600">View All</a>
                                                </div>
                                                <div class="ps-20 pt-20 pb-20">
                                                    <div class="pe-20 d-flex flex-column gap-28 max-h-462-px overflow-y-auto scroll-sm">
                                                        <?php if (!empty($leaveRequests)): ?>
                                                            <?php foreach ($leaveRequests as $leave): ?>
                                                            <div class="d-flex align-items-center justify-content-between gap-16">
                                                                <div class="d-flex align-items-start gap-16">
                                                                    <img src="<?php echo $leave['avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/leave-request-img1.png'; ?>" alt="Thumbnail" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                                                                    <div class="">
                                                                        <h6 class="mb-0 text-lg"><?php echo htmlspecialchars($leave['user_name']); ?></h6>
                                                                        <span class="text-secondary-light text-sm mb-0"><?php echo htmlspecialchars($leave['user_type']); ?></span>
                                                                    </div>
                                                                </div>
                                                                <div class="text-end">
                                                                    <span class="d-block fw-bold text-primary-light"><?php echo $leave['days']; ?> Days</span>
                                                                    <p class="text-secondary-light text-sm mb-0">Apply on: <?php echo date('d M', strtotime($leave['created_at'])); ?></p>
                                                                </div>
                                                            </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <div class="text-center py-20">
                                                                <p class="text-secondary-light">No pending leave requests</p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xxl-4">
                                <div class="card h-100">
                                    <div class="card-body p-0">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                            <h6 class="text-lg mb-0">Calendar</h6>
                                        </div>
                                        <div class="p-20">
                                            <div class="calendar">
                                                <div class="calendar__header">
                                                    <button type="button" class="calendar__arrow left">
                                                        <i class="ri-arrow-left-s-line"></i>
                                                    </button>
                                                    <p class="display text-md text-secondary-light fw-semibold mb-0"></p>
                                                    <button type="button" class="calendar__arrow right">
                                                        <i class="ri-arrow-right-s-line"></i>
                                                    </button>
                                                </div>
                                                <div class="calendar__week week">
                                                    <div class="calendar__week-text">Su</div>
                                                    <div class="calendar__week-text">Mo</div>
                                                    <div class="calendar__week-text">Tu</div>
                                                    <div class="calendar__week-text">We</div>
                                                    <div class="calendar__week-text">Th</div>
                                                    <div class="calendar__week-text">Fr</div>
                                                    <div class="calendar__week-text">Sa</div>
                                                </div>
                                                <div class="days"></div>
                                            </div>
                                        </div>
                                        <div class="ps-20 pt-20 pb-20 border-top border-neutral-200">
                                            <h6 class="text-lg mb-20">Upcoming Events</h6>
                                            <div class="pe-20 d-flex flex-column gap-32 overflow-y-auto max-h-500-px scroll-sm">
                                                <?php if (!empty($upcomingEvents)): ?>
                                                    <?php foreach ($upcomingEvents as $event): ?>
                                                    <div class="d-flex align-items-center justify-content-between gap-16">
                                                        <div class="ps-10 border-start-width-3-px border-purple-600">
                                                            <div class="d-flex align-items-end gap-6">
                                                                <h6 class="text-lg fw-normal mb-0">
                                                                    <?php echo date('H:i', strtotime($event['start_time'] ?? '09:00:00')); ?>
                                                                </h6>
                                                                <span class="text-xs text-secondary-light line-height-1 mb-2">
                                                                    <?php echo date('A', strtotime($event['start_time'] ?? '09:00:00')); ?>
                                                                </span>
                                                            </div>
                                                            <p class="text-secondary-light mt-4 mb-2 text-sm"><?php echo htmlspecialchars($event['title']); ?></p>
                                                            <p class="text-xs text-secondary-light mb-0">
                                                                <?php echo date('M d', strtotime($event['start_date'])); ?>
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <a href="event.php?id=<?php echo $event['id']; ?>" class="py-6 px-16 radius-4 bg-neutral-100 text-secondary-light fw-semibold bg-hover-primary-600 hover-text-white">View</a>
                                                        </div>
                                                    </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="text-center py-20">
                                                        <p class="text-secondary-light">No upcoming events</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-4 col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-0">
                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                    <h6 class="text-lg mb-0">User Overview</h6>
                                </div>
                                <div class="p-20">
                                    <div>
                                        <?php
                                        $totalUsers = $totalStudents + $totalTeachers + $staffCount;
                                        $studentPercent = $totalUsers > 0 ? round(($totalStudents / $totalUsers) * 100) : 0;
                                        $teacherPercent = $totalUsers > 0 ? round(($totalTeachers / $totalUsers) * 100) : 0;
                                        $staffPercent = $totalUsers > 0 ? round(($staffCount / $totalUsers) * 100) : 0;
                                        ?>
                                        <div class="mt-40 mb-24 pe-110 position-relative max-w-288-px mx-auto">
                                            <div class="w-170-px h-170-px rounded-circle z-1 position-relative d-inline-flex justify-content-center align-items-center">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/radial-bg1.png" alt="Image" class="position-absolute top-0 start-0 z-n1 w-100 h-100 object-fit-cover">
                                                <h5 class="text-white"><?php echo $studentPercent; ?>%</h5>
                                            </div>
                                            <div class="w-144-px h-144-px rounded-circle z-1 position-relative d-inline-flex justify-content-center align-items-center position-absolute top-0 end-0 mt--36">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/radial-bg2.png" alt="Image" class="position-absolute top-0 start-0 z-n1 w-100 h-100 object-fit-cover">
                                                <h5 class="text-white"><?php echo $teacherPercent; ?>%</h5>
                                            </div>
                                            <div class="w-110-px h-110-px rounded-circle z-1 position-relative d-inline-flex justify-content-center align-items-center position-absolute bottom-0 start-50 translate-middle-x ms-48">
                                                <img src="https://academixsuite.com/tenant/assets/images/icons/radial-bg3.png" alt="Image" class="position-absolute top-0 start-0 z-n1 w-100 h-100 object-fit-cover">
                                                <h5 class="text-white"><?php echo $staffPercent; ?>%</h5>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center flex-wrap gap-24 justify-content-evenly">
                                            <div class="d-flex flex-column align-items-start">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="w-12-px h-12-px rounded-pill bg-success-600"></span>
                                                    <span class="text-secondary-light text-sm fw-normal">Students</span>
                                                </div>
                                                <h6 class="text-primary-light fw-semibold mb-0 mt-4 text-lg"><?php echo number_format($totalStudents); ?></h6>
                                            </div>
                                            <div class="d-flex flex-column align-items-start">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="w-12-px h-12-px rounded-pill bg-warning-600"></span>
                                                    <span class="text-secondary-light text-sm fw-normal">Teachers</span>
                                                </div>
                                                <h6 class="text-primary-light fw-semibold mb-0 mt-4 text-lg"><?php echo number_format($totalTeachers); ?></h6>
                                            </div>
                                            <div class="d-flex flex-column align-items-start">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="w-12-px h-12-px rounded-pill bg-blue-600"></span>
                                                    <span class="text-secondary-light text-sm fw-normal">Staff</span>
                                                </div>
                                                <h6 class="text-primary-light fw-semibold mb-0 mt-4 text-lg"><?php echo number_format($staffCount); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xxl-8 col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-0">
                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                    <h6 class="text-lg mb-0">Income Vs Expense (Last 6 Months)</h6>
                                </div>
                                <div class="p-20">
                                    <ul class="d-flex flex-wrap align-items-center justify-content-center mb-16 gap-3">
                                        <li class="d-flex align-items-center gap-8">
                                            <span class="w-12-px h-12-px rounded-circle bg-primary-600"></span>
                                            <span class="text-secondary-light text-sm fw-semibold">
                                                Income:
                                                <span class="text-primary-light fw-bold"><?php echo $currencySymbol . number_format($totalRevenue, 2); ?></span>
                                            </span>
                                        </li>
                                        <li class="d-flex align-items-center gap-8">
                                            <span class="w-12-px h-12-px rounded-circle bg-warning-600"></span>
                                            <span class="text-secondary-light text-sm font-semibold">
                                                Expense:
                                                <span class="text-primary-light fw-bold"><?php echo $currencySymbol . number_format(array_sum($incomeVsExpense['expense'] ?? []) * 1000, 2); ?></span>
                                            </span>
                                        </li>
                                    </ul>
                                    <div id="incomeExpense" 
                                         data-income='<?php echo json_encode($incomeVsExpense['income'] ?? []); ?>'
                                         data-expense='<?php echo json_encode($incomeVsExpense['expense'] ?? []); ?>'
                                         data-months='<?php echo json_encode($incomeVsExpense['months'] ?? []); ?>'
                                         class="apexcharts-tooltip-style-1"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Top Teachers Block (UPDATED) -->
                    <div class="col-xxl-4 col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-0">
                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                    <h6 class="text-lg mb-0">Top Teachers</h6>
                                    <a href="teacher-list.php" class="text-primary-600">View All</a>
                                </div>
                                <div class="ps-20 pt-20 pb-20">
                                    <div class="pe-20 d-flex flex-column gap-20 max-h-462-px overflow-y-auto scroll-sm">
                                        <?php if (!empty($topTeachers)): ?>
                                            <?php foreach ($topTeachers as $teacher): ?>
                                            <div class="d-flex align-items-center justify-content-between gap-16">
                                                <div class="d-flex align-items-start gap-16">
                                                    <img src="<?php echo htmlspecialchars($teacher['avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/top-teacher-img1.png'); ?>" 
                                                         alt="Teacher" class="w-40-px h-40-px rounded-circle object-fit-cover flex-shrink-0">
                                                    <div class="">
                                                        <h6 class="mb-0 text-lg"><?php echo htmlspecialchars($teacher['name']); ?></h6>
                                                        <span class="text-secondary-light text-sm mb-0"><?php echo htmlspecialchars($teacher['email']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <?php if (isset($teacher['attendance_rate'])): ?>
                                                        <span class="d-block fw-semibold text-primary-light"><?php echo round($teacher['attendance_rate']); ?>%</span>
                                                        <span class="text-xs text-secondary-light">Attendance</span>
                                                    <?php else: ?>
                                                        <span class="d-block fw-semibold text-primary-light"><?php echo $teacher['subject_count'] ?? 0; ?></span>
                                                        <span class="text-xs text-secondary-light">Subjects</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-center py-20">
                                                <p class="text-secondary-light">No teacher data available</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- New Admissions Block -->
                    <div class="col-xxl-4 col-lg-6">
                        <div class="card h-100">
                            <div class="card-body p-0">
                                <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-16 border-bottom border-neutral-200">
                                    <h6 class="text-lg mb-0">New Admissions (Last 30 Days)</h6>
                                    <a href="admission-list.php" class="text-primary-600">View All</a>
                                </div>
                                <div class="p-20">
                                    <?php
                                    $boysPercentage = $totalAdmissions > 0 ? round(($boysAdmissions / $totalAdmissions) * 100) : 0;
                                    $girlsPercentage = $totalAdmissions > 0 ? round(($girlsAdmissions / $totalAdmissions) * 100) : 0;
                                    $admissionsData = [$boysAdmissions, $girlsAdmissions];
                                    ?>
                                    <div class="position-relative text-center">
                                        <div id="newAdmissions" 
                                             data-admissions='<?php echo json_encode($admissionsData); ?>'
                                             data-boys="<?php echo $boysAdmissions; ?>"
                                             data-girls="<?php echo $girlsAdmissions; ?>"
                                             class="y-value-left apexcharts-tooltip-z-none"></div>
                                        <div class="text-center position-absolute top-50 start-50 translate-middle">
                                            <h5 class="mb-4"><?php echo number_format($totalAdmissions); ?></h5>
                                            <span class="text-secondary-light">Total Admissions</span>
                                        </div>
                                    </div>
                                    <ul class="d-flex flex-wrap align-items-center justify-content-center mt-48 gap-24">
                                        <li class="d-flex align-items-center gap-2">
                                            <span class="w-12-px h-12-px radius-2 bg-warning-600 rotate-45-deg"></span>
                                            <div class="">
                                                <span class="text-secondary-light fw-medium">
                                                    Boys:
                                                    <span class="fw-bold text-primary-light"><?php echo number_format($boysAdmissions); ?> (<?php echo $boysPercentage; ?>%)</span>
                                                </span>
                                            </div>
                                        </li>
                                        <li class="d-flex align-items-center gap-2">
                                            <span class="w-12-px h-12-px radius-2 bg-primary-600 rotate-45-deg"></span>
                                            <div class="">
                                                <span class="text-secondary-light fw-medium">
                                                    Girls:
                                                    <span class="fw-bold text-primary-light"><?php echo number_format($girlsAdmissions); ?> (<?php echo $girlsPercentage; ?>%)</span>
                                                </span>
                                            </div>
                                        </li>
                                    </ul>
                                    <?php if ($totalAdmissions > 0): ?>
                                    <div class="mt-20">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span class="text-xs text-secondary-light">Boys (<?php echo $boysPercentage; ?>%)</span>
                                            <span class="text-xs text-secondary-light">Girls (<?php echo $girlsPercentage; ?>%)</span>
                                        </div>
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-warning-600" role="progressbar" 
                                                 style="width: <?php echo $boysPercentage; ?>%;" 
                                                 aria-valuenow="<?php echo $boysPercentage; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                            <div class="progress-bar bg-primary-600" role="progressbar" 
                                                 style="width: <?php echo $girlsPercentage; ?>%;" 
                                                 aria-valuenow="<?php echo $girlsPercentage; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Students Block (UPDATED) -->
                    <div class="col-xxl-4">
                        <div class="card radius-12 border-0 h-100">
                            <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between py-12 px-20 border-bottom border-neutral-200">
                                <h6 class="mb-2 fw-bold text-lg">Top Students</h6>
                                <a href="student-list.php" class="text-primary-600">View All</a>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-column gap-28">
                                    <?php if (!empty($topStudents)): ?>
                                        <?php foreach ($topStudents as $index => $student): ?>
                                        <?php
                                        $color = ['blue', 'red', 'warning', 'green', 'blue'][$index % 5];
                                        $percentage = min(100, round($student['avg_percentage'] ?? 0));
                                        $name = htmlspecialchars($student['first_name'] . ' ' . ($student['last_name'] ?? ''));
                                        $className = htmlspecialchars($student['class_name'] ?? 'N/A');
                                        ?>
                                        <div class="d-flex align-items-center justify-content-between gap-10">
                                            <div class="d-flex align-items-center gap-12">
                                                <span class="w-44-px h-44-px rounded-circle d-flex justify-content-center align-items-center">
                                                    <img src="<?php echo htmlspecialchars($student['avatar'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img' . ($index + 1) . '.png'); ?>" 
                                                         class="w-44-px h-44-px object-fit-cover rounded-circle" alt="Student">
                                                </span>
                                                <div class="">
                                                    <h6 class="text-sm mb-2"><?php echo $name; ?></h6>
                                                    <span class="text-xs text-secondary-light">Class: <?php echo $className; ?></span>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center gap-8">
                                                <span class="text-sm text-secondary-light">Marks</span>
                                                <span class="text-primary-light text-sm d-block text-end">
                                                    <svg class="radial-progress w-44-px" data-percentage="<?php echo $percentage; ?>" viewBox="0 0 80 80">
                                                        <circle class="incomplete stroke-8-px opacity-02 stroke-<?php echo $color; ?>" cx="40" cy="40" r="35"></circle>
                                                        <circle class="complete stroke-8-px stroke-<?php echo $color; ?>" cx="40" cy="40" r="35"></circle>
                                                        <text class="percentage fill-black" x="50%" y="57%" transform="matrix(0, 1, -1, 0, 80, 0)"><?php echo $percentage; ?></text>
                                                    </svg>
                                                </span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-20">
                                            <p class="text-secondary-light">No student data available</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        // Revenue Statistics Chart
        var monthlyDataElement = document.querySelector("#revenueStatistic");
        var monthlyData = monthlyDataElement ? JSON.parse(monthlyDataElement.dataset.monthly || '[]') : [];
        
        var revenueArray = [];
        var months = [];
        
        if (monthlyData.length > 0) {
            monthlyData.forEach(function(item) {
                revenueArray.push(item.revenue / 1000);
                months.push(item.month_name);
            });
        } else {
            revenueArray = [0, 0, 0, 0, 0, 0];
            months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        }

        var options = {
            series: [{
                name: 'Total Fee',
                data: revenueArray
            }, {
                name: 'Collected Fee',
                data: revenueArray.map(function(value) { return value * 0.8; })
            }],
            chart: {
                type: 'bar',
                height: 250,
                stacked: true,
                toolbar: { show: false }
            },
            colors: ["#25A194", "#FF7A2C"],
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: "50%"
                }
            },
            xaxis: { categories: months },
            yaxis: {
                labels: {
                    formatter: function(value) {
                        return "<?php echo $currencySymbol; ?>" + value.toFixed(1) + "k";
                    }
                }
            },
            legend: { show: false },
            fill: { opacity: 1 }
        };

        var chart = new ApexCharts(document.querySelector("#revenueStatistic"), options);
        chart.render();

        // Income Vs Expense Chart
        var incomeExpenseEl = document.querySelector("#incomeExpense");
        var incomeData = incomeExpenseEl ? JSON.parse(incomeExpenseEl.dataset.income || '[]') : [];
        var expenseData = incomeExpenseEl ? JSON.parse(incomeExpenseEl.dataset.expense || '[]') : [];
        var monthLabels = incomeExpenseEl ? JSON.parse(incomeExpenseEl.dataset.months || '[]') : [];

        if (incomeData.length === 0) {
            incomeData = [0, 0, 0, 0, 0, 0];
            expenseData = [0, 0, 0, 0, 0, 0];
            monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
        }

        var options2 = {
            series: [{
                name: 'Income',
                data: incomeData
            }, {
                name: 'Expense',
                data: expenseData
            }],
            legend: { show: false },
            chart: {
                type: 'area',
                width: '100%',
                height: 260,
                toolbar: { show: false }
            },
            dataLabels: { enabled: false },
            stroke: {
                curve: 'stepline',
                width: 2,
                colors: ['#16a34a', '#FF9F29'],
                lineCap: 'round'
            },
            grid: {
                show: true,
                borderColor: '#D1D5DB',
                strokeDashArray: 1,
                yaxis: { lines: { show: true } }
            },
            colors: ['#16a34a', '#FF9F29'],
            markers: {
                colors: ['#16a34a', '#FF9F29'],
                size: 0,
                hover: { size: 10 }
            },
            xaxis: {
                categories: monthLabels,
                labels: { show: true }
            },
            yaxis: {
                labels: {
                    formatter: function(value) {
                        return "<?php echo $currencySymbol; ?>" + value.toFixed(1) + "k";
                    }
                }
            },
            fill: {
                type: "gradient",
                gradient: {
                    shade: "light",
                    type: "vertical",
                    opacityFrom: 0.4,
                    opacityTo: 0.05,
                    stops: [0, 100]
                }
            }
        };

        var chart2 = new ApexCharts(document.querySelector("#incomeExpense"), options2);
        chart2.render();

        // New Admissions Chart - Boys vs Girls
        var admissionsEl = document.querySelector("#newAdmissions");
        var admissionsData = [];

        if (admissionsEl) {
            try {
                admissionsData = JSON.parse(admissionsEl.dataset.admissions || '[0,0]');
                var boysCount = parseInt(admissionsEl.dataset.boys || '0');
                var girlsCount = parseInt(admissionsEl.dataset.girls || '0');
            } catch (e) {
                console.error('Error parsing admissions data:', e);
                admissionsData = [0, 0];
                boysCount = 0;
                girlsCount = 0;
            }
        }

        // Ensure we have valid data
        if (!Array.isArray(admissionsData) || admissionsData.length === 0 || 
            (admissionsData[0] === 0 && admissionsData[1] === 0)) {
            admissionsData = [1, 1]; // 50-50 default for visual when no data
            boysCount = 0;
            girlsCount = 0;
        }

        var options3 = {
            series: admissionsData,
            colors: ['#FF7A2C', '#009F5E'], // Orange for boys, Green for girls
            labels: ['Boys', 'Girls'],
            legend: { show: false },
            chart: {
                type: 'donut',
                height: 270,
                sparkline: { enabled: true }
            },
            stroke: { width: 2 },
            dataLabels: { enabled: false },
            plotOptions: {
                pie: {
                    donut: {
                        size: '70%',
                        labels: {
                            show: true,
                            name: {
                                show: false
                            },
                            value: {
                                show: false
                            },
                            total: {
                                show: true,
                                showAlways: true,
                                label: 'Total',
                                fontSize: '14px',
                                fontWeight: 600,
                                color: '#5D657B',
                                formatter: function(w) {
                                    return boysCount + girlsCount;
                                }
                            }
                        }
                    }
                }
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return val + " students";
                    }
                }
            }
        };

        if (document.querySelector("#newAdmissions")) {
            var chart3 = new ApexCharts(document.querySelector("#newAdmissions"), options3);
            chart3.render();
        }

        // Calendar functionality
        let display = document.querySelector(".display");
        let days = document.querySelector(".days");
        let previous = document.querySelector(".left");
        let next = document.querySelector(".right");

        let date = new Date();
        let year = date.getFullYear();
        let month = date.getMonth();

        function displayCalendar() {
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const firstDayIndex = firstDay.getDay();
            const numberOfDays = lastDay.getDate();
            let formattedDate = date.toLocaleString("en-US", {
                month: "long",
                year: "numeric"
            });

            display.innerHTML = `${formattedDate}`;
            days.innerHTML = '';

            for (let x = 1; x <= firstDayIndex; x++) {
                const div = document.createElement("div");
                div.innerHTML = "";
                days.appendChild(div);
            }

            for (let i = 1; i <= numberOfDays; i++) {
                let div = document.createElement("div");
                let currentDate = new Date(year, month, i);
                div.dataset.date = currentDate.toDateString();
                div.innerHTML = i;
                days.appendChild(div);

                if (
                    currentDate.getFullYear() === new Date().getFullYear() &&
                    currentDate.getMonth() === new Date().getMonth() &&
                    currentDate.getDate() === new Date().getDate()
                ) {
                    div.classList.add("current-date");
                }
            }
        }

        displayCalendar();

        previous.addEventListener("click", () => {
            if (month < 0) {
                month = 11;
                year = year - 1;
            }
            month = month - 1;
            date.setMonth(month);
            displayCalendar();
        });

        next.addEventListener("click", () => {
            if (month > 11) {
                month = 0;
                year = year + 1;
            }
            month = month + 1;
            date.setMonth(month);
            displayCalendar();
        });

        // Animated Radial Progress Bar
        $('svg.radial-progress').each(function(index, value) {
            $(this).find($('circle.complete')).removeAttr('style');
        });

        $(window).scroll(function() {
            $('svg.radial-progress').each(function(index, value) {
                if (
                    $(window).scrollTop() >= $(this).offset().top - $(window).height() &&
                    $(window).scrollTop() <= $(this).offset().top + $(this).height()
                ) {
                    const percent = $(value).data('percentage');
                    const radius = $(this).find($('circle.complete')).attr('r');
                    const circumference = 2 * Math.PI * radius;
                    const strokeDashOffset = circumference - ((percent * circumference) / 100);
                    $(this).find($('circle.complete')).animate({ 'stroke-dashoffset': strokeDashOffset }, 1250);
                }
            });
        }).trigger('scroll');
    </script>
</body>
</html>
