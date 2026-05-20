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

// Load the shared school-admin context before using $school.
require_once __DIR__ . '/admin-bootstrap.php';

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
$staffCount = 15; // Default staff count

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

        // Get staff count
        try {
            $tableCheck = $schoolDb->query("SHOW TABLES LIKE 'staff'")->fetch();
            if ($tableCheck) {
                $staffStmt = $schoolDb->prepare("
                    SELECT COUNT(*) as count FROM staff 
                    WHERE school_id = ? AND is_active = 1
                ");
                if ($staffStmt) {
                    $staffStmt->execute([$school['id']]);
                    $staffResult = $staffStmt->fetch();
                    $staffCount = $staffResult['count'] ?? 15;
                }
            }
        } catch (Exception $e) {
            error_log("Error counting staff: " . $e->getMessage());
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
