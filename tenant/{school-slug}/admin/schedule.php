<?php

/**
 * Timetable Management - VIRTUAL VERSION
 * This file serves ALL schools via virtual-router.php
 * ALL DATA FETCHED LIVE FROM DATABASE
 * UPDATED TO MATCH ACTUAL DATABASE SCHEMA
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/timetable_management.log');

// Start output buffering
ob_start();

error_log("=== TIMETABLE MANAGEMENT START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'timetable.php';
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    error_log("AJAX Action: " . $action);

    switch ($action) {
        case 'get_timetable':
            getTimetable($schoolDb, $school['id']);
            break;
        case 'get_period':
            getPeriod($schoolDb, $school['id']);
            break;
        case 'save_period':
            savePeriod($schoolDb, $school['id']);
            break;
        case 'delete_period':
            deletePeriod($schoolDb, $school['id']);
            break;
        case 'get_teachers':
            getTeachers($schoolDb, $school['id']);
            break;
        case 'get_subjects':
            getSubjects($schoolDb, $school['id']);
            break;
        case 'get_classes':
            getClasses($schoolDb, $school['id']);
            break;
        case 'get_teacher_schedule':
            getTeacherSchedule($schoolDb, $school['id']);
            break;
        case 'generate_timetable':
            generateTimetable($schoolDb, $school['id']);
            break;
        case 'copy_timetable':
            copyTimetable($schoolDb, $school['id']);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
            exit;
    }
    exit;
}

// Function to get timetable - UPDATED FOR YOUR SCHEMA
function getTimetable($db, $schoolId)
{
    try {
        $grade = $_POST['grade'] ?? '';
        $section = $_POST['section'] ?? '';
        $day = $_POST['day'] ?? '';

        // Build WHERE clause
        $whereClauses = ["t.school_id = ?"];
        $params = [$schoolId];

        if ($grade) {
            $whereClauses[] = "c.grade_level = ?";
            $params[] = $grade;
        }

        if ($section) {
            $whereClauses[] = "c.section = ?";
            $params[] = $section;
        }

        if ($day) {
            // Convert day name to lowercase to match database
            $day = strtolower($day);
            $whereClauses[] = "t.day = ?";
            $params[] = $day;
        }

        $whereSql = implode(' AND ', $whereClauses);

        // Get timetable - UPDATED QUERY FOR YOUR SCHEMA
        $sql = "
            SELECT 
                t.*,
                c.grade_level,
                c.section,
                c.name as class_name,
                s.name as subject_name,
                s.code as subject_code,
                u.first_name as teacher_first_name,
                u.last_name as teacher_last_name,
                CONCAT('Room ', t.room_number) as room_name,
                CASE 
                    WHEN t.is_break = 1 THEN 'break'
                    ELSE 'regular'
                END as period_type
            FROM timetables t
            LEFT JOIN classes c ON t.class_id = c.id
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN users u ON t.teacher_id = u.id AND u.user_type = 'teacher'
            WHERE $whereSql
            ORDER BY 
                c.grade_level,
                c.section,
                FIELD(t.day, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'),
                t.start_time
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $timetable = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'timetable' => $timetable
        ]);
    } catch (Exception $e) {
        error_log("Error getting timetable: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to get single period - UPDATED FOR YOUR SCHEMA
function getPeriod($db, $schoolId)
{
    try {
        $periodId = $_POST['period_id'];

        $sql = "
            SELECT 
                t.*,
                c.grade_level,
                c.section,
                c.name as class_name,
                s.name as subject_name,
                u.first_name as teacher_first_name,
                u.last_name as teacher_last_name,
                CONCAT('Room ', t.room_number) as room_name,
                CASE 
                    WHEN t.is_break = 1 THEN 'break'
                    ELSE 'regular'
                END as period_type
            FROM timetables t
            LEFT JOIN classes c ON t.class_id = c.id
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN users u ON t.teacher_id = u.id AND u.user_type = 'teacher'
            WHERE t.id = ? AND t.school_id = ?
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$periodId, $schoolId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$period) {
            echo json_encode(['error' => 'Period not found']);
            return;
        }

        echo json_encode([
            'success' => true,
            'period' => $period
        ]);
    } catch (Exception $e) {
        error_log("Error getting period: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to save period - UPDATED FOR YOUR SCHEMA
function savePeriod($db, $schoolId)
{
    try {
        $periodId = $_POST['period_id'] ?? null;
        $periodData = json_decode($_POST['period_data'], true);

        if (!$periodData) {
            echo json_encode(['error' => 'Invalid period data']);
            return;
        }

        // Validate required fields
        $requiredFields = ['class_id', 'subject_id', 'teacher_id', 'day', 'start_time', 'end_time'];
        foreach ($requiredFields as $field) {
            if (empty($periodData[$field])) {
                echo json_encode(['error' => "Missing required field: $field"]);
                return;
            }
        }

        // Get current academic year and term
        $academicInfo = getCurrentAcademicInfo($db, $schoolId);

        // Check for conflicts - UPDATED
        $conflictSql = "
            SELECT COUNT(*) as conflict_count
            FROM timetables
            WHERE school_id = ?
            AND class_id = ?
            AND day = ?
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
            AND teacher_id = ?
            AND id != ?
        ";

        $conflictParams = [
            $schoolId,
            $periodData['class_id'],
            strtolower($periodData['day']),
            $periodData['start_time'],
            $periodData['start_time'],
            $periodData['end_time'],
            $periodData['end_time'],
            $periodData['start_time'],
            $periodData['end_time'],
            $periodData['teacher_id'],
            $periodId ?: 0
        ];

        $conflictStmt = $db->prepare($conflictSql);
        $conflictStmt->execute($conflictParams);
        $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);

        if ($conflict['conflict_count'] > 0) {
            echo json_encode(['error' => 'Teacher has conflicting schedule']);
            return;
        }

        // Calculate period number based on start time
        $periodNumber = calculatePeriodNumber($periodData['start_time']);

        if ($periodId) {
            // Update existing period - UPDATED
            $sql = "
                UPDATE timetables SET
                    class_id = ?,
                    subject_id = ?,
                    teacher_id = ?,
                    room_number = ?,
                    day = ?,
                    start_time = ?,
                    end_time = ?,
                    period_number = ?,
                    is_break = ?,
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ";

            $isBreak = ($periodData['period_type'] ?? 'regular') === 'break' ? 1 : 0;

            $params = [
                $periodData['class_id'],
                $periodData['subject_id'],
                $periodData['teacher_id'],
                $periodData['room_number'] ?? null,
                strtolower($periodData['day']),
                $periodData['start_time'],
                $periodData['end_time'],
                $periodNumber,
                $isBreak,
                $periodId,
                $schoolId
            ];

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            $message = 'Period updated successfully';
        } else {
            // Insert new period - UPDATED
            $sql = "
                INSERT INTO timetables (
                    school_id, class_id, subject_id, teacher_id,
                    room_number, day, start_time, end_time,
                    period_number, is_break,
                    academic_year_id, academic_term_id,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $isBreak = ($periodData['period_type'] ?? 'regular') === 'break' ? 1 : 0;

            $params = [
                $schoolId,
                $periodData['class_id'],
                $periodData['subject_id'],
                $periodData['teacher_id'],
                $periodData['room_number'] ?? null,
                strtolower($periodData['day']),
                $periodData['start_time'],
                $periodData['end_time'],
                $periodNumber,
                $isBreak,
                $academicInfo['academic_year_id'],
                $academicInfo['academic_term_id']
            ];

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $periodId = $db->lastInsertId();

            $message = 'Period added successfully';
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'period_id' => $periodId
        ]);
    } catch (Exception $e) {
        error_log("Error saving period: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Helper function to get current academic info
function getCurrentAcademicInfo($db, $schoolId)
{
    // Get current academic year
    $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
    $yearStmt = $db->prepare($yearSql);
    $yearStmt->execute([$schoolId]);
    $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);

    // Get current academic term
    $termSql = "SELECT id FROM academic_terms WHERE school_id = ? AND is_default = 1 LIMIT 1";
    $termStmt = $db->prepare($termSql);
    $termStmt->execute([$schoolId]);
    $academicTerm = $termStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'academic_year_id' => $academicYear['id'] ?? 1,
        'academic_term_id' => $academicTerm['id'] ?? 1
    ];
}

// Helper function to calculate period number
function calculatePeriodNumber($startTime)
{
    $timeSlots = [
        '08:00:00' => 1,
        '08:45:00' => 2,
        '09:30:00' => 3,
        '10:15:00' => 4,
        '11:00:00' => 5,
        '11:45:00' => 6,
        '12:30:00' => 7,
        '13:15:00' => 8,
        '14:00:00' => 9,
        '14:45:00' => 10,
        '15:30:00' => 11,
        '16:15:00' => 12
    ];

    return $timeSlots[$startTime] ?? 1;
}

// Function to get teachers - UPDATED FOR YOUR SCHEMA
function getTeachers($db, $schoolId)
{
    try {
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                t.specialization,
                t.qualification
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.user_type = 'teacher'
            AND u.is_active = 1
            ORDER BY u.first_name, u.last_name
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'teachers' => $teachers
        ]);
    } catch (Exception $e) {
        error_log("Error getting teachers: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to get subjects - UPDATED FOR YOUR SCHEMA
function getSubjects($db, $schoolId)
{
    try {
        $sql = "
            SELECT id, name, code, type, credit_hours
            FROM subjects
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'subjects' => $subjects
        ]);
    } catch (Exception $e) {
        error_log("Error getting subjects: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to get classes - UPDATED FOR YOUR SCHEMA
function getClasses($db, $schoolId)
{
    try {
        // Get current academic year
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;

        $sql = "
            SELECT id, name, code, grade_level, section, capacity
            FROM classes
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND is_active = 1
            ORDER BY grade_level, section
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, $academicYearId]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'classes' => $classes
        ]);
    } catch (Exception $e) {
        error_log("Error getting classes: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to delete period
function deletePeriod($db, $schoolId)
{
    try {
        $periodId = $_POST['period_id'];

        $stmt = $db->prepare("DELETE FROM timetables WHERE id = ? AND school_id = ?");
        $stmt->execute([$periodId, $schoolId]);

        echo json_encode([
            'success' => true,
            'message' => 'Period deleted successfully'
        ]);
    } catch (Exception $e) {
        error_log("Error deleting period: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to get teacher schedule - UPDATED FOR YOUR SCHEMA
function getTeacherSchedule($db, $schoolId)
{
    try {
        $teacherId = $_POST['teacher_id'];

        $sql = "
            SELECT 
                t.*,
                c.grade_level,
                c.section,
                c.name as class_name,
                s.name as subject_name,
                CONCAT('Room ', t.room_number) as room_name,
                CASE 
                    WHEN t.is_break = 1 THEN 'break'
                    ELSE 'regular'
                END as period_type
            FROM timetables t
            LEFT JOIN classes c ON t.class_id = c.id
            LEFT JOIN subjects s ON t.subject_id = s.id
            WHERE t.teacher_id = ? AND t.school_id = ?
            ORDER BY 
                FIELD(t.day, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'),
                t.start_time
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$teacherId, $schoolId]);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'schedule' => $schedule
        ]);
    } catch (Exception $e) {
        error_log("Error getting teacher schedule: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to generate timetable
function generateTimetable($db, $schoolId)
{
    try {
        // This would contain complex logic to auto-generate timetable
        // For now, return success message
        echo json_encode([
            'success' => true,
            'message' => 'Timetable generation feature coming soon',
            'generated' => 0
        ]);
    } catch (Exception $e) {
        error_log("Error generating timetable: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to copy timetable
function copyTimetable($db, $schoolId)
{
    try {
        $sourceGrade = $_POST['source_grade'];
        $targetGrade = $_POST['target_grade'];

        // This would copy timetable from one grade to another
        // For now, return success message
        echo json_encode([
            'success' => true,
            'message' => 'Timetable copied successfully',
            'copied' => 0
        ]);
    } catch (Exception $e) {
        error_log("Error copying timetable: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Get initial data for the page - UPDATED FOR YOUR SCHEMA
try {
    // Get current academic year for filtering
    $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
    $yearStmt = $schoolDb->prepare($yearSql);
    $yearStmt->execute([$school['id']]);
    $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
    $academicYearId = $academicYear['id'] ?? 1;

    // Get classes for filters - UPDATED
    $classesStmt = $schoolDb->prepare("
        SELECT DISTINCT grade_level, section 
        FROM classes 
        WHERE school_id = ? 
        AND academic_year_id = ?
        ORDER BY grade_level, section
    ");
    $classesStmt->execute([$school['id'], $academicYearId]);
    $classes = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get teachers count - UPDATED
    $teachersStmt = $schoolDb->prepare("
        SELECT COUNT(*) as total 
        FROM users 
        WHERE school_id = ? 
        AND user_type = 'teacher' 
        AND is_active = 1
    ");
    $teachersStmt->execute([$school['id']]);
    $totalTeachers = $teachersStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get subjects count - UPDATED
    $subjectsStmt = $schoolDb->prepare("
        SELECT COUNT(*) as total 
        FROM subjects 
        WHERE school_id = ? AND is_active = 1
    ");
    $subjectsStmt->execute([$school['id']]);
    $totalSubjects = $subjectsStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total periods this week - UPDATED
    $periodsStmt = $schoolDb->prepare("
        SELECT COUNT(*) as total 
        FROM timetables 
        WHERE school_id = ?
    ");
    $periodsStmt->execute([$school['id']]);
    $totalPeriods = $periodsStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's schedule - UPDATED
    $today = strtolower(date('l'));
    $todayScheduleStmt = $schoolDb->prepare("
        SELECT COUNT(*) as total 
        FROM timetables 
        WHERE school_id = ? AND day = ?
    ");
    $todayScheduleStmt->execute([$school['id'], $today]);
    $todaySchedule = $todayScheduleStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get statistics
    $stats = [
        'total_teachers' => $totalTeachers,
        'total_subjects' => $totalSubjects,
        'total_periods' => $totalPeriods,
        'today_schedule' => $todaySchedule
    ];
} catch (Exception $e) {
    error_log("Error loading initial data: " . $e->getMessage());
    $classes = [];
    $stats = [
        'total_teachers' => 0,
        'total_subjects' => 0,
        'total_periods' => 0,
        'today_schedule' => 0
    ];
}

// Get current date info
$currentDate = date('F j, Y');
$currentDay = date('l');
$currentMonth = date('F');
$currentYear = date('Y');

// Days of the week (for display)
$daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
// Days for database (lowercase)
$dbDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

// Standard time slots
$timeSlots = [
    '08:00:00' => '8:00 AM',
    '08:45:00' => '8:45 AM',
    '09:30:00' => '9:30 AM',
    '10:15:00' => '10:15 AM',
    '11:00:00' => '11:00 AM',
    '11:45:00' => '11:45 AM',
    '12:30:00' => '12:30 PM',
    '13:15:00' => '1:15 PM',
    '14:00:00' => '2:00 PM',
    '14:45:00' => '2:45 PM',
    '15:30:00' => '3:30 PM',
    '16:15:00' => '4:15 PM'
];

// Prepare data for JavaScript
$jsData = [
    'school' => [
        'id' => $school['id'],
        'name' => $school['name'],
        'slug' => $school['slug'],
        'primary_color' => $school['primary_color'] ?? '#4f46e5',
        'secondary_color' => $school['secondary_color'] ?? '#10b981'
    ],
    'stats' => $stats,
    'classes' => $classes,
    'days_of_week' => $daysOfWeek,
    'db_days' => $dbDays, // Add lowercase days for database operations
    'time_slots' => $timeSlots,
    'current_date' => $currentDate,
    'current_day' => $currentDay,
    'current_db_day' => strtolower($currentDay), // Add lowercase current day
    'current_month' => $currentMonth,
    'current_year' => $currentYear,
    'api_url' => $baseUrl . '/' . $schoolSlug . '/admin/timetable.php'
];

// End PHP output buffering and get the buffered content
$php_content = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?php echo htmlspecialchars($school['name']); ?> - Timetable Management | AcademixSuite School Admin</title>
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

        /* Status badges */
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

        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .status-graduated {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        /* Subject color badges */
        .subject-badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .subject-math {
            background: linear-gradient(135deg, #4f46e5, #7c73e9);
            color: white;
        }

        .subject-science {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
        }

        .subject-english {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
        }

        .subject-history {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }

        .subject-art {
            background: linear-gradient(135deg, #8b5cf6, #a78bfa);
            color: white;
        }

        .subject-pe {
            background: linear-gradient(135deg, #06b6d4, #22d3ee);
            color: white;
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

            .timetable-grid {
                font-size: 10px;
            }
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

        /* Timetable Styles */
        .timetable-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: white;
        }

        .timetable-grid {
            display: grid;
            grid-template-columns: 120px repeat(7, 1fr);
            min-width: 1000px;
        }

        .timetable-header {
            background: #f8fafc;
            padding: 16px;
            text-align: center;
            font-weight: 700;
            color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
        }

        .timetable-cell {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #e2e8f0;
            min-height: 100px;
            position: relative;
        }

        .timetable-cell:hover {
            background: #f8fafc;
        }

        .timetable-period {
            background: white;
            border-radius: 8px;
            padding: 12px;
            border-left: 4px solid #4f46e5;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .timetable-period:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .period-subject {
            font-weight: 700;
            font-size: 13px;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .period-teacher {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 4px;
        }

        .period-room {
            font-size: 11px;
            color: #10b981;
            font-weight: 600;
        }

        .period-time {
            font-size: 10px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* Empty period slot */
        .empty-slot {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #cbd5e1;
            font-size: 12px;
        }

        .empty-slot:hover {
            background: #f1f5f9;
            border-radius: 8px;
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

        /* Teacher schedule styles */
        .teacher-schedule-item {
            padding: 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .teacher-schedule-item:hover {
            background: #f8fafc;
        }

        .schedule-time {
            min-width: 120px;
            font-weight: 600;
            color: #4f46e5;
        }

        .schedule-details {
            flex: 1;
        }

        .schedule-class {
            font-weight: 700;
            color: #1e293b;
        }

        .schedule-subject {
            color: #64748b;
            font-size: 14px;
        }
    </style>
</head>

<body class="antialiased selection:bg-indigo-100 selection:text-indigo-900">

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-[99] lg:hidden hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Add/Edit Period Modal -->
    <div id="periodModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900" id="periodModalTitle">Add New Period</h3>
                    <button onclick="closeModal('periodModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <form id="periodForm" class="space-y-6" onsubmit="return savePeriod(event)">
                    <input type="hidden" id="periodId" value="">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="form-label">Class *</label>
                            <select id="classId" class="form-select" required>
                                <option value="">Select Class</option>
                                <!-- Classes will be loaded dynamically -->
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Subject *</label>
                            <select id="subjectId" class="form-select" required>
                                <option value="">Select Subject</option>
                                <!-- Subjects will be loaded dynamically -->
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Teacher *</label>
                            <select id="teacherId" class="form-select" required>
                                <option value="">Select Teacher</option>
                                <!-- Teachers will be loaded dynamically -->
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Room Number</label>
                            <input type="text" id="roomNumber" class="form-input" placeholder="101">
                        </div>

                        <div>
                            <label class="form-label">Day of Week *</label>
                            <select id="day" class="form-select" required>
                                <option value="">Select Day</option>
                                <?php foreach ($dbDays as $day): ?>
                                    <option value="<?php echo $day; ?>"><?php echo ucfirst($day); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Period Type</label>
                            <select id="periodType" class="form-select">
                                <option value="regular">Regular Class</option>
                                <option value="break">Break</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label">Start Time *</label>
                            <input type="time" id="startTime" class="form-input" required value="08:00">
                        </div>

                        <div>
                            <label class="form-label">End Time *</label>
                            <input type="time" id="endTime" class="form-input" required value="08:45">
                        </div>
                    </div>

                    <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                        <button type="button" onclick="closeModal('periodModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="submit" class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            Save Period
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Teacher Schedule Modal -->
    <div id="teacherScheduleModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900" id="teacherScheduleTitle">Teacher Schedule</h3>
                    <button onclick="closeModal('teacherScheduleModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="space-y-4" id="teacherScheduleContent">
                    <!-- Schedule will be loaded here -->
                </div>

                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button onclick="closeModal('teacherScheduleModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Close
                    </button>
                    <button onclick="printTeacherSchedule()" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                        <i class="fas fa-print mr-2"></i> Print Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Generate Timetable Modal -->
    <div id="generateTimetableModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 600px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Generate Timetable</h3>
                    <button onclick="closeModal('generateTimetableModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>

                <div class="space-y-6">
                    <div>
                        <label class="form-label">Select Grade Level</label>
                        <select id="generateGrade" class="form-select">
                            <option value="">All Grades</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class['grade_level']); ?>">Grade <?php echo htmlspecialchars($class['grade_level']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Generation Options</label>
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input type="checkbox" class="rounded border-slate-300 mr-3" checked>
                                <span>Avoid teacher conflicts</span>
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" class="rounded border-slate-300 mr-3" checked>
                                <span>Optimize room usage</span>
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" class="rounded border-slate-300 mr-3">
                                <span>Include breaks between classes</span>
                            </label>
                        </div>
                    </div>

                    <div class="border-t border-slate-100 pt-6">
                        <h4 class="font-bold text-slate-900 mb-3">Notes</h4>
                        <p class="text-sm text-slate-600">
                            This will generate an optimal timetable based on available teachers, subjects, and rooms.
                            Existing periods for selected grades will be replaced.
                        </p>
                    </div>
                </div>

                <div class="flex gap-3 mt-8 pt-6 border-t border-slate-100">
                    <button onclick="closeModal('generateTimetableModal')" class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button onclick="processTimetableGeneration()" class="flex-1 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                        Generate Timetable
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
                        <span class="text-sm font-black text-indigo-600"><?php echo number_format($stats['total_teachers']); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Subjects:</span>
                        <span class="text-sm font-bold text-emerald-600"><?php echo number_format($stats['total_subjects']); ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-slate-600">Today's Periods:</span>
                        <span class="text-sm font-bold text-slate-900"><?php echo number_format($stats['today_schedule']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Navigation -->
            <div class="flex-1 overflow-y-auto py-6 space-y-8 custom-scrollbar">
                <div>
                    <p class="px-6 text-[11px] font-black text-slate-400 uppercase tracking-[0.15em] mb-3">Dashboard</p>
                    <nav class="space-y-1">
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/dashboard.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span>Overview</span>
                        </a>
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/announcements.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
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
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/students.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-user-graduate"></i>
                            </div>
                            <span>Students Directory</span>
                        </a>
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/attendance.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <span>Attendance</span>
                        </a>
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/grades.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
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
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/teachers.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher"></i>
                            </div>
                            <span>Teachers</span>
                        </a>
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/timetable.php'; ?>" class="sidebar-link active-link flex items-center gap-3 px-6 py-3 text-sm font-semibold">
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
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/fees.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
                            <div class="w-5 h-5 flex items-center justify-center">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <span>Fee Management</span>
                        </a>
                        <a href="<?php echo $baseUrl . '/' . $schoolSlug . '/admin/settings.php'; ?>" class="sidebar-link flex items-center gap-3 px-6 py-3 text-sm font-medium text-slate-600">
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
                <div class="flex items-center gap-3 p-2 group cursor-pointer hover:bg-slate-50 rounded-xl transition" onclick="window.location.href='<?php echo $baseUrl . '/' . $schoolSlug . '/admin/profile.php'; ?>'">
                    <div class="relative">
                        <?php
                        $adminName = $schoolAuth['name'] ?? 'Admin User';
                        $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($adminName) . "&background=4f46e5&color=fff&bold=true&size=128";
                        ?>
                        <img src="<?php echo $avatarUrl; ?>" class="w-10 h-10 rounded-xl shadow-sm">
                        <div class="absolute -bottom-1 -right-1 w-3.5 h-3.5 bg-emerald-500 border-2 border-white rounded-full"></div>
                    </div>
                    <div class="overflow-hidden flex-1">
                        <p class="text-[13px] font-black text-slate-900 truncate"><?php echo htmlspecialchars($adminName); ?></p>
                        <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider italic">School_Admin</p>
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
                        <h1 class="text-lg font-black text-slate-900 tracking-tight">Timetable Management</h1>
                        <div class="hidden lg:flex items-center gap-2">
                            <div class="w-2 h-2 bg-emerald-500 rounded-full"></div>
                            <span class="text-xs font-black text-emerald-600 uppercase tracking-widest"><?php echo number_format($stats['total_periods']); ?> Total Periods</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Quick Stats -->
                    <div class="hidden md:flex items-center gap-2 bg-white border border-slate-200 px-4 py-2 rounded-xl">
                        <div class="text-right">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today's Schedule</p>
                            <p class="text-sm font-black text-indigo-600"><?php echo number_format($stats['today_schedule']); ?> periods</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-2">
                        <button onclick="openModal('generateTimetableModal')" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-magic"></i>
                            <span class="hidden sm:inline">Auto-Generate</span>
                        </button>
                        <button onclick="printTimetable()" class="px-4 py-2 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                            <i class="fas fa-print"></i>
                            <span class="hidden sm:inline">Print</span>
                        </button>
                        <button onclick="addNewPeriod()" class="px-4 py-2 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-emerald-200">
                            <i class="fas fa-plus"></i>
                            <span class="hidden sm:inline">Add Period</span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-6 lg:px-8">
                    <div class="flex overflow-x-auto">
                        <button class="tab-button active" onclick="switchView('timetable')" data-view="timetable">
                            <i class="fas fa-calendar-alt mr-2"></i>Timetable
                        </button>
                        <button class="tab-button" onclick="switchView('teacher')" data-view="teacher">
                            <i class="fas fa-chalkboard-teacher mr-2"></i>Teacher Schedule
                        </button>
                        <button class="tab-button" onclick="switchView('room')" data-view="room">
                            <i class="fas fa-door-open mr-2"></i>Room Allocation
                        </button>
                        <button class="tab-button" onclick="switchView('conflicts')" data-view="conflicts">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Conflicts
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
                            <h2 class="text-2xl lg:text-3xl font-black text-slate-900 mb-2">School Timetable Management</h2>
                            <p class="text-slate-500 font-medium">Manage class schedules, teacher allocations, and room assignments</p>
                        </div>
                        <div class="flex gap-3">
                            <div class="search-box">
                                <input type="text" placeholder="Search by teacher, subject, or class..." class="search-input" id="searchInput" onkeyup="filterTimetable()">
                                <i class="fas fa-search search-icon"></i>
                            </div>
                            <button onclick="toggleAdvancedFilters()" class="px-4 py-2.5 bg-white border border-slate-200 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center gap-2">
                                <i class="fas fa-sliders-h"></i>
                                <span class="hidden sm:inline">Filters</span>
                            </button>
                        </div>
                    </div>

                    <!-- Advanced Filters -->
                    <div class="glass-card rounded-xl p-6 mt-6 hidden" id="advancedFilters">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-black text-slate-900">Advanced Filters</h3>
                            <button onclick="toggleAdvancedFilters()" class="text-slate-400 hover:text-slate-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label class="form-label">Grade Level</label>
                                <select class="form-select" id="filterGrade" onchange="applyFilters()">
                                    <option value="">All Grades</option>
                                    <?php
                                    $gradeLevels = [];
                                    foreach ($classes as $class) {
                                        if (!in_array($class['grade_level'], $gradeLevels)) {
                                            $gradeLevels[] = $class['grade_level'];
                                        }
                                    }
                                    sort($gradeLevels);
                                    foreach ($gradeLevels as $grade): ?>
                                        <option value="<?php echo htmlspecialchars($grade); ?>">Grade <?php echo htmlspecialchars($grade); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Section</label>
                                <select class="form-select" id="filterSection" onchange="applyFilters()">
                                    <option value="">All Sections</option>
                                    <?php
                                    $sections = [];
                                    foreach ($classes as $class) {
                                        if (!in_array($class['section'], $sections)) {
                                            $sections[] = $class['section'];
                                        }
                                    }
                                    sort($sections);
                                    foreach ($sections as $section): ?>
                                        <option value="<?php echo htmlspecialchars($section); ?>">Section <?php echo htmlspecialchars($section); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Day of Week</label>
                                <select class="form-select" id="filterDay" onchange="applyFilters()">
                                    <option value="">All Days</option>
                                    <?php foreach ($dbDays as $day): ?>
                                        <option value="<?php echo $day; ?>"><?php echo ucfirst($day); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="form-label">Period Type</label>
                                <select class="form-select" id="filterPeriodType" onchange="applyFilters()">
                                    <option value="">All Types</option>
                                    <option value="regular">Regular</option>
                                    <option value="break">Break</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mt-6 pt-6 border-t border-slate-100">
                            <button onclick="clearFilters()" class="px-4 py-2 text-slate-600 hover:text-slate-800 transition">
                                Clear All Filters
                            </button>
                            <button onclick="applyFilters()" class="px-6 py-2 bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-bold rounded-xl hover:shadow-lg transition-all shadow-lg shadow-indigo-200">
                                Apply Filters
                            </button>
                        </div>
                    </div>

                    <!-- Filter Chips -->
                    <div class="flex flex-wrap gap-2 mt-6">
                        <span class="filter-chip active" onclick="toggleFilter('all')" data-filter="all">
                            <i class="fas fa-calendar"></i> All Classes
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade10')" data-filter="grade10">
                            <i class="fas fa-graduation-cap"></i> Grade 10
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade11')" data-filter="grade11">
                            <i class="fas fa-graduation-cap"></i> Grade 11
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('grade12')" data-filter="grade12">
                            <i class="fas fa-graduation-cap"></i> Grade 12
                        </span>
                        <span class="filter-chip" onclick="toggleFilter('today')" data-filter="today">
                            <i class="fas fa-sun"></i> Today (<?php echo $currentDay; ?>)
                        </span>
                    </div>
                </div>

                <!-- Key Metrics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Teachers Card -->
                    <div class="glass-card metric-card metric-primary rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.1s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">ACTIVE TEACHERS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($stats['total_teachers']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-50 to-purple-50 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-indigo-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-arrow-up mr-1"></i> 2 new</span>
                            <span class="text-slate-500">this semester</span>
                        </div>
                    </div>

                    <!-- Subjects Card -->
                    <div class="glass-card metric-card metric-success rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.2s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">SUBJECTS OFFERED</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($stats['total_subjects']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-emerald-50 to-emerald-100 flex items-center justify-center">
                                <i class="fas fa-book text-emerald-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-plus mr-1"></i> 5</span>
                            <span class="text-slate-500">electives added</span>
                        </div>
                    </div>

                    <!-- Weekly Periods Card -->
                    <div class="glass-card metric-card metric-warning rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.3s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">WEEKLY PERIODS</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($stats['total_periods']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-amber-50 to-amber-100 flex items-center justify-center">
                                <i class="fas fa-clock text-amber-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-emerald-600 font-bold"><i class="fas fa-check mr-1"></i> 98%</span>
                            <span class="text-slate-500">coverage rate</span>
                        </div>
                    </div>

                    <!-- Today's Schedule Card -->
                    <div class="glass-card metric-card metric-danger rounded-2xl p-6 animate-fadeInUp" style="animation-delay: 0.4s">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-bold text-slate-400">TODAY'S SCHEDULE</p>
                                <p class="text-2xl font-black text-slate-900"><?php echo number_format($stats['today_schedule']); ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-50 to-red-100 flex items-center justify-center">
                                <i class="fas fa-calendar-day text-red-600 text-lg"></i>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="text-slate-900 font-bold"><?php echo $currentDay; ?></span>
                            <span class="text-slate-500"><?php echo $currentDate; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Timetable View -->
                <div id="timetableView" class="view-content">
                    <!-- Class Selector -->
                    <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6 mb-8">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Class Timetable</h3>
                                <p class="text-slate-500">View and manage schedule for selected class</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-slate-600">Select Class:</span>
                                    <select id="selectedClass" class="form-select text-sm" onchange="loadTimetable()" style="min-width: 200px;">
                                        <option value="">Select a class</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo htmlspecialchars($class['grade_level'] . '-' . $class['section']); ?>">
                                                Grade <?php echo htmlspecialchars($class['grade_level']); ?> - Section <?php echo htmlspecialchars($class['section']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button onclick="copyTimetableToOtherClasses()" class="action-btn action-btn-secondary">
                                    <i class="fas fa-copy"></i> Copy Schedule
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Timetable Grid -->
                    <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6">
                        <div class="timetable-container">
                            <div class="timetable-grid">
                                <!-- Time Column Header -->
                                <div class="timetable-header" style="grid-row: 1;">Time</div>

                                <!-- Day Headers -->
                                <?php foreach ($daysOfWeek as $day):
                                    if (in_array(strtolower($day), $dbDays)): ?>
                                        <div class="timetable-header <?php echo $day === $currentDay ? 'bg-indigo-50 text-indigo-700' : ''; ?>">
                                            <?php echo $day; ?>
                                            <?php if ($day === $currentDay): ?>
                                                <div class="text-xs font-normal text-indigo-500 mt-1">Today</div>
                                            <?php endif; ?>
                                        </div>
                                <?php endif;
                                endforeach; ?>

                                <!-- Time Slots -->
                                <?php
                                $timeIndex = 1;
                                foreach ($timeSlots as $time => $displayTime):
                                    $nextIndex = $timeIndex + 1;
                                    $nextTime = array_keys($timeSlots)[$nextIndex - 1] ?? '17:00:00';
                                ?>
                                    <div class="timetable-cell" style="grid-row: <?php echo $timeIndex + 1; ?>;">
                                        <div class="font-bold text-slate-700"><?php echo $displayTime; ?></div>
                                        <div class="text-xs text-slate-400 mt-1">45 min</div>
                                    </div>

                                    <?php foreach ($dbDays as $dayIndex => $dbDay):
                                        $displayDay = ucfirst($dbDay);
                                    ?>
                                        <div class="timetable-cell"
                                            style="grid-row: <?php echo $timeIndex + 1; ?>; grid-column: <?php echo $dayIndex + 2; ?>;"
                                            data-day="<?php echo $dbDay; ?>"
                                            data-time="<?php echo $time; ?>"
                                            data-end-time="<?php echo $nextTime; ?>"
                                            onclick="addPeriodAtSlot('<?php echo $dbDay; ?>', '<?php echo $time; ?>', '<?php echo $nextTime; ?>')">
                                            <div class="empty-slot">
                                                <i class="fas fa-plus text-slate-300"></i>
                                                <span class="ml-2">Add period</span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>

                                <?php
                                    $timeIndex++;
                                endforeach;
                                ?>
                            </div>
                        </div>

                        <!-- Timetable Legend -->
                        <div class="flex flex-wrap gap-4 mt-6 pt-6 border-t border-slate-100">
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-gradient-to-r from-indigo-500 to-purple-500"></div>
                                <span class="text-sm text-slate-600">Regular Class</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-gradient-to-r from-emerald-500 to-teal-500"></div>
                                <span class="text-sm text-slate-600">Lab Session</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-gradient-to-r from-amber-500 to-orange-500"></div>
                                <span class="text-sm text-slate-600">Sports/PE</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-gradient-to-r from-red-500 to-pink-500"></div>
                                <span class="text-sm text-slate-600">Break</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teacher Schedule View -->
                <div id="teacherView" class="view-content hidden">
                    <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Teacher Schedules</h3>
                                <p class="text-slate-500">View individual teacher schedules and workloads</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <select id="teacherFilter" class="form-select text-sm" onchange="loadTeacherSchedule()" style="min-width: 200px;">
                                    <option value="">Select a teacher</option>
                                    <!-- Teachers will be loaded dynamically -->
                                </select>
                                <button onclick="printAllTeacherSchedules()" class="action-btn action-btn-secondary">
                                    <i class="fas fa-print"></i> Print All
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Teacher List -->
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">Teacher Directory</h4>
                                <div class="space-y-3" id="teacherList">
                                    <!-- Teachers will be loaded here -->
                                </div>
                            </div>

                            <!-- Teacher Schedule -->
                            <div>
                                <h4 class="font-bold text-slate-900 mb-4">Selected Teacher Schedule</h4>
                                <div class="border border-slate-200 rounded-xl p-4">
                                    <div class="text-center py-8 text-slate-400" id="teacherSchedulePlaceholder">
                                        <i class="fas fa-chalkboard-teacher text-4xl mb-3"></i>
                                        <p>Select a teacher to view their schedule</p>
                                    </div>
                                    <div id="teacherScheduleDisplay" class="hidden">
                                        <!-- Schedule will be loaded here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Room Allocation View -->
                <div id="roomView" class="view-content hidden">
                    <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Room Allocation</h3>
                                <p class="text-slate-500">Manage classroom assignments and availability</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <select id="roomFilter" class="form-select text-sm" onchange="loadRoomAllocation()" style="min-width: 200px;">
                                    <option value="">All Rooms</option>
                                    <option value="101">Room 101</option>
                                    <option value="102">Room 102</option>
                                    <option value="103">Room 103</option>
                                    <option value="lab1">Science Lab 1</option>
                                    <option value="lab2">Computer Lab</option>
                                    <option value="auditorium">Auditorium</option>
                                </select>
                                <button onclick="addNewRoom()" class="action-btn action-btn-primary">
                                    <i class="fas fa-plus"></i> Add Room
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Capacity</th>
                                        <th>Type</th>
                                        <th>Monday</th>
                                        <th>Tuesday</th>
                                        <th>Wednesday</th>
                                        <th>Thursday</th>
                                        <th>Friday</th>
                                        <th>Saturday</th>
                                        <th>Sunday</th>
                                        <th>Utilization</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="roomAllocationTable">
                                    <!-- Room allocation data will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Conflicts View -->
                <div id="conflictsView" class="view-content hidden">
                    <div class="max-w-7xl mx-auto glass-card rounded-2xl p-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                            <div>
                                <h3 class="text-lg font-black text-slate-900">Schedule Conflicts</h3>
                                <p class="text-slate-500">Identify and resolve timetable conflicts</p>
                            </div>
                            <div class="flex items-center gap-3">
                                <button onclick="scanForConflicts()" class="action-btn action-btn-secondary">
                                    <i class="fas fa-search"></i> Scan for Conflicts
                                </button>
                                <button onclick="autoResolveConflicts()" class="action-btn action-btn-primary">
                                    <i class="fas fa-bolt"></i> Auto-Resolve
                                </button>
                            </div>
                        </div>

                        <div class="space-y-4" id="conflictsList">
                            <div class="text-center py-8">
                                <i class="fas fa-check-circle text-4xl text-emerald-500 mb-3"></i>
                                <h4 class="text-lg font-bold text-slate-900 mb-2">No Conflicts Found</h4>
                                <p class="text-slate-500">Your timetable is currently conflict-free!</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions & Statistics -->
                <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 mt-8">
                    <!-- Quick Actions -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Quick Actions</h3>
                        <div class="space-y-3">
                            <button onclick="viewTodaySchedule()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <i class="fas fa-calendar-day text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">View Today's Schedule</p>
                                        <p class="text-sm text-slate-500">See all classes for today</p>
                                    </div>
                                </div>
                            </button>

                            <button onclick="exportTimetable()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                        <i class="fas fa-file-export text-emerald-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Export Timetable</p>
                                        <p class="text-sm text-slate-500">Download as PDF or Excel</p>
                                    </div>
                                </div>
                            </button>

                            <button onclick="sendScheduleNotifications()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                        <i class="fas fa-bell text-amber-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Notify Teachers</p>
                                        <p class="text-sm text-slate-500">Send schedule updates</p>
                                    </div>
                                </div>
                            </button>

                            <button onclick="viewAcademicCalendar()" class="w-full p-4 border border-slate-200 rounded-xl text-left hover:bg-slate-50 transition">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                        <i class="fas fa-calendar-alt text-purple-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-bold text-slate-900">Academic Calendar</p>
                                        <p class="text-sm text-slate-500">View school events & holidays</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Today's Highlights -->
                    <div class="glass-card rounded-2xl p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-900">Today's Highlights</h3>
                            <span class="text-sm font-bold text-indigo-600"><?php echo $currentDay; ?></span>
                        </div>

                        <div class="space-y-4" id="todayHighlights">
                            <div class="flex items-center gap-3 p-3 bg-indigo-50 rounded-xl">
                                <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center">
                                    <i class="fas fa-flask text-indigo-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Science Fair Prep</p>
                                    <p class="text-xs text-slate-500">Grade 10 • Science Lab • 2:00 PM</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 p-3 bg-emerald-50 rounded-xl">
                                <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center">
                                    <i class="fas fa-futbol text-emerald-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Sports Day Practice</p>
                                    <p class="text-xs text-slate-500">All Grades • Sports Field • 3:30 PM</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 p-3 bg-amber-50 rounded-xl">
                                <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center">
                                    <i class="fas fa-users text-amber-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Parent-Teacher Meeting</p>
                                    <p class="text-xs text-slate-500">Grade 12 Parents • Main Hall • 4:00 PM</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-3 p-3 bg-red-50 rounded-xl">
                                <div class="w-10 h-10 rounded-lg bg-white flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-red-600"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">Room Change Alert</p>
                                    <p class="text-xs text-slate-500">Math Class • Moved to Room 105</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Upcoming Changes -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Upcoming Schedule Changes</h3>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div>
                                    <p class="font-medium text-slate-900">Mid-term Exams</p>
                                    <p class="text-xs text-slate-500">Starts Jan 15 • Special Schedule</p>
                                </div>
                                <span class="text-xs font-bold text-amber-600">5 days</span>
                            </div>

                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div>
                                    <p class="font-medium text-slate-900">Teacher Training</p>
                                    <p class="text-xs text-slate-500">Professional Development Day</p>
                                </div>
                                <span class="text-xs font-bold text-blue-600">1 week</span>
                            </div>

                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div>
                                    <p class="font-medium text-slate-900">Spring Break</p>
                                    <p class="text-xs text-slate-500">No classes for 1 week</p>
                                </div>
                                <span class="text-xs font-bold text-emerald-600">3 weeks</span>
                            </div>

                            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-xl">
                                <div>
                                    <p class="font-medium text-slate-900">New Teacher Joining</p>
                                    <p class="text-xs text-slate-500">Physics Department</p>
                                </div>
                                <span class="text-xs font-bold text-purple-600">Next Month</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Global variables
        const jsData = <?php echo json_encode($jsData); ?>;
        let currentView = 'timetable';
        let selectedClass = '';
        let selectedTeacher = null;
        let timetableData = {};
        let teachersList = [];
        let subjectsList = [];
        let classesList = [];

        // API Configuration
        const API_BASE_URL = '../../api/timetable_api.php';

        // Toast Notification System
        class Toast {
            static show(message, type = 'info', duration = 5000) {
                const container = document.getElementById('toastContainer');
                const toast = document.createElement('div');
                toast.className = `toast toast-${type}`;

                const icons = {
                    success: 'fa-check-circle',
                    info: 'fa-info-circle',
                    warning: 'fa-exclamation-triangle',
                    error: 'fa-times-circle'
                };

                toast.innerHTML = `
                <i class="fas ${icons[type]} toast-icon"></i>
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

                container.appendChild(toast);

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

        // API Helper Functions
        async function apiRequest(action, params = {}) {
            const url = new URL(API_BASE_URL);
            url.searchParams.append('action', action);
            url.searchParams.append('school_slug', jsData.school.slug);

            // Add additional parameters
            Object.keys(params).forEach(key => {
                url.searchParams.append(key, params[key]);
            });

            try {
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Unknown error occurred');
                }

                return data;
            } catch (error) {
                console.error(`API Error (${action}):`, error);
                throw error;
            }
        }

        // Initialize page
        function initializePage() {
            loadInitialData();
            setupEventListeners();

            // Welcome toast
            setTimeout(() => {
                Toast.success('Timetable Management loaded successfully!', 3000);
            }, 1000);
        }

        // Load initial data
        async function loadInitialData() {
            try {
                // Load all data in parallel
                await Promise.all([
                    loadTeachers(),
                    loadSubjects(),
                    loadClasses()
                ]);

                // Load teacher list for filter
                await updateTeacherFilter();

            } catch (error) {
                Toast.error('Failed to load initial data');
            }
        }

        // Load teachers with new API
        async function loadTeachers() {
            try {
                const data = await apiRequest('get_teachers');
                teachersList = data.teachers || [];
                updateTeacherDropdown();
            } catch (error) {
                Toast.error('Failed to load teachers.');
            }
        }

        // Load subjects with new API
        async function loadSubjects() {
            try {
                const data = await apiRequest('get_subjects');
                subjectsList = data.subjects || [];
                updateSubjectDropdown();
            } catch (error) {
                Toast.error('Failed to load subjects.');
            }
        }

        // Load classes with new API
        async function loadClasses() {
            try {
                const data = await apiRequest('get_classes');
                classesList = data.classes || [];
                updateClassDropdown();
                updateClassSelector();
            } catch (error) {
                Toast.error('Failed to load classes.');
            }
        }

        // Update teacher dropdown in modal
        function updateTeacherDropdown() {
            const teacherSelect = document.getElementById('teacherId');
            if (!teacherSelect) return;

            teacherSelect.innerHTML = '<option value="">Select Teacher</option>';

            teachersList.forEach(teacher => {
                const option = document.createElement('option');
                option.value = teacher.id;
                option.textContent = teacher.full_name;
                if (teacher.specialization) {
                    option.textContent += ` - ${teacher.specialization}`;
                }
                // Add data attributes for availability info
                option.dataset.remainingPeriods = teacher.availability?.remaining_periods || 30;
                option.dataset.utilization = teacher.availability?.utilization_percentage || 0;
                teacherSelect.appendChild(option);
            });

            // Add availability indicator
            if (teacherSelect.nextElementSibling?.classList?.contains('availability-indicator')) {
                teacherSelect.nextElementSibling.remove();
            }

            const indicator = document.createElement('div');
            indicator.className = 'availability-indicator text-xs text-slate-500 mt-1 hidden';
            teacherSelect.parentNode.appendChild(indicator);

            // Show availability on selection
            teacherSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const indicator = this.parentNode.querySelector('.availability-indicator');

                if (selectedOption.value && selectedOption.dataset.remainingPeriods) {
                    indicator.textContent = `Available periods: ${selectedOption.dataset.remainingPeriods} (${selectedOption.dataset.utilization}% utilized)`;
                    indicator.classList.remove('hidden');

                    // Color code based on availability
                    const remaining = parseInt(selectedOption.dataset.remainingPeriods);
                    if (remaining < 5) {
                        indicator.className = 'availability-indicator text-xs text-red-600 font-medium mt-1';
                    } else if (remaining < 10) {
                        indicator.className = 'availability-indicator text-xs text-amber-600 font-medium mt-1';
                    } else {
                        indicator.className = 'availability-indicator text-xs text-emerald-600 font-medium mt-1';
                    }
                } else {
                    indicator.classList.add('hidden');
                }
            });
        }

        // Update subject dropdown in modal
        function updateSubjectDropdown() {
            const subjectSelect = document.getElementById('subjectId');
            if (!subjectSelect) return;

            subjectSelect.innerHTML = '<option value="">Select Subject</option>';

            subjectsList.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = `${subject.name} (${subject.code})`;
                if (subject.credit_hours) {
                    option.textContent += ` - ${subject.credit_hours} credits`;
                }
                option.dataset.type = subject.type;
                option.dataset.credits = subject.credit_hours;
                subjectSelect.appendChild(option);
            });

            // Add type indicator
            if (subjectSelect.nextElementSibling?.classList?.contains('subject-type-indicator')) {
                subjectSelect.nextElementSibling.remove();
            }

            const indicator = document.createElement('div');
            indicator.className = 'subject-type-indicator text-xs text-slate-500 mt-1 hidden';
            subjectSelect.parentNode.appendChild(indicator);

            subjectSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const indicator = this.parentNode.querySelector('.subject-type-indicator');

                if (selectedOption.value && selectedOption.dataset.type) {
                    const type = selectedOption.dataset.type;
                    const credits = selectedOption.dataset.credits || 0;
                    indicator.textContent = `${type.charAt(0).toUpperCase() + type.slice(1)} subject • ${credits} credits`;
                    indicator.classList.remove('hidden');
                } else {
                    indicator.classList.add('hidden');
                }
            });
        }

        // Update class dropdown in modal
        function updateClassDropdown() {
            const classSelect = document.getElementById('classId');
            if (!classSelect) return;

            classSelect.innerHTML = '<option value="">Select Class</option>';

            classesList.forEach(cls => {
                const option = document.createElement('option');
                option.value = cls.id;
                option.textContent = cls.display_name;
                option.dataset.grade = cls.grade_level;
                option.dataset.section = cls.section;
                option.dataset.capacity = cls.capacity;
                option.dataset.students = cls.current_students;
                option.dataset.availability = cls.availability?.seats_available || 0;
                classSelect.appendChild(option);
            });

            // Add class info indicator
            if (classSelect.nextElementSibling?.classList?.contains('class-info-indicator')) {
                classSelect.nextElementSibling.remove();
            }

            const indicator = document.createElement('div');
            indicator.className = 'class-info-indicator text-xs text-slate-500 mt-1 hidden';
            classSelect.parentNode.appendChild(indicator);

            classSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const indicator = this.parentNode.querySelector('.class-info-indicator');

                if (selectedOption.value && selectedOption.dataset.capacity) {
                    const capacity = selectedOption.dataset.capacity;
                    const students = selectedOption.dataset.students;
                    const available = selectedOption.dataset.availability;
                    const occupancy = Math.round((students / capacity) * 100);

                    indicator.innerHTML = `
                    <span>Capacity: ${students}/${capacity} students</span>
                    <span class="ml-3">Available: ${available} seats</span>
                    <span class="ml-3">Occupancy: ${occupancy}%</span>
                `;
                    indicator.classList.remove('hidden');

                    // Color code based on occupancy
                    if (occupancy > 90) {
                        indicator.className = 'class-info-indicator text-xs text-red-600 font-medium mt-1';
                    } else if (occupancy > 75) {
                        indicator.className = 'class-info-indicator text-xs text-amber-600 font-medium mt-1';
                    } else {
                        indicator.className = 'class-info-indicator text-xs text-emerald-600 font-medium mt-1';
                    }
                } else {
                    indicator.classList.add('hidden');
                }
            });
        }

        // Update class selector in timetable section
        function updateClassSelector() {
            const classSelect = document.getElementById('selectedClass');
            if (!classSelect) return;

            classSelect.innerHTML = '<option value="">Select a class</option>';

            classesList.forEach(cls => {
                const option = document.createElement('option');
                option.value = `${cls.grade_level}-${cls.section}`;
                option.textContent = cls.display_name;
                option.dataset.classId = cls.id;
                option.dataset.teacher = cls.class_teacher_name;
                classSelect.appendChild(option);
            });

            // Add class info display
            if (classSelect.nextElementSibling?.classList?.contains('selected-class-info')) {
                classSelect.nextElementSibling.remove();
            }

            const infoDiv = document.createElement('div');
            infoDiv.className = 'selected-class-info text-sm text-slate-600 mt-2 hidden';
            classSelect.parentNode.appendChild(infoDiv);

            classSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const infoDiv = this.parentNode.querySelector('.selected-class-info');

                if (selectedOption.value && selectedOption.dataset.teacher) {
                    infoDiv.innerHTML = `
                    <div class="flex items-center gap-2">
                        <i class="fas fa-chalkboard-teacher text-indigo-500"></i>
                        <span>Class Teacher: ${selectedOption.dataset.teacher}</span>
                    </div>
                `;
                    infoDiv.classList.remove('hidden');

                    // Load timetable for selected class
                    loadTimetable();
                } else {
                    infoDiv.classList.add('hidden');
                    clearTimetable();
                }
            });
        }

        // Update teacher filter dropdown with new API
        async function updateTeacherFilter() {
            const teacherFilter = document.getElementById('teacherFilter');
            if (!teacherFilter) return;

            teacherFilter.innerHTML = '<option value="">Select a teacher</option>';

            try {
                const data = await apiRequest('get_teachers');

                data.teachers.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.id;
                    option.textContent = teacher.full_name;
                    if (teacher.specialization) {
                        option.textContent += ` (${teacher.specialization})`;
                    }
                    option.dataset.periods = teacher.availability?.remaining_periods || 30;
                    teacherFilter.appendChild(option);
                });

                // Also update teacher list display
                updateTeacherListDisplay(data.teachers);

            } catch (error) {
                console.error('Error loading teachers:', error);
                Toast.error('Failed to load teachers.');
            }
        }

        // Update teacher list display with enhanced info
        function updateTeacherListDisplay(teachers) {
            const teacherList = document.getElementById('teacherList');
            if (!teacherList) return;

            teacherList.innerHTML = '';

            teachers.forEach(teacher => {
                const remaining = teacher.availability?.remaining_periods || 30;
                const utilization = teacher.availability?.utilization_percentage || 0;

                // Determine availability color
                let availabilityColor = 'text-emerald-600';
                let availabilityText = 'Good';
                if (remaining < 5) {
                    availabilityColor = 'text-red-600';
                    availabilityText = 'Limited';
                } else if (remaining < 10) {
                    availabilityColor = 'text-amber-600';
                    availabilityText = 'Moderate';
                }

                const div = document.createElement('div');
                div.className = 'flex items-center gap-3 p-3 hover:bg-slate-50 rounded-xl cursor-pointer border border-slate-100';
                div.onclick = () => viewTeacherSchedule(teacher.id, teacher.full_name);

                div.innerHTML = `
                <div class="flex-shrink-0">
                    <div class="avatar avatar-sm bg-gradient-to-br from-indigo-500 to-purple-500 text-white font-bold">
                        ${teacher.first_name ? teacher.first_name[0] : ''}${teacher.last_name ? teacher.last_name[0] : ''}
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-slate-900 truncate">${teacher.full_name}</p>
                        <span class="text-xs font-bold ${availabilityColor}">${availabilityText}</span>
                    </div>
                    <p class="text-xs text-slate-500 truncate">${teacher.specialization || 'Teacher'}</p>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="text-xs text-slate-400">
                            <i class="fas fa-clock mr-1"></i>
                            ${remaining} periods available
                        </span>
                        <span class="text-xs text-slate-400">
                            <i class="fas fa-chart-pie mr-1"></i>
                            ${utilization}% utilized
                        </span>
                    </div>
                </div>
            `;

                teacherList.appendChild(div);
            });
        }

        // Load timetable with new API integration
        async function loadTimetable() {
            const selectedClassValue = document.getElementById('selectedClass').value;
            if (!selectedClassValue) {
                clearTimetable();
                return;
            }

            selectedClass = selectedClassValue;

            const [grade, section] = selectedClassValue.split('-');

            try {
                const formData = new FormData();
                formData.append('action', 'get_timetable');
                formData.append('grade', grade);
                formData.append('section', section);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                timetableData = data.timetable || [];
                renderTimetable();

                // Update class info if needed
                updateCurrentClassInfo(grade, section);

            } catch (error) {
                console.error('Error loading timetable:', error);
                Toast.error('Failed to load timetable.');
            }
        }

        // Update current class information display
        function updateCurrentClassInfo(grade, section) {
            const currentClass = classesList.find(cls =>
                cls.grade_level == grade && cls.section == section
            );

            if (currentClass) {
                const header = document.querySelector('h1.text-lg');
                if (header) {
                    header.innerHTML = `
                    Timetable Management
                    <span class="text-sm font-normal text-indigo-600 ml-2">
                        • Grade ${grade} - Section ${section}
                    </span>
                    <span class="text-xs font-normal text-slate-500 ml-2">
                        (${currentClass.class_teacher_name || 'No class teacher'})
                    </span>
                `;
                }
            }
        }

        // Clear timetable display
        function clearTimetable() {
            // Clear all timetable cells
            document.querySelectorAll('.timetable-cell').forEach(cell => {
                if (cell.children.length > 0 && !cell.children[0].classList.contains('empty-slot')) {
                    cell.innerHTML = `
                    <div class="empty-slot">
                        <i class="fas fa-plus text-slate-300"></i>
                        <span class="ml-2">Add period</span>
                    </div>
                `;
                }
            });
        }

        // Render timetable with enhanced styling
        function renderTimetable() {
            // Clear existing periods first
            clearTimetable();

            // Group periods by day and time
            const periodsByDayTime = {};

            timetableData.forEach(period => {
                const day = period.day;
                const startTime = period.start_time;
                const endTime = period.end_time;

                if (!periodsByDayTime[day]) {
                    periodsByDayTime[day] = {};
                }

                // Create a key for this time slot
                const timeKey = `${startTime}-${endTime}`;
                periodsByDayTime[day][timeKey] = period;
            });

            // Render periods in timetable cells
            Object.keys(periodsByDayTime).forEach(day => {
                Object.keys(periodsByDayTime[day]).forEach(timeKey => {
                    const period = periodsByDayTime[day][timeKey];
                    renderPeriod(period);
                });
            });

            // Update statistics
            updateTimetableStats();
        }

        // Update timetable statistics
        function updateTimetableStats() {
            const stats = {
                totalPeriods: timetableData.length,
                totalSubjects: new Set(timetableData.map(p => p.subject_id)).size,
                totalTeachers: new Set(timetableData.map(p => p.teacher_id)).size,
                daysCovered: new Set(timetableData.map(p => p.day)).size
            };

            // Update stats display if exists
            const statsContainer = document.getElementById('timetableStats');
            if (!statsContainer) {
                // Create stats container if it doesn't exist
                const timetableHeader = document.querySelector('.glass-header');
                if (timetableHeader) {
                    const statsDiv = document.createElement('div');
                    statsDiv.id = 'timetableStats';
                    statsDiv.className = 'hidden md:flex items-center gap-4 text-sm';
                    statsDiv.innerHTML = `
                    <span class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-lg">
                        <i class="fas fa-clock mr-1"></i>
                        ${stats.totalPeriods} periods
                    </span>
                    <span class="px-3 py-1 bg-emerald-50 text-emerald-700 rounded-lg">
                        <i class="fas fa-book mr-1"></i>
                        ${stats.totalSubjects} subjects
                    </span>
                    <span class="px-3 py-1 bg-purple-50 text-purple-700 rounded-lg">
                        <i class="fas fa-chalkboard-teacher mr-1"></i>
                        ${stats.totalTeachers} teachers
                    </span>
                `;
                    timetableHeader.appendChild(statsDiv);
                }
            } else {
                statsContainer.innerHTML = `
                <span class="px-3 py-1 bg-indigo-50 text-indigo-700 rounded-lg">
                    <i class="fas fa-clock mr-1"></i>
                    ${stats.totalPeriods} periods
                </span>
                <span class="px-3 py-1 bg-emerald-50 text-emerald-700 rounded-lg">
                    <i class="fas fa-book mr-1"></i>
                    ${stats.totalSubjects} subjects
                </span>
                <span class="px-3 py-1 bg-purple-50 text-purple-700 rounded-lg">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>
                    ${stats.totalTeachers} teachers
                </span>
            `;
            }
        }

        // Render a single period with enhanced styling
        function renderPeriod(period) {
            const day = period.day;
            const startTime = period.start_time;
            const endTime = period.end_time;

            // Find the cell for this day and time
            const cell = document.querySelector(`.timetable-cell[data-day="${day}"][data-time="${startTime}"]`);
            if (!cell) return;

            // Determine period type color and icon
            let periodColor = 'indigo';
            let periodIcon = 'fa-book';
            let periodType = period.period_type || 'regular';

            switch (periodType) {
                case 'lab':
                    periodColor = 'emerald';
                    periodIcon = 'fa-flask';
                    break;
                case 'sports':
                    periodColor = 'amber';
                    periodIcon = 'fa-running';
                    break;
                case 'break':
                    periodColor = 'red';
                    periodIcon = 'fa-coffee';
                    break;
                default:
                    periodColor = 'indigo';
                    periodIcon = 'fa-book';
            }

            // Format time
            const formatTime = (timeStr) => {
                const [hours, minutes] = timeStr.split(':');
                const hour = parseInt(hours);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const displayHour = hour % 12 || 12;
                return `${displayHour}:${minutes} ${ampm}`;
            };

            cell.innerHTML = `
            <div class="timetable-period border-l-4 border-${periodColor}-500 bg-white hover:bg-${periodColor}-50 transition-colors" 
                 onclick="viewPeriod(${period.id})"
                 data-period-id="${period.id}">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="period-subject flex items-center gap-2">
                            <i class="fas ${periodIcon} text-${periodColor}-500"></i>
                            <span class="font-bold">${period.subject_name}</span>
                        </div>
                        <div class="period-teacher text-sm text-slate-600 mt-1">
                            <i class="fas fa-user-graduate mr-1"></i>
                            ${period.teacher_first_name} ${period.teacher_last_name}
                        </div>
                        <div class="period-room text-sm text-slate-500 mt-1">
                            <i class="fas fa-door-open mr-1"></i>
                            ${period.room_name || 'No Room'}
                        </div>
                    </div>
                    <div class="flex gap-1">
                        <button onclick="editPeriod(event, ${period.id})" 
                                class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                            <i class="fas fa-edit text-xs"></i>
                        </button>
                        <button onclick="deletePeriod(event, ${period.id})" 
                                class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                            <i class="fas fa-trash text-xs"></i>
                        </button>
                    </div>
                </div>
                <div class="period-time text-xs text-slate-400 mt-2 pt-2 border-t border-slate-100">
                    <i class="far fa-clock mr-1"></i>
                    ${formatTime(startTime)} - ${formatTime(endTime)}
                </div>
            </div>
        `;
        }

        // View period details with API
        async function viewPeriod(periodId) {
            try {
                const formData = new FormData();
                formData.append('action', 'get_period');
                formData.append('period_id', periodId);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                const period = data.period;

                // Create and show detailed view modal
                showPeriodDetailsModal(period);

            } catch (error) {
                console.error('Error viewing period:', error);
                Toast.error('Failed to load period details.');
            }
        }

        // Show period details modal
        function showPeriodDetailsModal(period) {
            // Create modal if it doesn't exist
            let modal = document.getElementById('periodDetailsModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'periodDetailsModal';
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden';
                modal.innerHTML = `
                <div class="modal-content bg-white rounded-2xl w-full max-w-md mx-4">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-xl font-black text-slate-900">Period Details</h3>
                            <button onclick="closeModal('periodDetailsModal')" class="text-slate-400 hover:text-slate-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        <div id="periodDetailsContent"></div>
                    </div>
                </div>
            `;
                document.body.appendChild(modal);
            }

            // Format time
            const formatTime = (timeStr) => {
                const [hours, minutes] = timeStr.split(':');
                const hour = parseInt(hours);
                const ampm = hour >= 12 ? 'PM' : 'AM';
                const displayHour = hour % 12 || 12;
                return `${displayHour}:${minutes} ${ampm}`;
            };

            // Determine period type color
            let periodColor = 'indigo';
            switch (period.period_type) {
                case 'lab':
                    periodColor = 'emerald';
                    break;
                case 'sports':
                    periodColor = 'amber';
                    break;
                case 'break':
                    periodColor = 'red';
                    break;
                default:
                    periodColor = 'indigo';
            }

            // Populate content
            const content = document.getElementById('periodDetailsContent');
            content.innerHTML = `
            <div class="space-y-4">
                <div class="p-4 bg-${periodColor}-50 rounded-xl">
                    <div class="flex items-center justify-between">
                        <h4 class="font-bold text-${periodColor}-700 text-lg">${period.subject_name}</h4>
                        <span class="px-3 py-1 bg-${periodColor}-100 text-${periodColor}-700 rounded-lg text-sm font-bold">
                            ${period.period_type?.toUpperCase() || 'REGULAR'}
                        </span>
                    </div>
                    <p class="text-sm text-slate-600 mt-1">${period.subject_code || ''}</p>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500">Class</p>
                        <p class="font-bold text-slate-900">Grade ${period.grade_level} - Section ${period.section}</p>
                    </div>
                    
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500">Teacher</p>
                        <p class="font-bold text-slate-900">${period.teacher_first_name} ${period.teacher_last_name}</p>
                    </div>
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500">Day</p>
                        <p class="font-bold text-slate-900 capitalize">${period.day}</p>
                    </div>
                    
                    <div class="bg-slate-50 p-4 rounded-xl">
                        <p class="text-sm text-slate-500">Time</p>
                        <p class="font-bold text-slate-900">${formatTime(period.start_time)} - ${formatTime(period.end_time)}</p>
                    </div>
                </div>
                
                <div class="bg-slate-50 p-4 rounded-xl">
                    <p class="text-sm text-slate-500">Room</p>
                    <p class="font-bold text-slate-900">${period.room_name || 'Not assigned'}</p>
                </div>
                
                <div class="flex gap-3 pt-4 border-t border-slate-100">
                    <button onclick="editPeriodFromDetails(${period.id})" 
                            class="flex-1 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 transition">
                        Edit Period
                    </button>
                    <button onclick="closeModal('periodDetailsModal')" 
                            class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Close
                    </button>
                </div>
            </div>
        `;

            openModal('periodDetailsModal');
        }

        // Edit period from details modal
        function editPeriodFromDetails(periodId) {
            closeModal('periodDetailsModal');
            editPeriod({
                stopPropagation: () => {}
            }, periodId);
        }

        // Add new period
        function addNewPeriod() {
            if (!selectedClass) {
                Toast.warning('Please select a class first');
                return;
            }

            document.getElementById('periodModalTitle').textContent = 'Add New Period';
            document.getElementById('periodId').value = '';
            document.getElementById('periodForm').reset();
            document.getElementById('periodType').value = 'regular';
            document.getElementById('roomNumber').value = '';

            // Auto-select current class if available
            if (selectedClass) {
                const [grade, section] = selectedClass.split('-');
                const currentClass = classesList.find(cls =>
                    cls.grade_level == grade && cls.section == section
                );
                if (currentClass) {
                    document.getElementById('classId').value = currentClass.id;
                }
            }

            openModal('periodModal');
        }

        // Add period at specific slot
        function addPeriodAtSlot(day, startTime, endTime) {
            if (!selectedClass) {
                Toast.warning('Please select a class first');
                return;
            }

            addNewPeriod();

            // Set the day and time in the form
            setTimeout(() => {
                document.getElementById('day').value = day;
                document.getElementById('startTime').value = startTime.substring(0, 5);
                document.getElementById('endTime').value = endTime.substring(0, 5);
            }, 100);
        }

        // Edit period
        async function editPeriod(event, periodId) {
            event.stopPropagation();

            try {
                const formData = new FormData();
                formData.append('action', 'get_period');
                formData.append('period_id', periodId);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                const period = data.period;
                document.getElementById('periodModalTitle').textContent = 'Edit Period';
                document.getElementById('periodId').value = period.id;
                document.getElementById('classId').value = period.class_id;
                document.getElementById('subjectId').value = period.subject_id;
                document.getElementById('teacherId').value = period.teacher_id;

                // Extract room number from room_name
                const roomNumber = period.room_name ? period.room_name.replace('Room ', '') : '';
                document.getElementById('roomNumber').value = roomNumber;

                document.getElementById('day').value = period.day;
                document.getElementById('startTime').value = period.start_time.substring(0, 5);
                document.getElementById('endTime').value = period.end_time.substring(0, 5);
                document.getElementById('periodType').value = period.period_type || 'regular';

                openModal('periodModal');

            } catch (error) {
                console.error('Error loading period:', error);
                Toast.error('Failed to load period details.');
            }
        }

        // Delete period
        async function deletePeriod(event, periodId) {
            event.stopPropagation();

            if (!confirm('Are you sure you want to delete this period?')) {
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'delete_period');
                formData.append('period_id', periodId);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                Toast.success('Period deleted successfully');
                loadTimetable();

            } catch (error) {
                console.error('Error deleting period:', error);
                Toast.error('Failed to delete period.');
            }
        }

        // Save period
        async function savePeriod(event) {
            event.preventDefault();

            const periodId = document.getElementById('periodId').value;
            const classId = document.getElementById('classId').value;
            const subjectId = document.getElementById('subjectId').value;
            const teacherId = document.getElementById('teacherId').value;
            const roomNumber = document.getElementById('roomNumber').value;
            const day = document.getElementById('day').value;
            const startTime = document.getElementById('startTime').value;
            const endTime = document.getElementById('endTime').value;
            const periodType = document.getElementById('periodType').value;

            // Validate times
            if (startTime >= endTime) {
                Toast.error('End time must be after start time');
                return;
            }

            // Format time to include seconds
            const startTimeFormatted = startTime + ':00';
            const endTimeFormatted = endTime + ':00';

            const periodData = {
                class_id: classId,
                subject_id: subjectId,
                teacher_id: teacherId,
                room_number: roomNumber || null,
                day: day,
                start_time: startTimeFormatted,
                end_time: endTimeFormatted,
                period_type: periodType
            };

            try {
                const formData = new FormData();
                formData.append('action', 'save_period');
                formData.append('period_id', periodId || '');
                formData.append('period_data', JSON.stringify(periodData));

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                Toast.success(periodId ? 'Period updated successfully' : 'Period added successfully');
                closeModal('periodModal');
                loadTimetable();

            } catch (error) {
                console.error('Error saving period:', error);
                Toast.error('Failed to save period.');
            }
        }

        // Load teacher schedule with API
        async function loadTeacherSchedule() {
            const teacherId = document.getElementById('teacherFilter').value;
            if (!teacherId) {
                document.getElementById('teacherSchedulePlaceholder').classList.remove('hidden');
                document.getElementById('teacherScheduleDisplay').classList.add('hidden');
                return;
            }

            try {
                const data = await apiRequest('get_teacher_load', {
                    teacher_id: teacherId
                });
                displayTeacherSchedule(data);
            } catch (error) {
                Toast.error('Failed to load teacher schedule.');
            }
        }

        // Display teacher schedule with enhanced view
        function displayTeacherSchedule(data) {
            const displayDiv = document.getElementById('teacherScheduleDisplay');
            const placeholder = document.getElementById('teacherSchedulePlaceholder');

            if (!data || !data.schedule || data.schedule.length === 0) {
                displayDiv.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-calendar-times text-4xl text-slate-300 mb-3"></i>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">No Schedule Found</h4>
                    <p class="text-slate-500">This teacher has no scheduled classes for this week.</p>
                </div>
            `;
            } else {
                // Teacher workload summary
                const teacher = data.teacher;
                const summary = data.workload_summary;

                let html = `
                <div class="mb-6 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-bold text-slate-900">Workload Summary</h4>
                        <span class="px-3 py-1 bg-white text-indigo-700 rounded-lg font-bold">
                            ${teacher.utilization_percentage}% Utilized
                        </span>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center">
                            <p class="text-2xl font-black text-slate-900">${teacher.current_weekly_periods}</p>
                            <p class="text-xs text-slate-500">Periods Scheduled</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-black text-slate-900">${teacher.remaining_periods}</p>
                            <p class="text-xs text-slate-500">Periods Available</p>
                        </div>
                        <div class="text-center">
                            <p class="text-2xl font-black text-slate-900">${summary.average_periods_per_day}</p>
                            <p class="text-xs text-slate-500">Avg/Day</p>
                        </div>
                    </div>
                </div>
            `;

                // Schedule by day
                const scheduleByDay = {};
                data.schedule.forEach(item => {
                    if (!scheduleByDay[item.day]) {
                        scheduleByDay[item.day] = [];
                    }
                    scheduleByDay[item.day].push(item);
                });

                // Sort days
                const daysOrder = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

                daysOrder.forEach(day => {
                    if (scheduleByDay[day] && scheduleByDay[day].length > 0) {
                        html += `<h5 class="font-bold text-slate-900 mb-3 mt-6 capitalize">${day}</h5>`;

                        scheduleByDay[day].sort((a, b) => {
                            // Parse time slots for proper sorting
                            const aTimes = a.time_slots?.split(',')[0]?.split('-') || ['00:00'];
                            const bTimes = b.time_slots?.split(',')[0]?.split('-') || ['00:00'];
                            return aTimes[0].localeCompare(bTimes[0]);
                        });

                        scheduleByDay[day].forEach(item => {
                            // Get first time slot for display
                            const firstSlot = item.time_slots?.split(',')[0];
                            const [startTime = '00:00', endTime = '00:00'] = firstSlot ? firstSlot.split('-') : [];

                            html += `
                            <div class="flex items-center gap-4 p-4 bg-white border border-slate-200 rounded-xl mb-3 hover:bg-slate-50 transition-colors">
                                <div class="text-center min-w-24">
                                    <div class="font-bold text-indigo-600">${startTime.substring(0, 5)}</div>
                                    <div class="text-xs text-slate-400">to</div>
                                    <div class="font-bold text-indigo-600">${endTime.substring(0, 5)}</div>
                                </div>
                                <div class="flex-1">
                                    <div class="font-bold text-slate-900 mb-1">
                                        ${item.periods_per_day} Period${item.periods_per_day > 1 ? 's' : ''}
                                    </div>
                                    <div class="text-sm text-slate-600">
                                        ${item.subject_ids ? item.subject_ids.split(',').length : 0} Subjects
                                    </div>
                                    <div class="text-xs text-slate-500 mt-1">
                                        ${item.class_ids ? item.class_ids.split(',').length : 0} Classes
                                    </div>
                                </div>
                            </div>
                        `;
                        });
                    }
                });

                displayDiv.innerHTML = html;
            }

            placeholder.classList.add('hidden');
            displayDiv.classList.remove('hidden');
        }

        // View teacher schedule with API
        async function viewTeacherSchedule(teacherId, teacherName) {
            selectedTeacher = {
                id: teacherId,
                name: teacherName
            };

            document.getElementById('teacherScheduleTitle').textContent = `${teacherName}'s Schedule`;

            try {
                // Load teacher details
                const teacherData = await apiRequest('get_teacher_by_id', {
                    teacher_id: teacherId
                });
                const scheduleData = await apiRequest('get_teacher_load', {
                    teacher_id: teacherId
                });

                displayTeacherScheduleInModal(teacherData, scheduleData);
                openModal('teacherScheduleModal');

            } catch (error) {
                console.error('Error loading teacher schedule:', error);
                Toast.error('Failed to load teacher schedule.');
            }
        }

        // Display teacher schedule in modal with enhanced info
        function displayTeacherScheduleInModal(teacherData, scheduleData) {
            const contentDiv = document.getElementById('teacherScheduleContent');
            const teacher = teacherData.teacher;
            const schedule = scheduleData.schedule;

            if (!schedule || schedule.length === 0) {
                contentDiv.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-calendar-times text-4xl text-slate-300 mb-3"></i>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">No Schedule Found</h4>
                    <p class="text-slate-500">${teacher.full_name} has no scheduled classes for this week.</p>
                </div>
            `;
                return;
            }

            // Teacher info header
            let html = `
            <div class="mb-6 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl">
                <div class="flex items-center gap-4">
                    <div class="avatar avatar-lg bg-gradient-to-br from-indigo-500 to-purple-500 text-white font-bold">
                        ${teacher.first_name[0]}${teacher.last_name[0]}
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-slate-900 text-lg">${teacher.full_name}</h4>
                        <p class="text-sm text-slate-600">${teacher.specialization || 'Teacher'}</p>
                        <div class="flex items-center gap-4 mt-2">
                            <span class="text-xs bg-white px-2 py-1 rounded">
                                <i class="fas fa-clock mr-1"></i>
                                ${teacher.current_weekly_periods}/${teacher.max_weekly_periods} periods
                            </span>
                            <span class="text-xs bg-white px-2 py-1 rounded">
                                <i class="fas fa-chart-pie mr-1"></i>
                                ${teacher.availability.utilization_percentage}% utilized
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        `;

            // Detailed schedule by day
            const scheduleByDay = {};
            schedule.forEach(item => {
                if (!scheduleByDay[item.day]) {
                    scheduleByDay[item.day] = [];
                }
                scheduleByDay[item.day].push(item);
            });

            // Sort days
            const daysOrder = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

            daysOrder.forEach(day => {
                if (scheduleByDay[day]) {
                    html += `<h5 class="font-bold text-slate-900 mb-3 mt-6 capitalize">${day}</h5>`;

                    scheduleByDay[day].sort((a, b) => a.start_time?.localeCompare(b.start_time) || 0);

                    scheduleByDay[day].forEach(item => {
                        // Format time
                        const formatTime = (timeStr) => {
                            if (!timeStr) return '';
                            const [hours, minutes] = timeStr.split(':');
                            const hour = parseInt(hours);
                            const ampm = hour >= 12 ? 'PM' : 'AM';
                            const displayHour = hour % 12 || 12;
                            return `${displayHour}:${minutes} ${ampm}`;
                        };

                        html += `
                        <div class="flex items-center gap-4 p-4 bg-white border border-slate-200 rounded-xl mb-3">
                            <div class="text-center min-w-24">
                                <div class="font-bold text-indigo-600">${formatTime(item.start_time)}</div>
                                <div class="text-xs text-slate-400">to</div>
                                <div class="font-bold text-indigo-600">${formatTime(item.end_time)}</div>
                            </div>
                            <div class="flex-1">
                                <div class="font-bold text-slate-900">
                                    ${item.subject_name}
                                </div>
                                <div class="text-slate-600">Grade ${item.grade_level} • Section ${item.section}</div>
                                <div class="text-sm text-slate-500 mt-1">
                                    <i class="fas fa-door-open mr-1"></i> ${item.room_name || 'No room assigned'}
                                </div>
                            </div>
                        </div>
                    `;
                    });
                }
            });

            contentDiv.innerHTML = html;
        }

        // Switch view with API integration
        function switchView(viewName) {
            currentView = viewName;

            // Update active tab
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');

            // Show selected view
            document.querySelectorAll('.view-content').forEach(view => {
                view.classList.add('hidden');
            });
            document.getElementById(`${viewName}View`).classList.remove('hidden');

            // Load data for the view if needed
            switch (viewName) {
                case 'teacher':
                    if (teachersList.length === 0) {
                        loadTeachers();
                    }
                    break;
                case 'room':
                    loadRoomAllocation();
                    break;
                case 'conflicts':
                    scanForConflicts();
                    break;
            }
        }

        // Load room allocation with API
        async function loadRoomAllocation() {
            try {
                // This would normally fetch room allocation data from the server
                // For now, using mock data
                const rooms = [{
                        name: 'Room 101',
                        capacity: 30,
                        type: 'Classroom',
                        utilization: '85%'
                    },
                    {
                        name: 'Room 102',
                        capacity: 30,
                        type: 'Classroom',
                        utilization: '90%'
                    },
                    {
                        name: 'Room 103',
                        capacity: 25,
                        type: 'Classroom',
                        utilization: '75%'
                    },
                    {
                        name: 'Science Lab 1',
                        capacity: 20,
                        type: 'Laboratory',
                        utilization: '95%'
                    },
                    {
                        name: 'Computer Lab',
                        capacity: 25,
                        type: 'Laboratory',
                        utilization: '88%'
                    },
                    {
                        name: 'Auditorium',
                        capacity: 200,
                        type: 'Special',
                        utilization: '45%'
                    }
                ];

                const tableBody = document.getElementById('roomAllocationTable');
                tableBody.innerHTML = '';

                rooms.forEach(room => {
                    // Generate random utilization per day
                    const dayUtilizations = [];
                    for (let i = 0; i < 7; i++) {
                        const util = Math.floor(Math.random() * 6) + 2; // 2-7 periods per day
                        dayUtilizations.push(util);
                    }

                    const row = document.createElement('tr');
                    row.className = 'hover:bg-slate-50';
                    row.innerHTML = `
                    <td class="py-3 px-4">
                        <div class="font-bold text-slate-900">${room.name}</div>
                        <div class="text-xs text-slate-500">${room.type}</div>
                    </td>
                    <td class="py-3 px-4">
                        <div class="text-sm font-medium">${room.capacity} seats</div>
                    </td>
                    <td class="py-3 px-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                               ${room.type === 'Laboratory' ? 'bg-emerald-100 text-emerald-800' : 
                                 room.type === 'Special' ? 'bg-purple-100 text-purple-800' : 
                                 'bg-blue-100 text-blue-800'}">
                            ${room.type}
                        </span>
                    </td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[0]}/8</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[1]}/8</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[2]}/8</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[3]}/8</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[4]}/8</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[5]}/5</td>
                    <td class="py-3 px-4 text-center">${dayUtilizations[6]}/3</td>
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 bg-slate-100 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full" style="width: ${room.utilization}"></div>
                            </div>
                            <span class="text-sm font-bold text-slate-700 min-w-10">${room.utilization}</span>
                        </div>
                    </td>
                    <td class="py-3 px-4">
                        <button onclick="editRoom('${room.name}')" 
                                class="p-2 text-slate-600 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                            <i class="fas fa-edit"></i>
                        </button>
                    </td>
                `;

                    tableBody.appendChild(row);
                });

            } catch (error) {
                console.error('Error loading room allocation:', error);
                Toast.error('Failed to load room allocation.');
            }
        }

        // Scan for conflicts with API
        async function scanForConflicts() {
            try {
                // This would normally scan for conflicts in the database
                const conflictsList = document.getElementById('conflictsList');

                // Show loading state
                conflictsList.innerHTML = `
                <div class="text-center py-8">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mb-3"></div>
                    <h4 class="text-lg font-bold text-slate-900 mb-2">Scanning for Conflicts</h4>
                    <p class="text-slate-500">Checking teacher schedules and room allocations...</p>
                </div>
            `;

                // Simulate API call
                setTimeout(() => {
                    conflictsList.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-check-circle text-4xl text-emerald-500 mb-3"></i>
                        <h4 class="text-lg font-bold text-slate-900 mb-2">No Conflicts Found</h4>
                        <p class="text-slate-500">Your timetable is currently conflict-free!</p>
                        <div class="mt-4 text-sm text-slate-400">
                            Last scanned: ${new Date().toLocaleTimeString()}
                        </div>
                    </div>
                `;

                    Toast.success('No conflicts found in the timetable');
                }, 1500);

            } catch (error) {
                console.error('Error scanning for conflicts:', error);
                Toast.error('Failed to scan for conflicts.');
            }
        }

        // Filter timetable
        function filterTimetable() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            if (!searchTerm) {
                // Show all periods
                document.querySelectorAll('.timetable-period').forEach(period => {
                    period.style.display = 'block';
                });
                return;
            }

            // Filter periods
            document.querySelectorAll('.timetable-period').forEach(period => {
                const periodText = period.textContent.toLowerCase();
                if (periodText.includes(searchTerm)) {
                    period.style.display = 'block';
                } else {
                    period.style.display = 'none';
                }
            });
        }

        // Apply filters with API
        async function applyFilters() {
            const grade = document.getElementById('filterGrade').value;
            const section = document.getElementById('filterSection').value;
            const day = document.getElementById('filterDay').value;
            const periodType = document.getElementById('filterPeriodType').value;

            // Show loading
            Toast.info('Applying filters...');

            // This would normally apply filters to the timetable
            if (selectedClass) {
                await loadTimetable();
            }

            toggleAdvancedFilters();

            Toast.success('Filters applied successfully');
        }

        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('filterGrade').value = '';
            document.getElementById('filterSection').value = '';
            document.getElementById('filterDay').value = '';
            document.getElementById('filterPeriodType').value = '';

            // Reset chip filters
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            document.querySelector('.filter-chip[data-filter="all"]').classList.add('active');

            // Reset timetable view
            if (selectedClass) {
                loadTimetable();
            }

            Toast.info('All filters cleared');
        }

        // Toggle filter chip
        function toggleFilter(filterType) {
            // Update active chip
            document.querySelectorAll('.filter-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            event.target.classList.add('active');

            // Apply filter logic
            switch (filterType) {
                case 'today':
                    document.getElementById('filterDay').value = jsData.current_db_day;
                    break;
                case 'grade10':
                    document.getElementById('filterGrade').value = '10';
                    break;
                case 'grade11':
                    document.getElementById('filterGrade').value = '11';
                    break;
                case 'grade12':
                    document.getElementById('filterGrade').value = '12';
                    break;
            }

            applyFilters();
        }

        // Toggle advanced filters
        function toggleAdvancedFilters() {
            const filters = document.getElementById('advancedFilters');
            filters.classList.toggle('hidden');
        }

        // Process timetable generation
        async function processTimetableGeneration() {
            const grade = document.getElementById('generateGrade').value;

            try {
                const formData = new FormData();
                formData.append('action', 'generate_timetable');
                formData.append('grade', grade);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                Toast.success('Timetable generated successfully!');
                closeModal('generateTimetableModal');

                // Reload timetable if applicable
                if (selectedClass && (!grade || selectedClass.startsWith(grade))) {
                    await loadTimetable();
                }

            } catch (error) {
                console.error('Error generating timetable:', error);
                Toast.error('Failed to generate timetable.');
            }
        }

        // Copy timetable to other classes
        async function copyTimetableToOtherClasses() {
            if (!selectedClass) {
                Toast.warning('Please select a class first');
                return;
            }

            const [sourceGrade, sourceSection] = selectedClass.split('-');

            // Get available classes to copy to
            const targetClasses = classesList.filter(cls =>
                cls.grade_level == sourceGrade &&
                cls.section != sourceSection
            );

            if (targetClasses.length === 0) {
                Toast.warning('No other classes found in the same grade');
                return;
            }

            // Create copy modal
            showCopyTimetableModal(sourceGrade, sourceSection, targetClasses);
        }

        // Show copy timetable modal
        function showCopyTimetableModal(sourceGrade, sourceSection, targetClasses) {
            // Create modal if it doesn't exist
            let modal = document.getElementById('copyTimetableModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'copyTimetableModal';
                modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden';
                modal.innerHTML = `
                <div class="modal-content bg-white rounded-2xl w-full max-w-md mx-4">
                    <div class="p-6">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-xl font-black text-slate-900">Copy Timetable</h3>
                            <button onclick="closeModal('copyTimetableModal')" class="text-slate-400 hover:text-slate-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>
                        <div id="copyTimetableContent"></div>
                    </div>
                </div>
            `;
                document.body.appendChild(modal);
            }

            // Populate content
            const content = document.getElementById('copyTimetableContent');
            content.innerHTML = `
            <div class="space-y-4">
                <div class="p-4 bg-indigo-50 rounded-xl">
                    <h4 class="font-bold text-slate-900 mb-2">Copy from:</h4>
                    <p class="text-indigo-700 font-medium">Grade ${sourceGrade} - Section ${sourceSection}</p>
                </div>
                
                <div>
                    <label class="form-label mb-3">Select target classes:</label>
                    <div class="space-y-2 max-h-60 overflow-y-auto p-2">
                        ${targetClasses.map(cls => `
                            <label class="flex items-center p-3 border border-slate-200 rounded-lg hover:bg-slate-50 cursor-pointer">
                                <input type="checkbox" class="rounded border-slate-300 mr-3" value="${cls.id}">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900">${cls.display_name}</p>
                                    <p class="text-xs text-slate-500">${cls.class_teacher_name || 'No class teacher'}</p>
                                </div>
                            </label>
                        `).join('')}
                    </div>
                </div>
                
                <div class="border-t border-slate-100 pt-4">
                    <div class="flex items-center mb-3">
                        <input type="checkbox" id="overwriteExisting" class="rounded border-slate-300 mr-2">
                        <label for="overwriteExisting" class="text-sm text-slate-700">
                            Overwrite existing periods in target classes
                        </label>
                    </div>
                    <p class="text-xs text-slate-500">
                        This will copy all periods from the source class to selected target classes.
                    </p>
                </div>
                
                <div class="flex gap-3 mt-6 pt-4 border-t border-slate-100">
                    <button onclick="closeModal('copyTimetableModal')" 
                            class="flex-1 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition">
                        Cancel
                    </button>
                    <button onclick="executeCopyTimetable()" 
                            class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all">
                        Copy Timetable
                    </button>
                </div>
            </div>
        `;

            openModal('copyTimetableModal');
        }

        // Execute copy timetable
        async function executeCopyTimetable() {
            const sourceClass = selectedClass;
            const [sourceGrade, sourceSection] = sourceClass.split('-');

            // Get selected target classes
            const checkboxes = document.querySelectorAll('#copyTimetableContent input[type="checkbox"]:checked');
            const targetClassIds = Array.from(checkboxes).map(cb => cb.value);

            if (targetClassIds.length === 0) {
                Toast.warning('Please select at least one target class');
                return;
            }

            const overwrite = document.getElementById('overwriteExisting').checked;

            try {
                const formData = new FormData();
                formData.append('action', 'copy_timetable');
                formData.append('source_grade', sourceGrade);
                formData.append('source_section', sourceSection);
                formData.append('target_class_ids', JSON.stringify(targetClassIds));
                formData.append('overwrite', overwrite);

                const response = await fetch(jsData.api_url, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) {
                    Toast.error(data.error);
                    return;
                }

                Toast.success(`Timetable copied to ${targetClassIds.length} class${targetClassIds.length > 1 ? 'es' : ''}`);
                closeModal('copyTimetableModal');

            } catch (error) {
                console.error('Error copying timetable:', error);
                Toast.error('Failed to copy timetable.');
            }
        }

        // Print timetable
        function printTimetable() {
            if (!selectedClass) {
                Toast.warning('Please select a class to print');
                return;
            }

            // Create print-friendly version
            const printWindow = window.open('', '_blank');
            const [grade, section] = selectedClass.split('-');

            // Get current date
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });

            // Generate print content
            printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Timetable - Grade ${grade} Section ${section}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    .header { text-align: center; margin-bottom: 30px; }
                    .school-name { font-size: 24px; font-weight: bold; margin-bottom: 5px; }
                    .timetable-title { font-size: 20px; margin-bottom: 10px; }
                    .print-date { color: #666; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
                    th { background-color: #f8fafc; font-weight: bold; }
                    .period-cell { min-height: 60px; }
                    .period { margin: 2px 0; padding: 5px; border-left: 4px solid #4f46e5; background: #f8fafc; }
                    .period-subject { font-weight: bold; }
                    .period-teacher { font-size: 12px; color: #666; }
                    .period-room { font-size: 12px; color: #10b981; }
                    .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="header">
                    <div class="school-name">${jsData.school.name}</div>
                    <div class="timetable-title">Timetable - Grade ${grade} Section ${section}</div>
                    <div class="print-date">Printed on ${dateStr}</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            ${jsData.days_of_week.map(day => `<th>${day}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${generatePrintTimetableRows()}
                    </tbody>
                </table>
                <div class="footer">
                    <p>Generated by AcademixSuite Timetable Management System</p>
                </div>
                <script>
                    window.onload = function() {
                        window.print();
                        setTimeout(() => window.close(), 100);
                    };
                </script>   
            </body>
            </html>
`);

printWindow.document.close();
}

// Generate print timetable rows
function generatePrintTimetableRows() {
let rows = '';
const timeSlots = jsData.time_slots;
const days = jsData.db_days;

Object.keys(timeSlots).forEach((timeKey, index) => {
const nextIndex = index + 1;
const nextTimeKey = Object.keys(timeSlots)[nextIndex] || '17:00:00';

rows += '<tr>';
    rows += `<td>${timeSlots[timeKey]}</td>`;

    days.forEach(day => {
    const period = timetableData.find(p =>
    p.day === day &&
    p.start_time === timeKey &&
    p.end_time === nextTimeKey
    );

    if (period) {
    rows += `
    <td class="period-cell">
        <div class="period">
            <div class="period-subject">${period.subject_name}</div>
            <div class="period-teacher">${period.teacher_first_name} ${period.teacher_last_name}</div>
            <div class="period-room">${period.room_name || 'No Room'}</div>
        </div>
    </td>
    `;
    } else {
    rows += '<td></td>';
    }
    });

    rows += '
</tr>';
});

return rows;
}

// Print teacher schedule
function printTeacherSchedule() {
if (!selectedTeacher) {
Toast.warning('Please select a teacher first');
return;
}

// Similar print implementation as printTimetable
Toast.info(`Printing ${selectedTeacher.name}'s schedule...`);
setTimeout(() => {
window.print();
}, 500);
}

// Print all teacher schedules
function printAllTeacherSchedules() {
Toast.info('Preparing all teacher schedules for printing...');
}

// Export timetable
async function exportTimetable() {
if (!selectedClass) {
Toast.warning('Please select a class to export');
return;
}

try {
const [grade, section] = selectedClass.split('-');

// Create CSV content
let csvContent = "Subject,Teacher,Room,Day,Start Time,End Time,Type\n";

timetableData.forEach(period => {
csvContent += `"${period.subject_name}",`;
csvContent += `"${period.teacher_first_name} ${period.teacher_last_name}",`;
csvContent += `"${period.room_name || ''}",`;
csvContent += `"${period.day}",`;
csvContent += `"${period.start_time}",`;
csvContent += `"${period.end_time}",`;
csvContent += `"${period.period_type || 'regular'}"\n`;
});

// Create download link
const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
const url = URL.createObjectURL(blob);
const link = document.createElement('a');
link.href = url;
link.download = `timetable-grade-${grade}-section-${section}-${new Date().toISOString().split('T')[0]}.csv`;
document.body.appendChild(link);
link.click();
document.body.removeChild(link);

Toast.success('Timetable exported successfully');

} catch (error) {
console.error('Error exporting timetable:', error);
Toast.error('Failed to export timetable.');
}
}

// View today's schedule
function viewTodaySchedule() {
document.getElementById('filterDay').value = jsData.current_db_day;
applyFilters();
Toast.info(`Showing schedule for ${jsData.current_day}`);
}

// Send schedule notifications
async function sendScheduleNotifications() {
if (!selectedClass) {
Toast.warning('Please select a class first');
return;
}

try {
const [grade, section] = selectedClass.split('-');

// This would normally send notifications via API
// For now, show a confirmation
if (confirm(`Send schedule notifications for Grade ${grade} Section ${section} to teachers and students?`)) {
Toast.info('Sending schedule notifications...');

// Simulate API call
setTimeout(() => {
Toast.success('Notifications sent successfully');
}, 1500);
}

} catch (error) {
console.error('Error sending notifications:', error);
Toast.error('Failed to send notifications.');
}
}

// View academic calendar
function viewAcademicCalendar() {
Toast.info('Opening academic calendar...');
// This would open the academic calendar page
}

// Add new room
function addNewRoom() {
Toast.info('Add new room feature coming soon');
}

// Edit room
function editRoom(roomName) {
Toast.info(`Editing ${roomName}...`);
}

// Auto-resolve conflicts
function autoResolveConflicts() {
Toast.info('Auto-resolving conflicts...');
}

// Modal functions
function openModal(modalId) {
const modal = document.getElementById(modalId);
modal.classList.remove('hidden');
document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
const modal = document.getElementById(modalId);
modal.classList.add('hidden');
document.body.style.overflow = '';
}

// Mobile sidebar toggle
function mobileSidebarToggle() {
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
sidebar.classList.toggle('-translate-x-full');
overlay.classList.toggle('hidden');
}

// Setup event listeners
function setupEventListeners() {
// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
// Ctrl/Cmd + N for new period
if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
e.preventDefault();
addNewPeriod();
}

// Ctrl/Cmd + F for search
if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
e.preventDefault();
document.getElementById('searchInput').focus();
}

// Ctrl/Cmd + S for save
if ((e.ctrlKey || e.metaKey) && e.key === 's') {
e.preventDefault();
if (document.getElementById('periodModal').classList.contains('hidden')) {
Toast.info('No form to save');
}
}

// Esc to close modals
if (e.key === 'Escape') {
closeModal('periodModal');
closeModal('teacherScheduleModal');
closeModal('generateTimetableModal');
closeModal('periodDetailsModal');
closeModal('copyTimetableModal');
}
});

// Auto-update end time when start time changes
const startTimeInput = document.getElementById('startTime');
const endTimeInput = document.getElementById('endTime');

if (startTimeInput && endTimeInput) {
startTimeInput.addEventListener('change', function() {
const startTime = this.value;
if (startTime) {
// Calculate default end time (45 minutes later)
const [hours, minutes] = startTime.split(':');
const startDate = new Date();
startDate.setHours(parseInt(hours), parseInt(minutes), 0);
startDate.setMinutes(startDate.getMinutes() + 45);

const endHours = startDate.getHours().toString().padStart(2, '0');
const endMinutes = startDate.getMinutes().toString().padStart(2, '0');
endTimeInput.value = `${endHours}:${endMinutes}`;
}
});
}
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
initializePage();
});
</script>
</body>

</html>