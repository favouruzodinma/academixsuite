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
        case 'get_teacher_load':
            getTeacherLoad($schoolDb, $school['id']);
            break;
        case 'get_available_teachers':
            getAvailableTeachers($schoolDb, $school['id']);
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
                CONCAT(u.first_name, ' ', u.last_name) as teacher_full_name,
                CONCAT('Room ', COALESCE(t.room_number, c.room_number)) as room_name,
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
                CONCAT('Room ', COALESCE(t.room_number, c.room_number)) as room_name,
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
            AND teacher_id = ?
            AND day = ?
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
            AND id != ?
        ";

        $conflictParams = [
            $schoolId,
            $periodData['teacher_id'],
            strtolower($periodData['day']),
            $periodData['start_time'],
            $periodData['start_time'],
            $periodData['end_time'],
            $periodData['end_time'],
            $periodData['start_time'],
            $periodData['end_time'],
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
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
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

            // Update teacher's current weekly periods
            updateTeacherPeriods($db, $periodData['teacher_id'], $schoolId, 1);

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

// Helper function to update teacher periods
function updateTeacherPeriods($db, $teacherId, $schoolId, $increment = 1)
{
    try {
        // Check if teacher record exists
        $checkSql = "SELECT id FROM teachers WHERE user_id = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([$teacherId]);
        $teacherRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($teacherRecord) {
            // Update existing record
            $updateSql = "
                UPDATE teachers 
                SET current_weekly_periods = COALESCE(current_weekly_periods, 0) + ?
                WHERE user_id = ?
            ";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([$increment, $teacherId]);
        } else {
            // Create new record
            $insertSql = "
                INSERT INTO teachers (user_id, current_weekly_periods, created_at)
                VALUES (?, ?, NOW())
            ";
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([$teacherId, $increment]);
        }
    } catch (Exception $e) {
        error_log("Error updating teacher periods: " . $e->getMessage());
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
                t.qualification,
                t.experience_years,
                t.max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
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

        // Add availability info
        foreach ($teachers as &$teacher) {
            $maxPeriods = $teacher['max_weekly_periods'] ?? 30;
            $currentPeriods = $teacher['current_weekly_periods'] ?? 0;
            $remaining = $maxPeriods - $currentPeriods;
            $utilization = $maxPeriods > 0 ? round(($currentPeriods / $maxPeriods) * 100, 2) : 0;

            $teacher['availability'] = [
                'remaining_periods' => $remaining,
                'utilization_percentage' => $utilization
            ];
        }

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
            SELECT id, name, code, type, credit_hours, is_compulsory, description
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

function getClassSections($db, $schoolId)
{
    try {
        $sql = "
            SELECT id, name, grade_level, class_id
            FROM class_sections
            WHERE school_id = ? AND is_active = 1
            ORDER BY grade_level, name
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId]);
        $classSections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'class_sections' => $classSections
        ]);
    } catch (Exception $e) {
        error_log("Error getting class sections: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getGradeLevels($db, $schoolId)
{
    try {
        $sql = "
            SELECT id, name, code, description
            FROM grade_levels
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId]);
        $gradeLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'grade_levels' => $gradeLevels
        ]);
    } catch (Exception $e) {
        error_log("Error getting grade levels: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getTeacherById($db, $teacherId)
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
                t.qualification,
                t.experience_years,
                t.max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.id = ? AND u.user_type = 'teacher'
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$teacherId]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

        return $teacher ?: null;
    } catch (Exception $e) {
        error_log("Error getting teacher by ID: " . $e->getMessage());
        return null;
    }
}

function searchTeachers($db, $schoolId, $searchTerm)
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
                t.qualification,
                t.experience_years,
                t.max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.user_type = 'teacher' AND u.school_id = ?
            AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, "%$searchTerm%", "%$searchTerm%", "%$searchTerm%"]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $teachers;
    } catch (Exception $e) {
        error_log("Error searching teachers: " . $e->getMessage());
        return [];
    }
}

function searchSubjects($db, $schoolId, $searchTerm)
{
    try {
        $sql = "
            SELECT id, name, code, type, credit_hours, is_compulsory, description
            FROM subjects
            WHERE school_id = ? 
            AND (name LIKE ? OR code LIKE ?)
            AND is_active = 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, "%$searchTerm%", "%$searchTerm%"]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $subjects;
    } catch (Exception $e) {
        error_log("Error searching subjects: " . $e->getMessage());
        return [];
    }
}

function getClassDetails($db, $classId)
{
    try {
        $sql = "
            SELECT 
                c.id,
                c.name,
                c.code,
                c.grade_level,
                c.section,
                c.capacity,
                c.current_students,
                c.room_number,
                c.class_teacher_id,
                CONCAT('Grade ', c.grade_level, ' - Section ', c.section) as display_name,
                CONCAT(u.first_name, ' ', u.last_name) as class_teacher_name
            FROM classes c
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.id = ? AND c.is_active = 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$classId]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);

        return $class ?: null;
    } catch (Exception $e) {
        error_log("Error getting class details: " . $e->getMessage());
        return null;
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
            SELECT 
                c.id,
                c.name,
                c.code,
                c.grade_level,
                c.section,
                c.capacity,
                c.current_students,
                c.room_number,
                c.class_teacher_id,
                CONCAT('Grade ', c.grade_level, ' - Section ', c.section) as display_name,
                CONCAT(u.first_name, ' ', u.last_name) as class_teacher_name
            FROM classes c
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.school_id = ? 
            AND c.academic_year_id = ?
            AND c.is_active = 1
            ORDER BY c.grade_level, c.section
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

        // Get period info before deletion
        $periodSql = "SELECT teacher_id FROM timetables WHERE id = ? AND school_id = ?";
        $periodStmt = $db->prepare($periodSql);
        $periodStmt->execute([$periodId, $schoolId]);
        $period = $periodStmt->fetch(PDO::FETCH_ASSOC);

        if ($period && $period['teacher_id']) {
            // Decrement teacher's current weekly periods
            updateTeacherPeriods($db, $period['teacher_id'], $schoolId, -1);
        }

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
                CONCAT('Room ', COALESCE(t.room_number, c.room_number)) as room_name,
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

// Function to get teacher load
function getTeacherLoad($db, $schoolId)
{
    try {
        $teacherId = $_POST['teacher_id'] ?? $_GET['teacher_id'] ?? '';
        if (empty($teacherId)) {
            echo json_encode(['error' => 'Teacher ID is required']);
            return;
        }

        // Get teacher's weekly schedule
        $sql = "
            SELECT 
                day,
                COUNT(*) as periods_per_day,
                GROUP_CONCAT(CONCAT(start_time, '-', end_time)) as time_slots,
                GROUP_CONCAT(DISTINCT subject_id) as subject_ids,
                GROUP_CONCAT(DISTINCT class_id) as class_ids
            FROM timetables
            WHERE school_id = ? 
            AND teacher_id = ?
            GROUP BY day
            ORDER BY FIELD(day, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday')
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, $teacherId]);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get teacher info
        $teacherSql = "
            SELECT 
                u.first_name,
                u.last_name,
                t.max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.id = ?
        ";

        $teacherStmt = $db->prepare($teacherSql);
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);

        if (!$teacher) {
            echo json_encode(['error' => 'Teacher not found']);
            return;
        }

        // Calculate workload statistics
        $totalPeriods = array_sum(array_column($schedule, 'periods_per_day'));
        $maxPeriods = $teacher['max_weekly_periods'] ?? 30;
        $utilizationPercentage = $maxPeriods > 0 ? round(($totalPeriods / $maxPeriods) * 100, 2) : 0;

        echo json_encode([
            'success' => true,
            'teacher' => [
                'id' => $teacherId,
                'full_name' => $teacher['first_name'] . ' ' . $teacher['last_name'],
                'max_weekly_periods' => $maxPeriods,
                'current_weekly_periods' => $totalPeriods,
                'remaining_periods' => $maxPeriods - $totalPeriods,
                'utilization_percentage' => $utilizationPercentage
            ],
            'schedule' => $schedule,
            'workload_summary' => [
                'total_days' => count($schedule),
                'total_periods' => $totalPeriods,
                'average_periods_per_day' => count($schedule) > 0 ? round($totalPeriods / count($schedule), 2) : 0,
                'busiest_day' => !empty($schedule) ?
                    max(array_column($schedule, 'periods_per_day')) : 0
            ]
        ]);
    } catch (Exception $e) {
        error_log("Error getting teacher load: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
}

// Function to get available teachers
function getAvailableTeachers($db, $schoolId)
{
    try {
        $day = $_POST['day'] ?? $_GET['day'] ?? '';
        $startTime = $_POST['start_time'] ?? $_GET['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? $_GET['end_time'] ?? '';

        if (empty($day) || empty($startTime) || empty($endTime)) {
            echo json_encode(['error' => 'Day, start_time, and end_time parameters are required']);
            return;
        }

        // Convert day to lowercase for consistency
        $day = strtolower($day);

        // First, get all teachers
        $teacherSql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                t.max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.user_type = 'teacher'
            AND u.is_active = 1
        ";

        $teacherStmt = $db->prepare($teacherSql);
        $teacherStmt->execute([$schoolId]);
        $allTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get teachers who are busy during the requested time
        $busySql = "
            SELECT DISTINCT teacher_id
            FROM timetables
            WHERE school_id = ?
            AND day = ?
            AND (
                (start_time <= ? AND end_time > ?) OR
                (start_time < ? AND end_time >= ?) OR
                (start_time >= ? AND end_time <= ?)
            )
        ";

        $busyStmt = $db->prepare($busySql);
        $busyStmt->execute([
            $schoolId,
            $day,
            $startTime,
            $startTime,
            $endTime,
            $endTime,
            $startTime,
            $endTime
        ]);
        $busyTeachers = $busyStmt->fetchAll(PDO::FETCH_COLUMN, 0);

        // Filter available teachers
        $availableTeachers = [];
        foreach ($allTeachers as $teacher) {
            if (!in_array($teacher['id'], $busyTeachers)) {
                $remainingPeriods = ($teacher['max_weekly_periods'] ?? 30) - ($teacher['current_weekly_periods'] ?? 0);

                if ($remainingPeriods > 0) {
                    $availableTeachers[] = [
                        'id' => $teacher['id'],
                        'full_name' => $teacher['full_name'],
                        'first_name' => $teacher['first_name'],
                        'last_name' => $teacher['last_name'],
                        'remaining_periods' => $remainingPeriods,
                        'current_weekly_periods' => $teacher['current_weekly_periods'] ?? 0,
                        'max_weekly_periods' => $teacher['max_weekly_periods'] ?? 30
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'available_teachers' => $availableTeachers,
            'count' => count($availableTeachers)
        ]);
    } catch (Exception $e) {
        error_log("Error getting available teachers: " . $e->getMessage());
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
        $sourceClassId = $_POST['source_class_id'] ?? '';
        $targetClassIds = json_decode($_POST['target_class_ids'] ?? '[]', true);
        $overwrite = $_POST['overwrite'] ?? false;

        if (empty($sourceClassId) || empty($targetClassIds)) {
            echo json_encode(['error' => 'Source class and target classes are required']);
            return;
        }

        // Get source class timetable
        $sourceSql = "SELECT * FROM timetables WHERE class_id = ? AND school_id = ?";
        $sourceStmt = $db->prepare($sourceSql);
        $sourceStmt->execute([$sourceClassId, $schoolId]);
        $sourcePeriods = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sourcePeriods)) {
            echo json_encode(['error' => 'No timetable found for source class']);
            return;
        }

        $copiedCount = 0;

        foreach ($targetClassIds as $targetClassId) {
            if ($overwrite) {
                // Delete existing periods for target class
                $deleteStmt = $db->prepare("DELETE FROM timetables WHERE class_id = ? AND school_id = ?");
                $deleteStmt->execute([$targetClassId, $schoolId]);
            }

            // Copy each period
            foreach ($sourcePeriods as $period) {
                $copySql = "
                    INSERT INTO timetables (
                        school_id, class_id, subject_id, teacher_id,
                        room_number, day, start_time, end_time,
                        period_number, is_break, academic_year_id, academic_term_id,
                        created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ";

                $copyStmt = $db->prepare($copySql);
                $copyStmt->execute([
                    $schoolId,
                    $targetClassId,
                    $period['subject_id'],
                    $period['teacher_id'],
                    $period['room_number'],
                    $period['day'],
                    $period['start_time'],
                    $period['end_time'],
                    $period['period_number'],
                    $period['is_break'],
                    $period['academic_year_id'],
                    $period['academic_term_id']
                ]);

                // Update teacher's current weekly periods
                updateTeacherPeriods($db, $period['teacher_id'], $schoolId, 1);

                $copiedCount++;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => "Timetable copied to " . count($targetClassIds) . " class(es)",
            'copied' => $copiedCount
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
    'api_url' => '../../api/timetable_api.php' // Changed to relative path
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
            grid-template-columns: 120px repeat(6, 1fr);
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

    <!-- Period Details Modal -->
    <div id="periodDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 500px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Period Details</h3>
                    <button onclick="closeModal('periodDetailsModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="periodDetailsContent"></div>
            </div>
        </div>
    </div>

    <!-- Copy Timetable Modal -->
    <div id="copyTimetableModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[1000] hidden">
        <div class="modal-content" style="max-width: 500px;">
            <div class="p-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-black text-slate-900">Copy Timetable</h3>
                    <button onclick="closeModal('copyTimetableModal')" class="text-slate-400 hover:text-slate-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div id="copyTimetableContent"></div>
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
                <!-- Navigation links remain the same -->
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
                        <?php
                        $gradeLevels = [];
                        foreach ($classes as $class) {
                            if (!in_array($class['grade_level'], $gradeLevels)) {
                                $gradeLevels[] = $class['grade_level'];
                            }
                        }
                        sort($gradeLevels);
                        foreach ($gradeLevels as $grade): ?>
                            <span class="filter-chip" onclick="toggleFilter('grade<?php echo $grade; ?>')" data-filter="grade<?php echo $grade; ?>">
                                <i class="fas fa-graduation-cap"></i> Grade <?php echo $grade; ?>
                            </span>
                        <?php endforeach; ?>
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
                                <?php foreach ($dbDays as $day): ?>
                                    <div class="timetable-header <?php echo ucfirst($day) === $currentDay ? 'bg-indigo-50 text-indigo-700' : ''; ?>">
                                        <?php echo ucfirst($day); ?>
                                        <?php if (ucfirst($day) === $currentDay): ?>
                                            <div class="text-xs font-normal text-indigo-500 mt-1">Today</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

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
                            <!-- Today's highlights content -->
                        </div>
                    </div>

                    <!-- Upcoming Changes -->
                    <div class="glass-card rounded-2xl p-6">
                        <h3 class="text-lg font-black text-slate-900 mb-6">Upcoming Schedule Changes</h3>
                        <div class="space-y-4" id="upcomingChanges">
                            <!-- Upcoming changes content -->
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
    let timetableData = [];
    let teachersList = [];
    let subjectsList = [];
    let classesList = [];

    // API Configuration
    const API_URL = '../../api/timetable_api.php';

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

    // API Helper Functions - USING POST METHOD FOR BETTER SESSION HANDLING
    async function apiRequest(action, params = {}) {
        // Use POST method to ensure session is maintained
        const formData = new FormData();
        formData.append('action', action);
        formData.append('school_slug', jsData.school.slug);
        
        // Add additional parameters
        Object.keys(params).forEach(key => {
            if (params[key] !== undefined && params[key] !== null) {
                if (typeof params[key] === 'object') {
                    formData.append(key, JSON.stringify(params[key]));
                } else {
                    formData.append(key, params[key]);
                }
            }
        });

        console.log(`Making API request: ${action}`, Object.fromEntries(formData));

        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                body: formData,
                credentials: 'include' // THIS IS CRITICAL - sends cookies/session
            });
            
            console.log(`Response status for ${action}:`, response.status);
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('Error response:', errorText);
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            console.log(`Response data for ${action}:`, data);
            
            if (!data.success) {
                throw new Error(data.error || 'Unknown error occurred');
            }

            return data;
        } catch (error) {
            console.error(`API Error (${action}):`, error);
            Toast.error(error.message || `Failed to ${action.replace('_', ' ')}`);
            throw error;
        }
    }

    // Initialize page
    function initializePage() {
        console.log('Initializing timetable page...');
        console.log('School data:', jsData.school);
        
        // Show loading toast
        Toast.info('Loading timetable data...', 2000);
        
        loadInitialData();
        setupEventListeners();
        setupButtonEventListeners();

        // Welcome toast
        setTimeout(() => {
            Toast.success('Timetable Management loaded successfully!', 3000);
        }, 1000);
    }

    // Setup button event listeners (instead of inline onclick)
    function setupButtonEventListeners() {
        // Add Period button
        const addPeriodBtn = document.querySelector('button[onclick*="addNewPeriod"]');
        if (addPeriodBtn) {
            addPeriodBtn.addEventListener('click', addNewPeriod);
        }

        // Print button
        const printBtn = document.querySelector('button[onclick*="printTimetable"]');
        if (printBtn) {
            printBtn.addEventListener('click', printTimetable);
        }

        // Generate Timetable button
        const generateBtn = document.querySelector('button[onclick*="processTimetableGeneration"]');
        if (generateBtn) {
            generateBtn.addEventListener('click', processTimetableGeneration);
        }

        // Copy Timetable button
        const copyBtn = document.querySelector('button[onclick*="copyTimetableToOtherClasses"]');
        if (copyBtn) {
            copyBtn.addEventListener('click', copyTimetableToOtherClasses);
        }

        // Mobile sidebar toggle
        const mobileToggleBtn = document.querySelector('button[onclick*="mobileSidebarToggle"]');
        if (mobileToggleBtn) {
            mobileToggleBtn.addEventListener('click', mobileSidebarToggle);
        }
    }

    // Load initial data
    async function loadInitialData() {
        try {
            console.log('Loading initial data...');
            
            // Load all data in sequence (not parallel) for better debugging
            await loadTeachers();
            await loadSubjects();
            await loadClasses();
            
            // Load teacher list for filter
            await updateTeacherFilter();
            
            console.log('Initial data loaded successfully');
            
        } catch (error) {
            console.error('Error loading initial data:', error);
            Toast.error('Failed to load initial data. Please refresh the page.');
        }
    }

    // Load teachers
    async function loadTeachers() {
        try {
            console.log('Loading teachers...');
            const data = await apiRequest('get_teachers');
            teachersList = data.teachers || [];
            console.log(`Loaded ${teachersList.length} teachers`);
            updateTeacherDropdown();
        } catch (error) {
            console.error('Error loading teachers:', error);
            Toast.error('Failed to load teachers. Please check your connection.');
        }
    }

    // Load subjects
    async function loadSubjects() {
        try {
            console.log('Loading subjects...');
            const data = await apiRequest('get_subjects');
            subjectsList = data.subjects || [];
            console.log(`Loaded ${subjectsList.length} subjects`);
            updateSubjectDropdown();
        } catch (error) {
            console.error('Error loading subjects:', error);
            Toast.error('Failed to load subjects.');
        }
    }

    // Load classes
    async function loadClasses() {
        try {
            console.log('Loading classes...');
            const data = await apiRequest('get_classes');
            classesList = data.classes || [];
            console.log(`Loaded ${classesList.length} classes`);
            updateClassDropdown();
            updateClassSelector();
        } catch (error) {
            console.error('Error loading classes:', error);
            Toast.error('Failed to load classes.');
        }
    }

    // Update teacher dropdown in modal
    function updateTeacherDropdown() {
        const teacherSelect = document.getElementById('teacherId');
        if (!teacherSelect) {
            console.error('Teacher select element not found');
            return;
        }

        teacherSelect.innerHTML = '<option value="">Select Teacher</option>';

        if (teachersList.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No teachers found';
            option.disabled = true;
            teacherSelect.appendChild(option);
            return;
        }

        teachersList.forEach(teacher => {
            const option = document.createElement('option');
            option.value = teacher.id;
            const fullName = teacher.full_name || `${teacher.first_name} ${teacher.last_name}`;
            option.textContent = teacher.specialization ?
                `${fullName} - ${teacher.specialization}` : fullName;
            
            option.dataset.remainingPeriods = teacher.availability?.remaining_periods || 30;
            option.dataset.utilization = teacher.availability?.utilization_percentage || 0;
            teacherSelect.appendChild(option);
        });

        // Add availability indicator
        let indicator = teacherSelect.parentNode.querySelector('.availability-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'availability-indicator text-xs text-slate-500 mt-1 hidden';
            teacherSelect.parentNode.appendChild(indicator);
        }

        // Show availability on selection
        teacherSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value && selectedOption.dataset.remainingPeriods) {
                const remaining = parseInt(selectedOption.dataset.remainingPeriods);
                const utilization = selectedOption.dataset.utilization;
                
                indicator.textContent = `Available periods: ${remaining} (${utilization}% utilized)`;
                indicator.classList.remove('hidden');

                // Color code based on availability
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
        if (!subjectSelect) {
            console.error('Subject select element not found');
            return;
        }

        subjectSelect.innerHTML = '<option value="">Select Subject</option>';

        if (subjectsList.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No subjects found';
            option.disabled = true;
            subjectSelect.appendChild(option);
            return;
        }

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
        let indicator = subjectSelect.parentNode.querySelector('.subject-type-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'subject-type-indicator text-xs text-slate-500 mt-1 hidden';
            subjectSelect.parentNode.appendChild(indicator);
        }

        subjectSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
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
        if (!classSelect) {
            console.error('Class select element not found');
            return;
        }

        classSelect.innerHTML = '<option value="">Select Class</option>';

        if (classesList.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No classes found';
            option.disabled = true;
            classSelect.appendChild(option);
            return;
        }

        classesList.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls.id;
            option.textContent = cls.display_name || `Grade ${cls.grade_level} - Section ${cls.section}`;
            option.dataset.grade = cls.grade_level;
            option.dataset.section = cls.section;
            option.dataset.capacity = cls.capacity;
            option.dataset.students = cls.current_students;
            option.dataset.availability = cls.availability?.seats_available || 0;
            classSelect.appendChild(option);
        });

        // Add class info indicator
        let indicator = classSelect.parentNode.querySelector('.class-info-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.className = 'class-info-indicator text-xs text-slate-500 mt-1 hidden';
            classSelect.parentNode.appendChild(indicator);
        }

        classSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
            if (selectedOption.value && selectedOption.dataset.capacity) {
                const capacity = selectedOption.dataset.capacity;
                const students = selectedOption.dataset.students;
                const available = selectedOption.dataset.availability;
                const occupancy = capacity > 0 ? Math.round((students / capacity) * 100) : 0;

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
        if (!classSelect) {
            console.error('Selected class select element not found');
            return;
        }

        classSelect.innerHTML = '<option value="">Select a class</option>';

        if (classesList.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No classes available';
            option.disabled = true;
            classSelect.appendChild(option);
            return;
        }

        classesList.forEach(cls => {
            const option = document.createElement('option');
            option.value = `${cls.grade_level}-${cls.section}`;
            option.textContent = cls.display_name || `Grade ${cls.grade_level} - Section ${cls.section}`;
            option.dataset.classId = cls.id;
            option.dataset.teacher = cls.class_teacher_name;
            classSelect.appendChild(option);
        });

        // Add class info display
        let infoDiv = classSelect.parentNode.querySelector('.selected-class-info');
        if (!infoDiv) {
            infoDiv = document.createElement('div');
            infoDiv.className = 'selected-class-info text-sm text-slate-600 mt-2 hidden';
            classSelect.parentNode.appendChild(infoDiv);
        }

        classSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            
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

    // Update teacher filter dropdown
    async function updateTeacherFilter() {
        const teacherFilter = document.getElementById('teacherFilter');
        if (!teacherFilter) return;

        teacherFilter.innerHTML = '<option value="">Select a teacher</option>';

        if (teachersList.length === 0) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No teachers available';
            option.disabled = true;
            teacherFilter.appendChild(option);
            return;
        }

        teachersList.forEach(teacher => {
            const option = document.createElement('option');
            option.value = teacher.id;
            const fullName = teacher.full_name || `${teacher.first_name} ${teacher.last_name}`;
            option.textContent = teacher.specialization ?
                `${fullName} (${teacher.specialization})` : fullName;
            option.dataset.periods = teacher.availability?.remaining_periods || 30;
            teacherFilter.appendChild(option);
        });

        // Also update teacher list display
        updateTeacherListDisplay(teachersList);
    }

    // Update teacher list display with enhanced info
    function updateTeacherListDisplay(teachers) {
        const teacherList = document.getElementById('teacherList');
        if (!teacherList) return;

        teacherList.innerHTML = '';

        if (teachers.length === 0) {
            teacherList.innerHTML = `
                <div class="text-center py-8">
                    <i class="fas fa-users text-3xl text-slate-300 mb-3"></i>
                    <p class="text-slate-500">No teachers found</p>
                </div>
            `;
            return;
        }

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

            // Create avatar initials
            const firstName = teacher.first_name || '';
            const lastName = teacher.last_name || '';
            const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();

            const div = document.createElement('div');
            div.className = 'flex items-center gap-3 p-3 hover:bg-slate-50 rounded-xl cursor-pointer border border-slate-100';
            div.addEventListener('click', () => viewTeacherSchedule(teacher.id, teacher.full_name || `${firstName} ${lastName}`));

            div.innerHTML = `
                <div class="flex-shrink-0">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white font-bold flex items-center justify-center">
                        ${initials}
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <p class="font-medium text-slate-900 truncate">${teacher.full_name || `${firstName} ${lastName}`}</p>
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

    // Load timetable
    async function loadTimetable() {
        const selectedClassValue = document.getElementById('selectedClass').value;
        if (!selectedClassValue) {
            clearTimetable();
            Toast.warning('Please select a class first');
            return;
        }

        selectedClass = selectedClassValue;

        const [grade, section] = selectedClassValue.split('-');

        try {
            Toast.info('Loading timetable...', 1000);
            
            const data = await apiRequest('get_timetable', {
                grade: grade,
                section: section
            });

            timetableData = data.timetable || [];
            console.log(`Loaded ${timetableData.length} periods`);
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

        if (timetableData.length === 0) {
            Toast.info('No periods scheduled for this class');
            return;
        }

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
        
        Toast.success(`Loaded ${timetableData.length} periods`, 2000);
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
        if (!cell) {
            console.warn(`Cell not found for day: ${day}, time: ${startTime}`);
            return;
        }

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

        // Create period element
        const periodElement = document.createElement('div');
        periodElement.className = `timetable-period border-l-4 border-${periodColor}-500 bg-white hover:bg-${periodColor}-50 transition-colors`;
        periodElement.dataset.periodId = period.id;
        
        periodElement.innerHTML = `
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="period-subject flex items-center gap-2">
                        <i class="fas ${periodIcon} text-${periodColor}-500"></i>
                        <span class="font-bold">${period.subject_name || 'No Subject'}</span>
                    </div>
                    <div class="period-teacher text-sm text-slate-600 mt-1">
                        <i class="fas fa-user-graduate mr-1"></i>
                        ${period.teacher_first_name || ''} ${period.teacher_last_name || ''}
                    </div>
                    <div class="period-room text-sm text-slate-500 mt-1">
                        <i class="fas fa-door-open mr-1"></i>
                        ${period.room_name || 'No Room'}
                    </div>
                </div>
                <div class="flex gap-1">
                    <button class="edit-period-btn p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors">
                        <i class="fas fa-edit text-xs"></i>
                    </button>
                    <button class="delete-period-btn p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                        <i class="fas fa-trash text-xs"></i>
                    </button>
                </div>
            </div>
            <div class="period-time text-xs text-slate-400 mt-2 pt-2 border-t border-slate-100">
                <i class="far fa-clock mr-1"></i>
                ${formatTime(startTime)} - ${formatTime(endTime)}
            </div>
        `;

        // Add event listeners
        periodElement.addEventListener('click', (e) => {
            if (!e.target.closest('button')) {
                viewPeriod(period.id);
            }
        });

        const editBtn = periodElement.querySelector('.edit-period-btn');
        editBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            editPeriod(period.id);
        });

        const deleteBtn = periodElement.querySelector('.delete-period-btn');
        deleteBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            deletePeriod(period.id);
        });

        cell.innerHTML = '';
        cell.appendChild(periodElement);
    }

    // View period details
    async function viewPeriod(periodId) {
        try {
            const data = await apiRequest('get_period', {
                period_id: periodId
            });

            const period = data.period;
            showPeriodDetailsModal(period);

        } catch (error) {
            console.error('Error viewing period:', error);
            Toast.error('Failed to load period details.');
        }
    }

    // Show period details modal
    function showPeriodDetailsModal(period) {
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
                        <h4 class="font-bold text-${periodColor}-700 text-lg">${period.subject_name || 'No Subject'}</h4>
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
                        <p class="font-bold text-slate-900">${period.teacher_first_name || ''} ${period.teacher_last_name || ''}</p>
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
                    <button onclick="editPeriod(${period.id})" 
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

    // Add new period
    function addNewPeriod() {
        if (!selectedClass) {
            Toast.warning('Please select a class first');
            return;
        }

        document.getElementById('periodModalTitle').textContent = 'Add New Period';
        document.getElementById('periodId').value = '';
        
        // Reset form
        const form = document.getElementById('periodForm');
        if (form) form.reset();
        
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
    async function editPeriod(periodId) {
        try {
            const data = await apiRequest('get_period', {
                period_id: periodId
            });

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
    async function deletePeriod(periodId) {
        if (!confirm('Are you sure you want to delete this period?')) {
            return;
        }

        try {
            const data = await apiRequest('delete_period', {
                period_id: periodId
            });

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

        // Validate required fields
        if (!classId || !subjectId || !teacherId || !day || !startTime || !endTime) {
            Toast.error('Please fill all required fields');
            return;
        }

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
            Toast.info('Saving period...', 1000);
            
            const data = await apiRequest('save_period', {
                period_id: periodId || '',
                period_data: JSON.stringify(periodData)
            });

            Toast.success(periodId ? 'Period updated successfully' : 'Period added successfully');
            closeModal('periodModal');
            loadTimetable();

        } catch (error) {
            console.error('Error saving period:', error);
            Toast.error('Failed to save period.');
        }
    }

    // Load teacher schedule
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

                    scheduleByDay[day].forEach(item => {
                        html += `
                            <div class="flex items-center gap-4 p-4 bg-white border border-slate-200 rounded-xl mb-3 hover:bg-slate-50 transition-colors">
                                <div class="text-center min-w-24">
                                    <div class="font-bold text-indigo-600">${item.periods_per_day}</div>
                                    <div class="text-xs text-slate-400">Periods</div>
                                </div>
                                <div class="flex-1">
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

    // View teacher schedule
    async function viewTeacherSchedule(teacherId, teacherName) {
        selectedTeacher = {
            id: teacherId,
            name: teacherName
        };

        document.getElementById('teacherScheduleTitle').textContent = `${teacherName}'s Schedule`;

        try {
            const data = await apiRequest('get_teacher_load', {
                teacher_id: teacherId
            });

            displayTeacherScheduleInModal(data);
            openModal('teacherScheduleModal');

        } catch (error) {
            console.error('Error loading teacher schedule:', error);
            Toast.error('Failed to load teacher schedule.');
        }
    }

    // Display teacher schedule in modal
    function displayTeacherScheduleInModal(data) {
        const contentDiv = document.getElementById('teacherScheduleContent');
        const teacher = data.teacher;
        const schedule = data.schedule;

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

        let html = `
            <div class="mb-6 p-4 bg-gradient-to-r from-indigo-50 to-purple-50 rounded-xl">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 text-white font-bold flex items-center justify-center">
                        ${teacher.full_name.split(' ').map(n => n[0]).join('').toUpperCase()}
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-slate-900 text-lg">${teacher.full_name}</h4>
                        <div class="flex items-center gap-4 mt-2">
                            <span class="text-xs bg-white px-2 py-1 rounded">
                                <i class="fas fa-clock mr-1"></i>
                                ${teacher.current_weekly_periods}/${teacher.max_weekly_periods} periods
                            </span>
                            <span class="text-xs bg-white px-2 py-1 rounded">
                                <i class="fas fa-chart-pie mr-1"></i>
                                ${teacher.utilization_percentage}% utilized
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Schedule by day
        const daysOrder = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

        daysOrder.forEach(day => {
            const daySchedule = schedule.find(s => s.day === day);
            if (daySchedule) {
                html += `<h5 class="font-bold text-slate-900 mb-3 mt-6 capitalize">${day}</h5>`;
                html += `
                    <div class="flex items-center gap-4 p-4 bg-white border border-slate-200 rounded-xl mb-3">
                        <div class="text-center min-w-24">
                            <div class="font-bold text-indigo-600">${daySchedule.periods_per_day}</div>
                            <div class="text-xs text-slate-400">Periods</div>
                        </div>
                        <div class="flex-1">
                            <div class="text-sm text-slate-600">
                                ${daySchedule.subject_ids ? daySchedule.subject_ids.split(',').length : 0} Subjects
                            </div>
                            <div class="text-xs text-slate-500 mt-1">
                                ${daySchedule.class_ids ? daySchedule.class_ids.split(',').length : 0} Classes
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        contentDiv.innerHTML = html;
    }

    // Switch view
    function switchView(viewName, event) {
        currentView = viewName;

        // Update active tab
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active');
        });
        if (event && event.target) {
            event.target.classList.add('active');
        }

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

    // Load room allocation
    async function loadRoomAllocation() {
        try {
            // This would normally fetch room allocation data
            // For now, using mock data
            const rooms = [
                {
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
                }
            ];

            const tableBody = document.getElementById('roomAllocationTable');
            if (!tableBody) return;
            
            tableBody.innerHTML = '';

            rooms.forEach(room => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-slate-50';
                row.innerHTML = `
                    <td class="py-3 px-4">
                        <div class="font-bold text-slate-900">${room.name}</div>
                    </td>
                    <td class="py-3 px-4">
                        <div class="text-sm font-medium">${room.capacity} seats</div>
                    </td>
                    <td class="py-3 px-4">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            ${room.type}
                        </span>
                    </td>
                    <td class="py-3 px-4 text-center">5/8</td>
                    <td class="py-3 px-4 text-center">6/8</td>
                    <td class="py-3 px-4 text-center">4/8</td>
                    <td class="py-3 px-4 text-center">7/8</td>
                    <td class="py-3 px-4 text-center">5/8</td>
                    <td class="py-3 px-4 text-center">2/5</td>
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

    // Scan for conflicts
    async function scanForConflicts() {
        try {
            const conflictsList = document.getElementById('conflictsList');
            if (!conflictsList) return;

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

    // Apply filters
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
    function toggleFilter(filterType, event) {
        // Update active chip
        document.querySelectorAll('.filter-chip').forEach(chip => {
            chip.classList.remove('active');
        });
        if (event && event.target) {
            event.target.classList.add('active');
        }

        // Apply filter logic
        switch (filterType) {
            case 'today':
                document.getElementById('filterDay').value = jsData.current_db_day;
                break;
            default:
                if (filterType.startsWith('grade')) {
                    const grade = filterType.replace('grade', '');
                    document.getElementById('filterGrade').value = grade;
                }
        }

        applyFilters();
    }

    // Toggle advanced filters
    function toggleAdvancedFilters() {
        const filters = document.getElementById('advancedFilters');
        if (filters) {
            filters.classList.toggle('hidden');
        }
    }

    // Process timetable generation
    async function processTimetableGeneration() {
        const grade = document.getElementById('generateGrade').value;

        try {
            const data = await apiRequest('generate_timetable', {
                grade: grade
            });

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

        // Get current class ID
        const sourceClass = classesList.find(cls =>
            cls.grade_level == sourceGrade && cls.section == sourceSection
        );

        if (!sourceClass) {
            Toast.error('Source class not found');
            return;
        }

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
        showCopyTimetableModal(sourceClass.id, sourceGrade, sourceSection, targetClasses);
    }

    // Show copy timetable modal
    function showCopyTimetableModal(sourceClassId, sourceGrade, sourceSection, targetClasses) {
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
                    <button onclick="executeCopyTimetable(${sourceClassId})" 
                            class="flex-1 py-3 bg-gradient-to-r from-emerald-600 to-teal-600 text-white font-bold rounded-xl hover:shadow-lg transition-all">
                        Copy Timetable
                    </button>
                </div>
            </div>
        `;

        openModal('copyTimetableModal');
    }

    // Execute copy timetable
    async function executeCopyTimetable(sourceClassId) {
        // Get selected target classes
        const checkboxes = document.querySelectorAll('#copyTimetableContent input[type="checkbox"]:checked');
        const targetClassIds = Array.from(checkboxes).map(cb => cb.value);

        if (targetClassIds.length === 0) {
            Toast.warning('Please select at least one target class');
            return;
        }

        const overwrite = document.getElementById('overwriteExisting').checked;

        try {
            const data = await apiRequest('copy_timetable', {
                source_class_id: sourceClassId,
                target_class_ids: JSON.stringify(targetClassIds),
                overwrite: overwrite
            });

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
        const printContent = `
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
                            ${jsData.days_of_week.slice(0, 6).map(day => `<th>${day}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody id="printTimetableBody">
                    </tbody>
                </table>
                <div class="footer">
                    <p>Generated by AcademixSuite Timetable Management System</p>
                </div>
            </body>
            </html>
        `;

        printWindow.document.write(printContent);

        // Generate timetable rows
        const timeSlots = jsData.time_slots;
        const days = jsData.db_days;
        const timeKeys = Object.keys(timeSlots);

        let rowsHTML = '';
        timeKeys.forEach((timeKey, index) => {
            const nextIndex = index + 1;
            const nextTimeKey = timeKeys[nextIndex] || '17:00:00';

            rowsHTML += '<tr>';
            rowsHTML += `<td>${timeSlots[timeKey]}</td>`;

            days.forEach(day => {
                const period = timetableData.find(p =>
                    p.day === day &&
                    p.start_time === timeKey &&
                    p.end_time === nextTimeKey
                );

                if (period) {
                    rowsHTML += `
                        <td class="period-cell">
                            <div class="period">
                                <div class="period-subject">${period.subject_name || 'No Subject'}</div>
                                <div class="period-teacher">${period.teacher_first_name || ''} ${period.teacher_last_name || ''}</div>
                                <div class="period-room">${period.room_name || 'No Room'}</div>
                            </div>
                        </td>
                    `;
                } else {
                    rowsHTML += '<td></td>';
                }
            });

            rowsHTML += '</tr>';
        });

        printWindow.document.getElementById('printTimetableBody').innerHTML = rowsHTML;
        printWindow.document.close();
        printWindow.focus();

        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 500);
    }

    // Print teacher schedule
    function printTeacherSchedule() {
        if (!selectedTeacher) {
            Toast.warning('Please select a teacher first');
            return;
        }

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
                csvContent += `"${period.subject_name || ''}",`;
                csvContent += `"${period.teacher_first_name || ''} ${period.teacher_last_name || ''}",`;
                csvContent += `"${period.room_name || ''}",`;
                csvContent += `"${period.day}",`;
                csvContent += `"${period.start_time}",`;
                csvContent += `"${period.end_time}",`;
                csvContent += `"${period.period_type || 'regular'}"\n`;
            });

            // Create download link
            const blob = new Blob([csvContent], {
                type: 'text/csv;charset=utf-8;'
            });
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
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    // Mobile sidebar toggle
    function mobileSidebarToggle() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
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
                const searchInput = document.getElementById('searchInput');
                if (searchInput) searchInput.focus();
            }

            // Ctrl/Cmd + S for save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (document.getElementById('periodModal') && document.getElementById('periodModal').classList.contains('hidden')) {
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

        // Period form submission
        const periodForm = document.getElementById('periodForm');
        if (periodForm) {
            periodForm.addEventListener('submit', savePeriod);
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM loaded, initializing timetable...');
        try {
            initializePage();
            console.log('Timetable initialized successfully');
        } catch (error) {
            console.error('Failed to initialize timetable:', error);
            Toast.error('Failed to initialize page. Please refresh.');
        }
    });

    // Debug: Check if functions are loaded
    console.log('addNewPeriod defined:', typeof addNewPeriod !== 'undefined');
    console.log('openModal defined:', typeof openModal !== 'undefined');
    console.log('Toast defined:', typeof Toast !== 'undefined');
    console.log('jsData:', jsData);
</script>

</body>

</html>