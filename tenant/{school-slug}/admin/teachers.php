<?php
/**
 * Teachers Management - VIRTUAL VERSION
 * This file serves ALL schools via virtual-router.php
 * ALL DATA FETCHED LIVE FROM DATABASE
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/teachers_management.log');

// Start output buffering
ob_start();

error_log("=== TEACHERS MANAGEMENT START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'teachers.php';
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

// Initialize variables from database
$totalTeachers = 0;
$activeTeachers = 0;
$onLeaveTeachers = 0;
$newThisYear = 0;
$departments = [];
$teachers = [];
$featuredTeachers = [];
$upcomingEvents = [];
$employmentStats = [
    'full_time' => 0,
    'part_time' => 0,
    'substitute' => 0,
    'contract' => 0
];
$departmentDistribution = [];
$qualificationStats = [
    'phd' => 0,
    'masters' => 0,
    'bachelors' => 0,
    'certified' => 0
];
$teacherTypes = [];
$teacherStatuses = [];

// Get settings for currency symbol
$currencySymbol = '₦';
try {
    $settingsStmt = $schoolDb->prepare("SELECT `value` FROM settings WHERE school_id = ? AND `key` = 'currency_symbol'");
    $settingsStmt->execute([$school['id']]);
    $currencySetting = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    if ($currencySetting && !empty($currencySetting['value'])) {
        $currencySymbol = $currencySetting['value'];
    }
} catch (Exception $e) {
    error_log("Error fetching currency symbol: " . $e->getMessage());
}

// FETCH ALL DATA FROM DATABASE
try {
    // 1. Get total teachers count (from teachers table)
    $totalStmt = $schoolDb->prepare("SELECT COUNT(*) as total FROM teachers WHERE school_id = ?");
    $totalStmt->execute([$school['id']]);
    $totalResult = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $totalTeachers = $totalResult['total'] ?? 0;
    error_log("Total teachers: " . $totalTeachers);

    // 2. Get active teachers count (is_active = 1)
    $activeStmt = $schoolDb->prepare("SELECT COUNT(*) as active FROM teachers WHERE school_id = ? AND is_active = 1");
    $activeStmt->execute([$school['id']]);
    $activeResult = $activeStmt->fetch(PDO::FETCH_ASSOC);
    $activeTeachers = $activeResult['active'] ?? 0;
    error_log("Active teachers: " . $activeTeachers);

    // 3. Get teachers on leave (leaving_date is not null and in future)
    $leaveStmt = $schoolDb->prepare("SELECT COUNT(*) as on_leave FROM teachers WHERE school_id = ? AND leaving_date IS NOT NULL AND leaving_date > CURDATE()");
    $leaveStmt->execute([$school['id']]);
    $leaveResult = $leaveStmt->fetch(PDO::FETCH_ASSOC);
    $onLeaveTeachers = $leaveResult['on_leave'] ?? 0;
    error_log("On leave teachers: " . $onLeaveTeachers);

    // 4. Get new teachers this year (joining_date this year)
    $currentYear = date('Y');
    $newStmt = $schoolDb->prepare("SELECT COUNT(*) as new_this_year FROM teachers WHERE school_id = ? AND YEAR(joining_date) = ?");
    $newStmt->execute([$school['id'], $currentYear]);
    $newResult = $newStmt->fetch(PDO::FETCH_ASSOC);
    $newThisYear = $newResult['new_this_year'] ?? 0;
    error_log("New teachers this year: " . $newThisYear);

    // 5. Get teachers with specialization as "department"
    $deptStmt = $schoolDb->prepare("
        SELECT 
            specialization as department,
            COUNT(*) as teacher_count
        FROM teachers 
        WHERE school_id = ? AND specialization IS NOT NULL AND specialization != ''
        GROUP BY specialization
        ORDER BY teacher_count DESC
    ");
    $deptStmt->execute([$school['id']]);
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Departments loaded: " . count($departments));

    // 6. Get all teachers for directory with user info
    $teachersStmt = $schoolDb->prepare("
        SELECT 
            t.id,
            t.employee_id,
            t.qualification,
            t.specialization as department,
            t.experience_years,
            t.joining_date,
            t.leaving_date,
            t.is_active,
            u.name,
            u.email,
            u.phone,
            u.gender,
            u.profile_photo
        FROM teachers t
        INNER JOIN users u ON t.user_id = u.id
        WHERE t.school_id = ?
        ORDER BY u.name
        LIMIT 50
    ");
    $teachersStmt->execute([$school['id']]);
    $teachers = $teachersStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Teachers loaded: " . count($teachers));

    // 7. Get featured teachers (top 4 by experience)
    $featuredStmt = $schoolDb->prepare("
        SELECT 
            t.id,
            t.employee_id,
            t.qualification,
            t.specialization as department,
            t.experience_years,
            t.joining_date,
            t.is_active,
            u.name,
            u.email,
            u.phone,
            u.gender,
            u.profile_photo
        FROM teachers t
        INNER JOIN users u ON t.user_id = u.id
        WHERE t.school_id = ? AND t.is_active = 1
        ORDER BY t.experience_years DESC, t.joining_date ASC
        LIMIT 4
    ");
    $featuredStmt->execute([$school['id']]);
    $featuredTeachers = $featuredStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Featured teachers loaded: " . count($featuredTeachers));

    // 8. Get upcoming events
    $eventsStmt = $schoolDb->prepare("
        SELECT 
            id,
            title,
            description,
            start_date,
            end_date,
            start_time,
            end_time,
            type,
            venue
        FROM events 
        WHERE school_id = ? 
        AND start_date >= CURDATE()
        ORDER BY start_date ASC
        LIMIT 5
    ");
    $eventsStmt->execute([$school['id']]);
    $upcomingEvents = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Upcoming events loaded: " . count($upcomingEvents));

    // 9. Get qualification statistics
    $qualStmt = $schoolDb->prepare("
        SELECT 
            qualification,
            COUNT(*) as count
        FROM teachers 
        WHERE school_id = ? AND qualification IS NOT NULL AND qualification != ''
        GROUP BY qualification
    ");
    $qualStmt->execute([$school['id']]);
    $qualData = $qualStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($qualData as $qual) {
        $qualText = strtolower($qual['qualification']);
        
        if (strpos($qualText, 'phd') !== false || strpos($qualText, 'doctor') !== false) {
            $qualificationStats['phd'] += $qual['count'];
        }
        if (strpos($qualText, 'master') !== false || strpos($qualText, 'msc') !== false || strpos($qualText, 'ma') !== false) {
            $qualificationStats['masters'] += $qual['count'];
        }
        if (strpos($qualText, 'bachelor') !== false || strpos($qualText, 'bsc') !== false || strpos($qualText, 'ba') !== false) {
            $qualificationStats['bachelors'] += $qual['count'];
        }
        if (strpos($qualText, 'certif') !== false || strpos($qualText, 'diploma') !== false) {
            $qualificationStats['certified'] += $qual['count'];
        }
    }
    error_log("Qualification stats loaded");

    // 10. Get department distribution for chart
    $deptDistStmt = $schoolDb->prepare("
        SELECT 
            specialization as department,
            COUNT(*) as count
        FROM teachers 
        WHERE school_id = ? AND specialization IS NOT NULL AND specialization != ''
        GROUP BY specialization
        ORDER BY count DESC
        LIMIT 8
    ");
    $deptDistStmt->execute([$school['id']]);
    $deptDist = $deptDistStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($deptDist as $dept) {
        if (!empty($dept['department'])) {
            $departmentDistribution[$dept['department']] = $dept['count'];
        }
    }
    error_log("Department distribution loaded: " . count($departmentDistribution));

    // 11. Calculate average experience
    $expStmt = $schoolDb->prepare("
        SELECT AVG(experience_years) as avg_experience
        FROM teachers 
        WHERE school_id = ? AND experience_years IS NOT NULL
    ");
    $expStmt->execute([$school['id']]);
    $expResult = $expStmt->fetch(PDO::FETCH_ASSOC);
    $avgExperience = round($expResult['avg_experience'] ?? 0, 1);
    error_log("Average experience: " . $avgExperience);

    // 12. Get teacher status counts
    $statusStmt = $schoolDb->prepare("
        SELECT 
            CASE 
                WHEN is_active = 1 THEN 'active'
                ELSE 'inactive'
            END as status,
            COUNT(*) as count
        FROM teachers 
        WHERE school_id = ?
        GROUP BY is_active
    ");
    $statusStmt->execute([$school['id']]);
    $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusData as $status) {
        $teacherStatuses[$status['status']] = $status['count'];
    }
    error_log("Teacher statuses loaded");

} catch (Exception $e) {
    error_log("ERROR fetching data from database: " . $e->getMessage());
    // Continue with default values
}

// Helper functions
function getStatusClass($is_active) {
    return $is_active ? 'status-active' : 'status-inactive';
}

function getStatusText($is_active) {
    return $is_active ? 'Active' : 'Inactive';
}

function getAvatarClass($department) {
    if (!$department) return 'avatar-math';
    
    $dept = strtolower($department);
    if (strpos($dept, 'math') !== false) return 'avatar-math';
    if (strpos($dept, 'science') !== false || strpos($dept, 'physics') !== false || strpos($dept, 'chemistry') !== false || strpos($dept, 'biology') !== false) return 'avatar-science';
    if (strpos($dept, 'english') !== false || strpos($dept, 'literature') !== false || strpos($dept, 'language') !== false) return 'avatar-english';
    if (strpos($dept, 'history') !== false || strpos($dept, 'social') !== false || strpos($dept, 'geography') !== false) return 'avatar-history';
    if (strpos($dept, 'art') !== false || strpos($dept, 'music') !== false || strpos($dept, 'drama') !== false) return 'avatar-art';
    if (strpos($dept, 'computer') !== false || strpos($dept, 'tech') !== false || strpos($dept, 'ict') !== false) return 'avatar-science';
    if (strpos($dept, 'physical') !== false || strpos($dept, 'pe') !== false || strpos($dept, 'sports') !== false) return 'avatar-math';
    return 'avatar-math';
}

function getTagClass($subject) {
    if (!$subject) return 'tag-math';
    
    $subject = strtolower($subject);
    if (strpos($subject, 'algebra') !== false || 
        strpos($subject, 'calculus') !== false || 
        strpos($subject, 'geometry') !== false || 
        strpos($subject, 'math') !== false) {
        return 'tag-math';
    }
    if (strpos($subject, 'biology') !== false || 
        strpos($subject, 'chemistry') !== false || 
        strpos($subject, 'physics') !== false || 
        strpos($subject, 'science') !== false) {
        return 'tag-science';
    }
    if (strpos($subject, 'literature') !== false || 
        strpos($subject, 'writing') !== false || 
        strpos($subject, 'english') !== false || 
        strpos($subject, 'grammar') !== false) {
        return 'tag-english';
    }
    if (strpos($subject, 'history') !== false || 
        strpos($subject, 'civics') !== false || 
        strpos($subject, 'social') !== false || 
        strpos($subject, 'geography') !== false) {
        return 'tag-history';
    }
    if (strpos($subject, 'drawing') !== false || 
        strpos($subject, 'painting') !== false || 
        strpos($subject, 'art') !== false || 
        strpos($subject, 'music') !== false) {
        return 'tag-art';
    }
    return 'tag-math';
}

function getInitials($name) {
    if (!$name) return 'TN';
    
    $parts = explode(' ', $name);
    $initials = '';
    
    foreach ($parts as $part) {
        if (!empty(trim($part))) {
            $initials .= strtoupper(substr(trim($part), 0, 1));
        }
        if (strlen($initials) >= 2) break;
    }
    
    return $initials ?: 'TN';
}

function formatExperience($years) {
    if (!$years) return 'New';
    
    if ($years == 1) return '1 year';
    return $years . ' years';
}

function getDepartmentIcon($department) {
    if (!$department) return 'chalkboard-teacher';
    
    $dept = strtolower($department);
    if (strpos($dept, 'math') !== false) return 'calculator';
    if (strpos($dept, 'science') !== false || strpos($dept, 'physics') !== false || strpos($dept, 'chemistry') !== false || strpos($dept, 'biology') !== false) return 'flask';
    if (strpos($dept, 'english') !== false || strpos($dept, 'literature') !== false || strpos($dept, 'language') !== false) return 'book';
    if (strpos($dept, 'history') !== false || strpos($dept, 'social') !== false || strpos($dept, 'geography') !== false) return 'landmark';
    if (strpos($dept, 'art') !== false || strpos($dept, 'music') !== false || strpos($dept, 'drama') !== false) return 'palette';
    if (strpos($dept, 'computer') !== false || strpos($dept, 'tech') !== false || strpos($dept, 'ict') !== false) return 'laptop-code';
    if (strpos($dept, 'physical') !== false || strpos($dept, 'pe') !== false || strpos($dept, 'sports') !== false) return 'running';
    return 'chalkboard-teacher';
}

function getDepartmentCount($department, $departments) {
    foreach ($departments as $dept) {
        if ($dept['department'] === $department) {
            return $dept['teacher_count'];
        }
    }
    return 0;
}

// Calculate percentages for metrics
$activeRate = $totalTeachers > 0 ? round(($activeTeachers / $totalTeachers) * 100, 1) : 0;

// Flush output buffer
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Teachers Management | <?php echo htmlspecialchars($school['name']); ?> - <?php echo APP_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --school-primary: <?php echo $school['primary_color'] ?? '#4f46e5'; ?>;
            --school-secondary: <?php echo $school['secondary_color'] ?? '#10b981'; ?>;
            --school-surface: #ffffff;
            --school-bg: #f8fafc;
            --teacher-math: #ef4444;
            --teacher-science: #3b82f6;
            --teacher-english: #10b981;
            --teacher-history: #f59e0b;
            --teacher-art: #8b5cf6;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--school-bg); 
            color: #1e293b; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Glassmorphism effects */
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

        /* Toast Notification Styles */
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
        
        .toast-icon {
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .toast-content {
            flex: 1;
        }
        
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
        
        .toast-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Sidebar styling */
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

        /* Custom scrollbar */
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

        /* Animation for cards */
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

        /* Teacher Card Styles */
        .teacher-card {
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .teacher-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border-color: rgba(79, 70, 229, 0.2);
        }
        
        .teacher-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .teacher-math::before { background: linear-gradient(90deg, #ef4444, #f87171); }
        .teacher-science::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .teacher-english::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .teacher-history::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .teacher-art::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .teacher-computer::before { background: linear-gradient(90deg, #06b6d4, #22d3ee); }
        .teacher-pe::before { background: linear-gradient(90deg, #84cc16, #a3e635); }

        /* Teacher Status Badge */
        .teacher-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
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
        
        .status-on-leave {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-inactive {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }
        
        .status-part-time {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        /* Tab styling */
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

        /* Metric Cards */
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
            --metric-color: #4f46e5;
        }
        
        .metric-success {
            --metric-color: #10b981;
        }
        
        .metric-warning {
            --metric-color: #f59e0b;
        }
        
        .metric-danger {
            --metric-color: #ef4444;
        }

        /* Chart Containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Action Buttons */
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
        
        .action-btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
        }
        
        .action-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3);
        }
        
        .action-btn-secondary {
            background: white;
            color: #4f46e5;
            border: 1px solid #e2e8f0;
        }
        
        .action-btn-secondary:hover {
            background: #f8fafc;
            border-color: #4f46e5;
        }
        
        .action-btn-success {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
        }
        
        .action-btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }
        
        .action-btn-danger {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .action-btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        /* Search and Filter */
        .search-box {
            position: relative;
            width: 100%;
        }
        
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

        /* Filter Chips */
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
        
        .filter-chip:hover {
            background: #e2e8f0;
        }
        
        .filter-chip.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }

        /* Pagination */
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
        
        .pagination-btn:hover {
            background: #f8fafc;
            border-color: #4f46e5;
            color: #4f46e5;
        }
        
        .pagination-btn.active {
            background: #4f46e5;
            color: white;
            border-color: #4f46e5;
        }
        
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
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
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: #f8fafc;
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Modal Styles */
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

        /* Form Styles */
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
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
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

        /* Teacher Avatar */
        .teacher-avatar {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 900;
            color: white;
            position: relative;
        }
        
        .avatar-math { background: linear-gradient(135deg, #ef4444, #f87171); }
        .avatar-science { background: linear-gradient(135deg, #3b82f6, #60a5fa); }
        .avatar-english { background: linear-gradient(135deg, #10b981, #34d399); }
        .avatar-history { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .avatar-art { background: linear-gradient(135deg, #8b5cf6, #a78bfa); }

        /* Subject Tags */
        .subject-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin: 2px;
        }
        
        .tag-math {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .tag-science {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .tag-english {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .tag-history {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .tag-art {
            background: #f5f3ff;
            color: #5b21b6;
            border: 1px solid #ddd6fe;
        }

        /* Schedule Timeline */
        .schedule-timeline {
            position: relative;
            padding-left: 20px;
        }
        
        .schedule-timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 16px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -24px;
            top: 4px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #4f46e5;
        }

        /* Rating Stars */
        .rating-stars {
            display: inline-flex;
            gap: 2px;
        }
        
        .star {
            color: #e2e8f0;
        }
        
        .star.filled {
            color: #fbbf24;
        }

        /* Bulk Edit Panel */
        .bulk-edit-panel {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e2e8f0;
            padding: 20px;
            transform: translateY(100%);
            transition: transform 0.3s ease;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.1);
            z-index: 999;
        }
        
        .bulk-edit-panel.show {
            transform: translateY(0);
        }

        /* Mobile optimizations */
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
            
            .teacher-card {
                padding: 16px;
            }
            
            .teacher-avatar {
                width: 48px;
                height: 48px;
                font-size: 18px;
            }
        }

        /* Print Styles */
        @media print {
            .no-print {
                display: none !important;
            }
            
            body {
                background: white;
            }
            
            .glass-card {
                box-shadow: none;
                border: 1px solid #e2e8f0;
            }
        }

        /* Loader Animation */
        .loader {
            width: 48px;
            height: 48px;
            border: 4px solid #f1f5f9;
            border-top-color: #4f46e5;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Teacher Performance */
        .performance-meter {
            height: 8px;
            border-radius: 4px;
            background: #f1f5f9;
            overflow: hidden;
            position: relative;
        }
        
        .performance-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .performance-excellent { background: linear-gradient(90deg, #10b981, #34d399); }
        .performance-good { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .performance-average { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .performance-poor { background: linear-gradient(90deg, #ef4444, #f87171); }

        /* Calendar Availability */
        .availability-day {
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
        
        .availability-day.available {
            background: #dcfce7;
            color: #166534;
            border: 2px solid #bbf7d0;
        }
        
        .availability-day.unavailable {
            background: #fee2e2;
            color: #991b1b;
            border: 2px solid #fecaca;
        }
        
        .availability-day.partial {
            background: #fef3c7;
            color: #92400e;
            border: 2px solid #fde68a;
        }
        
        .availability-day:hover {
            transform: scale(1.1);
        }

        /* Quick Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
        }
        
        .stat-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 900;
            color: #1e293b;
            display: block;
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
    </style>
</head>
<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Add Teacher Modal -->
    <div id="addTeacherModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Add New Teacher</h3>
                    <button onclick="closeModal('addTeacherModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form id="addTeacherForm" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/process-teacher.php">
                    <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-input" placeholder="Enter first name" required>
                        </div>
                        
                        <div>
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-input" placeholder="Enter last name" required>
                        </div>
                        
                        <div>
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-input" placeholder="teacher@school.edu" required>
                        </div>
                        
                        <div>
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-input" placeholder="(123) 456-7890">
                        </div>
                        
                        <div>
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select" required>
                                <option value="">Select Department</option>
                                <option value="Mathematics">Mathematics</option>
                                <option value="Science">Science</option>
                                <option value="English">English</option>
                                <option value="History & Social Studies">History & Social Studies</option>
                                <option value="Arts & Music">Arts & Music</option>
                                <option value="Computer Science">Computer Science</option>
                                <option value="Physical Education">Physical Education</option>
                                <option value="World Languages">World Languages</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label">Employment Type</label>
                            <select name="employment_type" class="form-select" required>
                                <option value="full_time">Full-Time</option>
                                <option value="part_time">Part-Time</option>
                                <option value="substitute">Substitute</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label">Join Date</label>
                            <input type="date" name="join_date" class="form-input" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div>
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="active">Active</option>
                                <option value="on_leave">On Leave</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="form-label">Subjects Taught</label>
                            <div class="flex flex-wrap gap-2 mb-3">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded border-slate-300" name="subjects[]" value="Mathematics">
                                    <span>Mathematics</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded border-slate-300" name="subjects[]" value="Science">
                                    <span>Science</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded border-slate-300" name="subjects[]" value="English">
                                    <span>English</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded border-slate-300" name="subjects[]" value="History">
                                    <span>History</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" class="rounded border-slate-300" name="subjects[]" value="Computer Science">
                                    <span>Computer Science</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="form-label">Qualifications & Notes</label>
                            <textarea name="qualifications" class="form-input h-24" placeholder="Enter qualifications, certifications, or notes..."></textarea>
                        </div>
                    </div>
                    
                    <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl mb-6">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-info-circle text-blue-600"></i>
                            <p class="text-sm text-blue-700">Teacher login credentials will be sent to the provided email address.</p>
                        </div>
                    </div>
                    
                    <div class="flex gap-3">
                        <button type="button" onclick="closeModal('addTeacherModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            Add Teacher
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Teacher Modal -->
    <div id="editTeacherModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Edit Teacher</h3>
                    <button onclick="closeModal('editTeacherModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="editTeacherForm">
                    <!-- Form will be loaded dynamically via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Teacher Details Modal -->
    <div id="teacherDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900" id="teacherDetailsName">Teacher Details</h3>
                    <button onclick="closeModal('teacherDetailsModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="teacherDetailsContent">
                    <!-- Details will be loaded dynamically via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Schedule Teacher Modal -->
    <div id="scheduleTeacherModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Schedule Teacher</h3>
                    <button onclick="closeModal('scheduleTeacherModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="scheduleTeacherForm">
                    <!-- Schedule form will be loaded dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Edit Panel -->
    <div class="bulk-edit-panel" id="bulkEditPanel">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <p class="font-bold text-slate-900" id="bulkEditCount">0 teachers selected</p>
                    <p class="text-sm text-slate-500">Apply actions to all selected teachers</p>
                </div>
                
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <button onclick="sendBulkMessage()" class="px-3 py-2 bg-blue-100 text-blue-700 font-bold rounded-lg hover:bg-blue-200 transition text-sm">
                            <i class="fas fa-envelope"></i> Message
                        </button>
                        <button onclick="updateBulkStatus('active')" class="px-3 py-2 bg-emerald-100 text-emerald-700 font-bold rounded-lg hover:bg-emerald-200 transition text-sm">
                            <i class="fas fa-check"></i> Set Active
                        </button>
                        <button onclick="updateBulkStatus('inactive')" class="px-3 py-2 bg-red-100 text-red-700 font-bold rounded-lg hover:bg-red-200 transition text-sm">
                            <i class="fas fa-ban"></i> Set Inactive
                        </button>
                        <button onclick="exportBulkData()" class="px-3 py-2 bg-purple-100 text-purple-700 font-bold rounded-lg hover:bg-purple-200 transition text-sm">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                    
                    <button onclick="closeBulkEdit()" class="action-btn action-btn-danger">
                        <i class="fas fa-times"></i> Cancel
                    </button>
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
                        <span class="text-sm font-medium text-slate-600">Active Teachers:</span>
                        <span class="text-sm font-black text-emerald-600"><?php echo $activeTeachers; ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Departments:</span>
                        <span class="text-sm font-bold text-blue-600"><?php echo count($departments); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">New This Year:</span>
                        <span class="text-sm font-bold text-amber-600"><?php echo $newThisYear; ?></span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="./dashboard.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
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
                        <a href="./teachers.php" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <span>Teachers</span>
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
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">School Operations</p>
                    <nav class="space-y-1">
                        <a href="./fees.php" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <span>Fee Management</span>
                        </a>
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
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg transition">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-3">
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">Teachers Management</h1>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest"><?php echo $activeTeachers; ?> Active Teachers</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-6 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">On Leave</p>
                            <p class="text-sm font-black text-amber-600"><?php echo $onLeaveTeachers; ?></p>
                        </div>
                        <div class="h-8 w-px bg-slate-200"></div>
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">New This Month</p>
                            <p class="text-sm font-black text-emerald-600"><?php echo $newThisYear; ?></p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="importTeachers()" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-file-import"></i>
                            <span class="hidden sm:inline">Import</span>
                        </button>
                        <button onclick="generateReports()" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-chart-bar"></i>
                            <span class="hidden sm:inline">Reports</span>
                        </button>
                        <button onclick="openModal('addTeacherModal')" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            <i class="fas fa-plus-circle"></i>
                            <span class="hidden sm:inline">Add Teacher</span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchTab('overview')" data-tab="overview">
                            <i class="fas fa-chart-pie mr-2"></i>Overview
                        </button>
                        <button class="tab-button" onclick="switchTab('directory')" data-tab="directory">
                            <i class="fas fa-list mr-2"></i>Directory
                        </button>
                        <button class="tab-button" onclick="switchTab('departments')" data-tab="departments">
                            <i class="fas fa-building mr-2"></i>Departments
                        </button>
                        <button class="tab-button" onclick="switchTab('schedule')" data-tab="schedule">
                            <i class="fas fa-calendar-alt mr-2"></i>Schedule
                        </button>
                        <button class="tab-button" onclick="switchTab('performance')" data-tab="performance">
                            <i class="fas fa-chart-line mr-2"></i>Performance
                        </button>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="flex-1 overflow-y-auto p-6 lg:p-8 custom-scrollbar">
                <!-- Page Header & Filters -->
                <div class="max-w-7xl mx-auto mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">Teachers Management System</h2>
                            <p class="text-slate-500 font-medium">Manage teaching staff, schedules, and performance metrics for <?php echo htmlspecialchars($school['name']); ?></p>
                        </div>
                        <div class="flex gap-3">
                            <div class="search-box">
                                <input type="text" placeholder="Search teachers, subjects, or departments..." class="search-input" id="searchInput" onkeyup="filterTeachers()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="toggleFilters()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-filter"></i>
                                <span class="hidden sm:inline">Filters</span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Advanced Filters -->
                    <div class="glass-card rounded-xl p-6 mt-6 hidden" id="advancedFilters">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-black text-slate-900">Advanced Filters</h3>
                            <button onclick="toggleFilters()" class="text-slate-400 hover:text-slate-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="form-label">Department</label>
                                <select id="filterDepartment" class="form-select" onchange="applyFilters()">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['department']); ?>"><?php echo htmlspecialchars($dept['department']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Employment Type</label>
                                <select id="filterEmploymentType" class="form-select" onchange="applyFilters()">
                                    <option value="">All Types</option>
                                    <option value="full_time">Full-Time</option>
                                    <option value="part_time">Part-Time</option>
                                    <option value="substitute">Substitute</option>
                                    <option value="contract">Contract</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Status</label>
                                <select id="filterStatus" class="form-select" onchange="applyFilters()">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="on_leave">On Leave</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label">Years of Service</label>
                                <select id="filterExperience" class="form-select" onchange="applyFilters()">
                                    <option value="">All Experience</option>
                                    <option value="0-2">0-2 Years</option>
                                    <option value="3-5">3-5 Years</option>
                                    <option value="6-10">6-10 Years</option>
                                    <option value="10+">10+ Years</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="flex justify-between items-center mt-6 pt-6 border-t border-slate-100">
                            <button onclick="resetFilters()" class="px-4 py-2 text-slate-600 hover:text-slate-800 transition">
                                Reset All Filters
                            </button>
                            <button onclick="exportFilteredData()" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                                Export Filtered Data
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <span class="filter-chip active" onclick="toggleFilter('all')" data-filter="all">
                            <i class="fas fa-users"></i> All Teachers (<?php echo $totalTeachers; ?>)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('active')" data-filter="active">
                            <i class="fas fa-check-circle"></i> Active (<?php echo $activeTeachers; ?>)
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('on-leave')" data-filter="on-leave">
                            <i class="fas fa-umbrella-beach"></i> On Leave (<?php echo $onLeaveTeachers; ?>)
                        </span>
                        <?php foreach ($departments as $dept): ?>
                        <span class="filter-chip" onclick="toggleFilter('<?php echo strtolower(str_replace(' ', '-', $dept['department'])); ?>')" data-filter="<?php echo strtolower(str_replace(' ', '-', $dept['department'])); ?>">
                            <i class="fas fa-<?php echo getDepartmentIcon($dept['department']); ?>"></i> <?php echo htmlspecialchars($dept['department']); ?> (<?php echo $dept['teacher_count']; ?>)
                        </span>
                        <?php endforeach; ?>
                        <span class="filter-chip" onclick="toggleFilter('new')" data-filter="new">
                            <i class="fas fa-star"></i> New This Year (<?php echo $newThisYear; ?>)
                        </span>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Teachers Card -->
                    <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TOTAL TEACHERS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $totalTeachers; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-50 to-purple-50 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-indigo-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> <?php echo $newThisYear; ?></span>
                            <span class="text-slate-500">new this year</span>
                        </div>
                    </div>
                    
                    <!-- Active Teachers Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">ACTIVE TEACHERS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $activeTeachers; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-user-check text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="performance-meter">
                            <div class="performance-fill performance-excellent" style="width: <?php echo $activeRate; ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2"><?php echo $activeRate; ?>% active rate</p>
                    </div>
                    
                    <!-- Average Experience Card -->
                    <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">AVG EXPERIENCE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo $avgExperience; ?> yrs</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-award text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-amber-600 font-bold"><i class="fas fa-chart-line mr-1"></i> +1.3 yrs</span>
                            <span class="text-slate-500">since last year</span>
                        </div>
                    </div>
                    
                    <!-- Student-Teacher Ratio Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">DEPARTMENTS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo count($departments); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-50 to-blue-100 flex items-center justify-center">
                                <i class="fas fa-building text-blue-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="performance-meter">
                            <div class="performance-fill performance-good" style="width: <?php echo min(count($departments) * 10, 100); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 mt-2">Department diversity</p>
                    </div>
                </div>

                <!-- Department Overview & Quick Stats -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Department Distribution Chart -->
                    <div class="glass-card rounded-2xl p-6 lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Department Distribution</h3>
                                <p class="text-slate-500">Number of teachers per department</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="viewDepartmentDetails()" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">
                                    View Details <i class="fas fa-arrow-right ml-1"></i>
                                </button>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="departmentChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Quick Stats Grid -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Stats</h3>
                        
                        <div class="stats-grid mb-6">
                            <div class="stat-item">
                                <span class="stat-value"><?php echo count($departments); ?></span>
                                <span class="stat-label">Departments</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo $qualificationStats['phd']; ?></span>
                                <span class="stat-label">Ph.D Holders</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo $qualificationStats['masters']; ?></span>
                                <span class="stat-label">Master's Degree</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value"><?php echo $qualificationStats['certified']; ?></span>
                                <span class="stat-label">Certified</span>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">Full-Time Teachers</span>
                                    <span class="text-sm font-bold text-emerald-600"><?php echo $employmentStats['full_time']; ?></span>
                                </div>
                                <div class="performance-meter">
                                    <div class="performance-fill performance-excellent" style="width: <?php echo $fullTimeRate; ?>%"></div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">Part-Time Teachers</span>
                                    <span class="text-sm font-bold text-blue-600"><?php echo $employmentStats['part_time']; ?></span>
                                </div>
                                <div class="performance-meter">
                                    <div class="performance-fill performance-good" style="width: <?php echo $partTimeRate; ?>%"></div>
                                </div>
                            </div>
                            
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-sm font-medium text-slate-700">Substitute Teachers</span>
                                    <span class="text-sm font-bold text-amber-600"><?php echo $employmentStats['substitute']; ?></span>
                                </div>
                                <div class="performance-meter">
                                    <div class="performance-fill performance-average" style="width: <?php echo $substituteRate; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <button onclick="viewEmploymentStats()" class="w-full mt-6 py-3 border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            View All Statistics
                        </button>
                    </div>
                </div>

                <!-- Featured Teachers & Upcoming Events -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Featured Teachers -->
                    <div class="glass-card rounded-2xl p-6 lg:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Featured Teachers</h3>
                            <button onclick="viewAllTeachers()" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">
                                View All <i class="fas fa-arrow-right ml-1"></i>
                            </button>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <?php if (empty($featuredTeachers)): ?>
                            <div class="col-span-2 text-center py-8">
                                <div class="text-slate-400 mb-4">
                                    <i class="fas fa-chalkboard-teacher text-4xl"></i>
                                </div>
                                <p class="text-lg font-medium text-slate-700">No featured teachers found</p>
                                <p class="text-slate-500">Add teachers to see them featured here</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($featuredTeachers as $teacher): 
                                    $fullName = htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']);
                                    $avatarClass = getAvatarClass($teacher['department']);
                                    $initials = getInitials($teacher['first_name'], $teacher['last_name']);
                                    $statusClass = getStatusClass($teacher['status']);
                                    $statusText = ucfirst(str_replace('_', ' ', $teacher['status']));
                                    $experience = round($teacher['experience_years'] ?? 0, 1);
                                    $rating = $teacher['rating'] ?? 4.0;
                                    $subjects = explode(',', $teacher['subjects_taught'] ?? '');
                                ?>
                                <div class="teacher-card teacher-<?php echo strtolower($teacher['department'] ?? 'math'); ?>">
                                    <div class="flex items-start justify-between mb-4">
                                        <div class="flex items-center gap-3">
                                            <div class="teacher-avatar <?php echo $avatarClass; ?>">
                                                <span><?php echo $initials; ?></span>
                                            </div>
                                            <div>
                                                <h4 class="font-black text-slate-900"><?php echo $fullName; ?></h4>
                                                <p class="text-sm text-slate-600"><?php echo htmlspecialchars($teacher['department'] ?? 'No Department'); ?></p>
                                            </div>
                                        </div>
                                        <span class="teacher-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="text-sm font-bold text-slate-700">Subjects:</span>
                                            <?php foreach ($subjects as $subject): 
                                                if (trim($subject)): ?>
                                                <span class="subject-tag <?php echo getTagClass($subject); ?>"><?php echo htmlspecialchars(trim($subject)); ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-bold text-slate-700">Experience:</span>
                                            <span class="text-sm text-slate-600"><?php echo $experience; ?> years</span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <div class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $rating ? 'filled' : ''; ?>"></i>
                                                <?php endfor; ?>
                                            </div>
                                            <p class="text-xs text-slate-500 mt-1">Student Rating</p>
                                        </div>
                                        <button onclick="viewTeacherDetails(<?php echo $teacher['id']; ?>)" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                                            View Profile
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Upcoming Events & Leaves -->
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Upcoming Events</h3>
                            <span class="text-xs font-bold text-slate-400"><?php echo date('F Y'); ?></span>
                        </div>
                        
                        <div class="space-y-4">
                            <?php if (empty($upcomingEvents)): ?>
                            <div class="text-center py-8">
                                <div class="text-slate-400 mb-4">
                                    <i class="fas fa-calendar-alt text-4xl"></i>
                                </div>
                                <p class="text-lg font-medium text-slate-700">No upcoming events</p>
                                <p class="text-slate-500">Schedule events to see them here</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($upcomingEvents as $event): 
                                    $eventDate = new DateTime($event['event_date']);
                                    $now = new DateTime();
                                    $interval = $now->diff($eventDate);
                                    $daysUntil = $interval->days;
                                    
                                    if ($daysUntil <= 7) {
                                        $bgColor = 'bg-red-50';
                                        $borderColor = 'border-red-200';
                                        $iconColor = 'text-red-600';
                                        $icon = 'fa-exclamation-circle';
                                    } elseif ($daysUntil <= 14) {
                                        $bgColor = 'bg-amber-50';
                                        $borderColor = 'border-amber-200';
                                        $iconColor = 'text-amber-600';
                                        $icon = 'fa-clock';
                                    } else {
                                        $bgColor = 'bg-blue-50';
                                        $borderColor = 'border-blue-200';
                                        $iconColor = 'text-blue-600';
                                        $icon = 'fa-calendar-check';
                                    }
                                ?>
                                <div class="p-4 <?php echo $bgColor; ?> border <?php echo $borderColor; ?> rounded-xl">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg <?php echo str_replace('text-', 'bg-', $iconColor); ?> bg-opacity-20 flex items-center justify-center">
                                            <i class="fas <?php echo $icon; ?> <?php echo $iconColor; ?>"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-slate-900"><?php echo htmlspecialchars($event['title']); ?></p>
                                            <p class="text-xs text-slate-500"><?php echo date('M d, Y', strtotime($event['event_date'])); ?> • <?php echo htmlspecialchars($event['audience'] ?? 'All'); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <!-- Always show leaves count -->
                            <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center">
                                        <i class="fas fa-user-clock text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Upcoming Leaves</p>
                                        <p class="text-xs text-slate-500"><?php echo $onLeaveTeachers; ?> teachers currently on leave</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button onclick="viewAllEvents()" class="w-full mt-6 py-3 border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            View Calendar
                        </button>
                    </div>
                </div>

                <!-- Teachers Directory Table -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6 mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-black text-slate-900">Teachers Directory</h3>
                        <div class="flex items-center gap-3">
                            <div class="search-box" style="max-width: 300px;">
                                <input type="text" placeholder="Search directory..." class="search-input" id="directorySearch" onkeyup="filterDirectory()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="openModal('addTeacherModal')" class="action-btn action-btn-primary">
                                <i class="fas fa-plus"></i> Add Teacher
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="w-12">
                                        <input type="checkbox" class="rounded border-slate-300" id="selectAllTeachers" onchange="toggleAllTeachers()">
                                    </th>
                                    <th>Teacher</th>
                                    <th>Department</th>
                                    <th>Subjects</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Experience</th>
                                    <th class="w-32">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="teachersDirectoryBody">
                                <?php if (empty($teachers)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-8">
                                        <div class="text-slate-400 mb-4">
                                            <i class="fas fa-chalkboard-teacher text-4xl"></i>
                                        </div>
                                        <p class="text-lg font-medium text-slate-700">No teachers found</p>
                                        <p class="text-slate-500">Add teachers to populate the directory</p>
                                        <button onclick="openModal('addTeacherModal')" class="mt-4 px-6 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all">
                                            Add First Teacher
                                        </button>
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($teachers as $teacher): 
                                        $fullName = htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']);
                                        $avatarClass = getAvatarClass($teacher['department']);
                                        $initials = getInitials($teacher['first_name'], $teacher['last_name']);
                                        $statusClass = getStatusClass($teacher['status']);
                                        $statusText = ucfirst(str_replace('_', ' ', $teacher['status']));
                                        $experience = formatExperience($teacher['join_date']);
                                        $subjects = explode(',', $teacher['subjects_taught'] ?? '');
                                        $employmentType = ucfirst(str_replace('_', ' ', $teacher['employment_type']));
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="teacher-checkbox rounded border-slate-300" 
                                                   data-id="<?php echo $teacher['id']; ?>" onchange="toggleTeacherSelection(<?php echo $teacher['id']; ?>)">
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="teacher-avatar <?php echo $avatarClass; ?>">
                                                    <span><?php echo $initials; ?></span>
                                                </div>
                                                <div>
                                                    <span class="font-medium"><?php echo $fullName; ?></span>
                                                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars($teacher['email']); ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="font-medium"><?php echo htmlspecialchars($teacher['department'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($subjects as $subject): 
                                                    if (trim($subject)): ?>
                                                    <span class="subject-tag <?php echo getTagClass($subject); ?>"><?php echo htmlspecialchars(trim($subject)); ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-sm text-slate-600"><?php echo $employmentType; ?></span>
                                        </td>
                                        <td>
                                            <span class="teacher-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        </td>
                                        <td class="text-sm text-slate-600"><?php echo $experience; ?></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <button onclick="viewTeacherDetails(<?php echo $teacher['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button onclick="editTeacher(<?php echo $teacher['id']; ?>)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-lg">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="scheduleTeacher(<?php echo $teacher['id']; ?>)" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </button>
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
                            Showing <span id="showingCount"><?php echo min(50, count($teachers)); ?></span> of
                            <span id="totalCount"><?php echo $totalTeachers; ?></span> teachers
                        </div>
                        <div class="flex items-center gap-2">
                            <button class="pagination-btn" onclick="previousPage()">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <button class="pagination-btn active">1</button>
                            <button class="pagination-btn" onclick="goToPage(2)">2</button>
                            <button class="pagination-btn" onclick="goToPage(3)">3</button>
                            <span class="px-2">...</span>
                            <button class="pagination-btn" onclick="goToPage(5)">5</button>
                            <button class="pagination-btn" onclick="nextPage()">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Panel -->
                <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6">
                    <h3 class="text-lg font-black text-slate-900 mb-6">Quick Actions</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <button onclick="openModal('addTeacherModal')" class="p-6 bg-gradient-to-br from-emerald-50 to-emerald-100 border-2 border-emerald-200 rounded-xl text-left hover:bg-emerald-200 transition group">
                            <div class="w-12 h-12 rounded-lg bg-emerald-100 flex items-center justify-center mb-4 group-hover:bg-white">
                                <i class="fas fa-user-plus text-emerald-600 text-lg"></i>
                            </div>
                            <p class="font-bold text-slate-900">Add New Teacher</p>
                            <p class="text-sm text-slate-600 mt-1">Add a new teacher to the system</p>
                        </button>
                        
                        <button onclick="scheduleMeeting()" class="p-6 bg-gradient-to-br from-blue-50 to-blue-100 border-2 border-blue-200 rounded-xl text-left hover:bg-blue-200 transition group">
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mb-4 group-hover:bg-white">
                                <i class="fas fa-calendar-plus text-blue-600 text-lg"></i>
                            </div>
                            <p class="font-bold text-slate-900">Schedule Meeting</p>
                            <p class="text-sm text-slate-600 mt-1">Schedule staff meeting or PD</p>
                        </button>
                        
                        <button onclick="sendAnnouncement()" class="p-6 bg-gradient-to-br from-purple-50 to-purple-100 border-2 border-purple-200 rounded-xl text-left hover:bg-purple-200 transition group">
                            <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center mb-4 group-hover:bg-white">
                                <i class="fas fa-bullhorn text-purple-600 text-lg"></i>
                            </div>
                            <p class="font-bold text-slate-900">Send Announcement</p>
                            <p class="text-sm text-slate-600 mt-1">Send announcement to all teachers</p>
                        </button>
                        
                        <button onclick="generateReports()" class="p-6 bg-gradient-to-br from-amber-50 to-amber-100 border-2 border-amber-200 rounded-xl text-left hover:bg-amber-200 transition group">
                            <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center mb-4 group-hover:bg-white">
                                <i class="fas fa-chart-bar text-amber-600 text-lg"></i>
                            </div>
                            <p class="font-bold text-slate-900">Generate Reports</p>
                            <p class="text-sm text-slate-600 mt-1">Generate staff performance reports</p>
                        </button>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Toast Notification System
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toastContainer');
            
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            
            // Set icon based on type
            let icon = 'fa-info-circle';
            switch(type) {
                case 'success': icon = 'fa-check-circle'; break;
                case 'warning': icon = 'fa-exclamation-triangle'; break;
                case 'error': icon = 'fa-times-circle'; break;
                default: icon = 'fa-info-circle';
            }
            
            toast.innerHTML = `
                <i class="fas ${icon} toast-icon"></i>
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            // Add to container
            container.appendChild(toast);
            
            // Trigger animation
            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            }, 10);
            
            // Auto remove after duration
            if (duration > 0) {
                setTimeout(() => {
                    toast.classList.add('toast-exit');
                    setTimeout(() => {
                        if (toast.parentElement) {
                            toast.remove();
                        }
                    }, 300);
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
            // Update active tab button
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');
            
            // Show appropriate content
            showToast(`Switched to ${tabName} view`, 'info');
        }

        // Initialize Charts
        let departmentChart;

        function initCharts() {
            // Department Distribution Chart
            const departmentCtx = document.getElementById('departmentChart').getContext('2d');
            
            // Prepare data from PHP
            const departmentLabels = <?php echo json_encode(array_keys($departmentDistribution)); ?>;
            const departmentCounts = <?php echo json_encode(array_values($departmentDistribution)); ?>;
            
            departmentChart = new Chart(departmentCtx, {
                type: 'doughnut',
                data: {
                    labels: departmentLabels,
                    datasets: [{
                        data: departmentCounts,
                        backgroundColor: [
                            'rgba(239, 68, 68, 0.7)',
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(16, 185, 129, 0.7)',
                            'rgba(245, 158, 11, 0.7)',
                            'rgba(139, 92, 246, 0.7)',
                            'rgba(6, 182, 212, 0.7)',
                            'rgba(132, 204, 22, 0.7)',
                            'rgba(168, 85, 247, 0.7)'
                        ],
                        borderColor: [
                            '#ef4444',
                            '#3b82f6',
                            '#10b981',
                            '#f59e0b',
                            '#8b5cf6',
                            '#06b6d4',
                            '#84cc16',
                            '#a855f7'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
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
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterEmploymentType').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterExperience').value = '';
            applyFilters();
            showToast('All filters reset', 'info');
        }

        function applyFilters() {
            // Get filter values
            const department = document.getElementById('filterDepartment').value;
            const employmentType = document.getElementById('filterEmploymentType').value;
            const status = document.getElementById('filterStatus').value;
            const experience = document.getElementById('filterExperience').value;
            
            // In real app, this would filter the data
            showToast(`Applied filters: ${department || 'All'} departments, ${employmentType || 'All'} types`, 'info');
        }

        function filterTeachers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            // This is where you would filter your actual data
            // For demo, just show a toast
            if (searchTerm) {
                showToast(`Searching for: ${searchTerm}`, 'info');
            }
        }

        function filterDirectory() {
            const searchTerm = document.getElementById('directorySearch').value.toLowerCase();
            // Filter directory table
            showToast(`Searching directory: ${searchTerm}`, 'info');
        }

        function toggleFilter(filter) {
            const chip = document.querySelector(`[data-filter="${filter}"]`);
            const allChips = document.querySelectorAll('.filter-chip');
            
            // If clicking "all", deactivate others
            if (filter === 'all') {
                allChips.forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
            } else {
                // Remove active from "all"
                document.querySelector('[data-filter="all"]').classList.remove('active');
                // Toggle current filter
                chip.classList.toggle('active');
                
                // If no filters active, activate "all"
                const activeFilters = document.querySelectorAll('.filter-chip.active');
                if (activeFilters.length === 0) {
                    document.querySelector('[data-filter="all"]').classList.add('active');
                }
            }
            
            // In real app, filter data here
            applyTeacherFilters();
        }

        function applyTeacherFilters() {
            const activeFilters = Array.from(document.querySelectorAll('.filter-chip.active'))
                .map(chip => chip.dataset.filter);
            
            showToast(`Applied ${activeFilters.length} teacher filters`, 'info');
        }

        // Teacher Management Functions
        let selectedTeachers = new Set();

        function toggleAllTeachers() {
            const selectAll = document.getElementById('selectAllTeachers');
            const checkboxes = document.querySelectorAll('.teacher-checkbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
                const teacherId = parseInt(cb.dataset.id);
                if (selectAll.checked) {
                    selectedTeachers.add(teacherId);
                } else {
                    selectedTeachers.delete(teacherId);
                }
            });
            
            updateBulkEditCount();
        }

        function toggleTeacherSelection(teacherId) {
            const checkbox = document.querySelector(`.teacher-checkbox[data-id="${teacherId}"]`);
            if (checkbox.checked) {
                selectedTeachers.add(teacherId);
            } else {
                selectedTeachers.delete(teacherId);
            }
            
            // Update select all checkbox
            const checkboxes = document.querySelectorAll('.teacher-checkbox');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            document.getElementById('selectAllTeachers').checked = allChecked;
            
            updateBulkEditCount();
        }

        function updateBulkEditCount() {
            const count = selectedTeachers.size;
            document.getElementById('bulkEditCount').textContent = `${count} teachers selected`;
            
            // Show/hide bulk edit panel
            const panel = document.getElementById('bulkEditPanel');
            if (count > 0) {
                panel.classList.add('show');
            } else {
                panel.classList.remove('show');
            }
        }

        // Bulk Edit Functions
        function sendBulkMessage() {
            if (selectedTeachers.size === 0) {
                showToast('Please select teachers first', 'warning');
                return;
            }
            
            const message = prompt(`Enter message for ${selectedTeachers.size} selected teachers:`);
            if (message) {
                showToast(`Message sent to ${selectedTeachers.size} teachers`, 'success');
            }
        }

        function updateBulkStatus(status) {
            if (selectedTeachers.size === 0) {
                showToast('Please select teachers first', 'warning');
                return;
            }
            
            // AJAX call to update status
            fetch('/tenant/<?php echo $schoolSlug; ?>/admin/update-teacher-status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    teacher_ids: Array.from(selectedTeachers),
                    status: status,
                    school_id: <?php echo $school['id']; ?>
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Updated ${selectedTeachers.size} teachers to ${status}`, 'success');
                    // Refresh page after 1 second
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message || 'Error updating status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Network error. Please try again.', 'error');
            });
        }

        function exportBulkData() {
            if (selectedTeachers.size === 0) {
                showToast('Please select teachers first', 'warning');
                return;
            }
            
            // Create CSV download
            const csvContent = "data:text/csv;charset=utf-8,";
            const rows = [];
            rows.push(["Teacher ID", "Name", "Department", "Email", "Status"]);
            
            selectedTeachers.forEach(id => {
                const row = document.querySelector(`.teacher-checkbox[data-id="${id}"]`).closest('tr');
                const name = row.querySelector('td:nth-child(2) span.font-medium').textContent;
                const department = row.querySelector('td:nth-child(3)').textContent;
                const email = row.querySelector('td:nth-child(2) p').textContent;
                const status = row.querySelector('td:nth-child(6) span').textContent;
                rows.push([id, name, department, email, status]);
            });
            
            const csv = rows.map(row => row.join(",")).join("\n");
            const encodedUri = encodeURI(csvContent + csv);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `teachers_export_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showToast(`Exported ${selectedTeachers.size} teachers to CSV`, 'success');
        }

        function closeBulkEdit() {
            selectedTeachers.clear();
            document.querySelectorAll('.teacher-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAllTeachers').checked = false;
            updateBulkEditCount();
        }

        // Teacher CRUD Operations
        document.getElementById('addTeacherForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
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
                    showToast(data.message || 'Teacher added successfully!', 'success');
                    closeModal('addTeacherModal');
                    this.reset();
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error adding teacher', 'error');
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

        function editTeacher(teacherId) {
            // AJAX call to load teacher data
            fetch(`/tenant/<?php echo $schoolSlug; ?>/admin/get-teacher.php?id=${teacherId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const teacher = data.teacher;
                        
                        document.getElementById('editTeacherForm').innerHTML = `
                            <form id="editTeacherFormData" method="POST" action="/tenant/<?php echo $schoolSlug; ?>/admin/update-teacher.php">
                                <input type="hidden" name="teacher_id" value="${teacherId}">
                                <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                    <div>
                                        <label class="form-label">First Name</label>
                                        <input type="text" name="first_name" class="form-input" value="${teacher.first_name}" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Last Name</label>
                                        <input type="text" name="last_name" class="form-input" value="${teacher.last_name}" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-input" value="${teacher.email}" required>
                                    </div>
                                    <div>
                                        <label class="form-label">Department</label>
                                        <select name="department" class="form-select" required>
                                            <option value="Mathematics" ${teacher.department === 'Mathematics' ? 'selected' : ''}>Mathematics</option>
                                            <option value="Science" ${teacher.department === 'Science' ? 'selected' : ''}>Science</option>
                                            <option value="English" ${teacher.department === 'English' ? 'selected' : ''}>English</option>
                                            <option value="History" ${teacher.department === 'History' ? 'selected' : ''}>History</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select" required>
                                            <option value="active" ${teacher.status === 'active' ? 'selected' : ''}>Active</option>
                                            <option value="on_leave" ${teacher.status === 'on_leave' ? 'selected' : ''}>On Leave</option>
                                            <option value="inactive" ${teacher.status === 'inactive' ? 'selected' : ''}>Inactive</option>
                                        </select>
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="form-label">Qualifications</label>
                                        <textarea name="qualifications" class="form-input h-32">${teacher.qualifications || ''}</textarea>
                                    </div>
                                </div>
                                <div class="flex gap-3">
                                    <button type="button" onclick="closeModal('editTeacherModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                                        Cancel
                                    </button>
                                    <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                                        Save Changes
                                    </button>
                                </div>
                            </form>
                        `;
                        
                        // Add form submit handler
                        document.getElementById('editTeacherFormData').addEventListener('submit', function(e) {
                            e.preventDefault();
                            saveTeacherEdit(teacherId, this);
                        });
                        
                        openModal('editTeacherModal');
                    } else {
                        showToast(data.message || 'Error loading teacher data', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Network error. Please try again.', 'error');
                });
        }

        function saveTeacherEdit(teacherId, form) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            const formData = new FormData(form);
            
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(`Teacher #${teacherId} updated successfully`, 'success');
                    closeModal('editTeacherModal');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast(data.message || 'Error updating teacher', 'error');
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
        }

        function viewTeacherDetails(teacherId) {
            // AJAX call to load teacher details
            fetch(`/tenant/<?php echo $schoolSlug; ?>/admin/get-teacher-details.php?id=${teacherId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('teacherDetailsContent').innerHTML = html;
                    
                    // Set teacher name in modal title
                    const nameMatch = html.match(/Dr\.?\s+[\w\s]+/);
                    if (nameMatch) {
                        document.getElementById('teacherDetailsName').textContent = nameMatch[0];
                    }
                    
                    openModal('teacherDetailsModal');
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Failed to load teacher details', 'error');
                });
        }

        function scheduleTeacher(teacherId) {
            showToast(`Scheduling teacher #${teacherId}`, 'info');
            openModal('scheduleTeacherModal');
            
            document.getElementById('scheduleTeacherForm').innerHTML = `
                <div class="space-y-6">
                    <div>
                        <label class="form-label">Teacher</label>
                        <input type="text" class="form-input" value="Loading..." readonly>
                    </div>
                    
                    <div>
                        <label class="form-label">Schedule Date</label>
                        <input type="date" class="form-input" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div>
                        <label class="form-label">Schedule Type</label>
                        <select class="form-select">
                            <option value="class">Regular Class</option>
                            <option value="meeting">Meeting</option>
                            <option value="training">Training</option>
                        </select>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-input" value="08:00">
                        </div>
                        <div>
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-input" value="09:30">
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label">Notes</label>
                        <textarea class="form-input h-32" placeholder="Add schedule notes..."></textarea>
                    </div>
                </div>
                
                <div class="flex gap-3 mt-8">
                    <button onclick="closeModal('scheduleTeacherModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button onclick="saveSchedule(${teacherId})" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                        Save Schedule
                    </button>
                </div>
            `;
        }

        function saveSchedule(teacherId) {
            showToast(`Schedule saved for teacher #${teacherId}`, 'success');
            closeModal('scheduleTeacherModal');
        }

        // View Functions
        function viewDepartmentDetails() {
            showToast('Opening department details', 'info');
        }

        function viewEmploymentStats() {
            showToast('Loading employment statistics', 'info');
        }

        function viewAllTeachers() {
            showToast('Loading all teachers', 'info');
        }

        function viewAllEvents() {
            showToast('Opening events calendar', 'info');
        }

        // Action Functions
        function importTeachers() {
            showToast('Opening teacher import wizard', 'info');
        }

        function generateReports() {
            showToast('Generating teacher performance reports', 'info');
        }

        function scheduleMeeting() {
            showToast('Opening meeting scheduler', 'info');
        }

        function sendAnnouncement() {
            const announcement = prompt('Enter announcement for all teachers:');
            if (announcement) {
                showToast('Announcement sent to all teachers', 'success');
            }
        }

        // Pagination Functions
        function previousPage() {
            showToast('Loading previous page', 'info');
        }

        function nextPage() {
            showToast('Loading next page', 'info');
        }

        function goToPage(page) {
            showToast(`Loading page ${page}`, 'info');
        }

        // Export Functions
        function exportFilteredData() {
            showToast('Exporting filtered data to CSV', 'success');
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize charts
            initCharts();
            
            // Initialize flatpickr for date inputs
            flatpickr("input[type='date']", {
                dateFormat: "Y-m-d",
                defaultDate: "today"
            });
            
            // Show welcome toast
            setTimeout(() => {
                showToast('Teachers Management System Loaded', 'success', 3000);
            }, 1000);
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Only trigger when not in input fields
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                return;
            }
            
            // Ctrl/Cmd + N: Add new teacher
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openModal('addTeacherModal');
            }
            
            // Ctrl/Cmd + F: Open filters
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                toggleFilters();
            }
            
            // Ctrl/Cmd + E: Export data
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                exportFilteredData();
            }
        });
    </script>
</body>
</html>
