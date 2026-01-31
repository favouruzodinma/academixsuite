<?php
/**
 * Timetable API Endpoint
 * Fetches teachers, subjects, and classes for timetable management
 * Accessible via: /academixsuite/tenant/api/timetable_api.php
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/api_timetable.log');

// Set session cookie parameters for cross-page access
session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/academixsuite',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header with CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Debug logging
error_log("=== TIMETABLE API REQUEST ===");
error_log("Session ID: " . session_id());
error_log("Session Data: " . print_r($_SESSION, true));
error_log("Request URI: " . $_SERVER['REQUEST_URI']);
error_log("GET Params: " . print_r($_GET, true));
error_log("POST Params: " . print_r($_POST, true));

// Database configuration
require_once __DIR__ . '/../../includes/autoload.php';

// Response helper function
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    error_log("API Response: " . json_encode($data));
    exit();
}

// Error response helper
function jsonError($message, $status = 400) {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// Authentication check
function authenticateRequest() {
    error_log("Checking authentication...");
    
    // Check if school auth exists in session
    if (!isset($_SESSION['school_auth'])) {
        error_log("No school_auth in session");
        jsonError('Authentication required. Please log in again.', 401);
    }
    
    // Get school slug from request
    $schoolSlug = $_GET['school_slug'] ?? $_POST['school_slug'] ?? '';
    
    if (empty($schoolSlug)) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $schoolSlug = $data['school_slug'] ?? '';
    }
    
    if (empty($schoolSlug)) {
        jsonError('School slug parameter is required', 400);
    }
    
    $schoolAuth = $_SESSION['school_auth'];
    error_log("Session school_slug: " . ($schoolAuth['school_slug'] ?? 'none'));
    error_log("Request school_slug: " . $schoolSlug);
    
    // Check if user has access to this school
    if (($schoolAuth['school_slug'] ?? '') !== $schoolSlug) {
        jsonError('Unauthorized access to this school. Session mismatch.', 403);
    }
    
    // Check if user is admin
    if (($schoolAuth['user_type'] ?? '') !== 'admin') {
        jsonError('Admin privileges required', 403);
    }
    
    // Get school info
    $schoolInfo = $_SESSION['school_info'][$schoolSlug] ?? [];
    if (empty($schoolInfo)) {
        jsonError('School information not found', 404);
    }
    
    return [
        'school_id' => $schoolInfo['id'] ?? 0,
        'school_slug' => $schoolSlug,
        'school_info' => $schoolInfo,
        'user_id' => $schoolAuth['user_id'] ?? 0,
        'user_type' => $schoolAuth['user_type'] ?? ''
    ];
}


// Main API logic
try {
    // Get request method
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'GET' && $method !== 'POST') {
        jsonError('Method not allowed', 405);
    }
    
    // Get school slug and action
    $schoolSlug = $_GET['school_slug'] ?? $_POST['school_slug'] ?? '';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // If still empty, try JSON input
    if (empty($schoolSlug) || empty($action)) {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $data = json_decode($input, true);
            if (empty($schoolSlug)) $schoolSlug = $data['school_slug'] ?? '';
            if (empty($action)) $action = $data['action'] ?? '';
        }
    }
    
    if (empty($schoolSlug)) {
        jsonError('School slug parameter is required', 400);
    }
    
    if (empty($action)) {
        jsonError('Action parameter is required', 400);
    }
    
    // Authenticate request
    $auth = authenticateRequest();
    
    // Connect to school database
    $db = Database::getSchoolConnection($auth['school_info']['database_name']);
    if (!$db) {
        jsonError('Failed to connect to school database', 500);
    }
    
    // Handle different actions
    error_log("Processing action: " . $action);
    switch ($action) {
        case 'get_teachers':
            getTeachers($db, $auth['school_id']);
            break;
            
        case 'get_subjects':
            getSubjects($db, $auth['school_id']);
            break;
            
        case 'get_classes':
            getClasses($db, $auth['school_id']);
            break;
            
        case 'get_class_sections':
            getClassSections($db, $auth['school_id']);
            break;
            
        case 'get_grade_levels':
            getGradeLevels($db, $auth['school_id']);
            break;
            
        case 'get_teacher_by_id':
            getTeacherById($db, $auth['school_id']);
            break;
            
        case 'search_teachers':
            searchTeachers($db, $auth['school_id'], $searchTerm = '');
            break;
            
        case 'search_subjects':
            searchSubjects($db, $auth['school_id'], $searchTerm = '');
            break;
            
        case 'get_available_teachers':
            getAvailableTeachers($db, $auth['school_id']);
            break;
            
        case 'get_teacher_load':
            getTeacherLoad($db, $auth['school_id']);
            break;
            
        case 'get_class_details':
            getClassDetails($db, $auth['school_id']);
            break;
            
        case 'get_timetable':
            getTimetable($db, $auth['school_id']);
            break;
            
        case 'get_period':
            getPeriod($db, $auth['school_id']);
            break;
            
        case 'save_period':
            savePeriod($db, $auth['school_id']);
            break;
            
        case 'delete_period':
            deletePeriod($db, $auth['school_id']);
            break;
            
        case 'copy_timetable':
            copyTimetable($db, $auth['school_id']);
            break;
            
        case 'generate_timetable':
            generateTimetable($db, $auth['school_id']);
            break;
            
        default:
            jsonError('Invalid action: ' . $action, 400);
    }
    
} catch (Exception $e) {
    error_log("Timetable API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonError('Internal server error: ' . $e->getMessage(), 500);
}

// Function to get all teachers - UPDATED FOR YOUR SCHEMA
function getTeachers($db, $schoolId) {
    try {
        $params = [$schoolId];
        
        $sql = "
            SELECT 
                u.id,
                u.name as full_name,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.profile_photo as profile_image,
                u.is_active,
                t.employee_id,
                t.qualification,
                t.specialization,
                t.experience_years,
                COALESCE(t.max_weekly_periods, 30) as max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods,
                t.subjects_taught
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.user_type = 'teacher'
            AND u.is_active = 1
            ORDER BY u.name
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response
        $formattedTeachers = [];
        foreach ($teachers as $teacher) {
            // Parse first and last name from full name
            $nameParts = explode(' ', $teacher['full_name']);
            $firstName = $teacher['first_name'] ?? $nameParts[0] ?? '';
            $lastName = $teacher['last_name'] ?? (count($nameParts) > 1 ? end($nameParts) : '');
            
            $maxPeriods = $teacher['max_weekly_periods'] ?? 30;
            $currentPeriods = $teacher['current_weekly_periods'] ?? 0;
            $remaining = $maxPeriods - $currentPeriods;
            
            $formattedTeachers[] = [
                'id' => $teacher['id'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $teacher['full_name'],
                'email' => $teacher['email'],
                'phone' => $teacher['phone'],
                'employee_id' => $teacher['employee_id'],
                'specialization' => $teacher['specialization'],
                'qualification' => $teacher['qualification'],
                'experience_years' => $teacher['experience_years'],
                'subjects_taught' => $teacher['subjects_taught'],
                'max_weekly_periods' => $maxPeriods,
                'current_weekly_periods' => $currentPeriods,
                'availability' => [
                    'remaining_periods' => $remaining,
                    'utilization_percentage' => $maxPeriods > 0 ? 
                        round(($currentPeriods / $maxPeriods) * 100, 2) : 0
                ]
            ];
        }
        
        jsonResponse([
            'success' => true,
            'teachers' => $formattedTeachers,
            'count' => count($formattedTeachers),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch teachers: ' . $e->getMessage());
    }
}

// Function to get all subjects - UPDATED FOR YOUR SCHEMA
function getSubjects($db, $schoolId) {
    try {
        $params = [$schoolId];
        
        $sql = "
            SELECT 
                id,
                name,
                code,
                type,
                credit_hours,
                description,
                is_active,
                created_at
            FROM subjects
            WHERE school_id = ? 
            AND is_active = 1
            ORDER BY 
                type,
                name ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response
        $formattedSubjects = [];
        foreach ($subjects as $subject) {
            $formattedSubjects[] = [
                'id' => $subject['id'],
                'name' => $subject['name'],
                'code' => $subject['code'],
                'type' => $subject['type'] ?? 'core',
                'credit_hours' => $subject['credit_hours'] ?? 1.0,
                'description' => $subject['description'],
                'is_compulsory' => ($subject['type'] ?? 'core') === 'core',
                'is_active' => (bool)$subject['is_active'],
                'created_at' => $subject['created_at']
            ];
        }
        
        jsonResponse([
            'success' => true,
            'subjects' => $formattedSubjects,
            'count' => count($formattedSubjects),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch subjects: ' . $e->getMessage());
    }
}

// Function to get all classes - UPDATED FOR YOUR SCHEMA
function getClasses($db, $schoolId) {
    try {
        // Get current academic year
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;
        
        $params = [$schoolId, $academicYearId];
        
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
                c.is_active,
                c.created_at,
                u.name as class_teacher_name
            FROM classes c
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.school_id = ? 
            AND c.academic_year_id = ?
            AND c.is_active = 1
            ORDER BY 
                CAST(c.grade_level AS UNSIGNED) ASC,
                c.section ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get student counts if not already present
        foreach ($classes as &$class) {
            if ($class['current_students'] === null) {
                $countSql = "
                    SELECT COUNT(*) as student_count 
                    FROM students 
                    WHERE school_id = ? 
                    AND class_id = ? 
                    AND status = 'active'
                ";
                $countStmt = $db->prepare($countSql);
                $countStmt->execute([$schoolId, $class['id']]);
                $result = $countStmt->fetch(PDO::FETCH_ASSOC);
                $class['current_students'] = $result['student_count'] ?? 0;
            }
        }
        
        // Format response
        $formattedClasses = [];
        foreach ($classes as $class) {
            $capacity = $class['capacity'] ?? 40;
            $currentStudents = $class['current_students'] ?? 0;
            
            $formattedClasses[] = [
                'id' => $class['id'],
                'name' => $class['name'],
                'code' => $class['code'],
                'grade_level' => $class['grade_level'],
                'section' => $class['section'],
                'display_name' => "Grade {$class['grade_level']} - Section {$class['section']}",
                'capacity' => $capacity,
                'current_students' => $currentStudents,
                'room_number' => $class['room_number'],
                'class_teacher_id' => $class['class_teacher_id'],
                'class_teacher_name' => $class['class_teacher_name'] ?? 'Not Assigned',
                'is_active' => (bool)$class['is_active'],
                'availability' => [
                    'seats_available' => $capacity - $currentStudents,
                    'occupancy_percentage' => $capacity > 0 ? 
                        round(($currentStudents / $capacity) * 100, 2) : 0
                ]
            ];
        }
        
        jsonResponse([
            'success' => true,
            'classes' => $formattedClasses,
            'count' => count($formattedClasses),
            'academic_year_id' => $academicYearId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch classes: ' . $e->getMessage());
    }
}


// Function to get timetable
function getTimetable($db, $schoolId) {
    try {
        $grade = $_GET['grade'] ?? $_POST['grade'] ?? '';
        $section = $_GET['section'] ?? $_POST['section'] ?? '';
        $day = $_GET['day'] ?? $_POST['day'] ?? '';
        
        // Build WHERE clause
        $whereClauses = ["t.school_id = ?"];
        $params = [$schoolId];
        
        if (!empty($grade)) {
            $whereClauses[] = "c.grade_level = ?";
            $params[] = $grade;
        }
        
        if (!empty($section)) {
            $whereClauses[] = "c.section = ?";
            $params[] = $section;
        }
        
        if (!empty($day)) {
            $whereClauses[] = "t.day = ?";
            $params[] = strtolower($day);
        }
        
        $whereSql = implode(' AND ', $whereClauses);
        
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
        
        jsonResponse([
            'success' => true,
            'timetable' => $timetable,
            'count' => count($timetable),
            'filters' => [
                'grade' => $grade,
                'section' => $section,
                'day' => $day
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch timetable: ' . $e->getMessage());
    }
}

// Function to get single period
function getPeriod($db, $schoolId) {
    try {
        $periodId = $_GET['period_id'] ?? $_POST['period_id'] ?? '';
        
        if (empty($periodId)) {
            jsonError('Period ID is required');
        }
        
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
            jsonError('Period not found', 404);
        }
        
        jsonResponse([
            'success' => true,
            'period' => $period,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch period: ' . $e->getMessage());
    }
}

// Function to save period
function savePeriod($db, $schoolId) {
    try {
        $periodId = $_POST['period_id'] ?? null;
        $periodData = isset($_POST['period_data']) ? 
            json_decode($_POST['period_data'], true) : [];
        
        if (empty($periodData)) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $periodId = $data['period_id'] ?? null;
            $periodData = $data['period_data'] ?? [];
        }
        
        if (empty($periodData)) {
            jsonError('Invalid period data');
        }
        
        // Validate required fields
        $requiredFields = ['class_id', 'subject_id', 'teacher_id', 'day', 'start_time', 'end_time'];
        foreach ($requiredFields as $field) {
            if (empty($periodData[$field])) {
                jsonError("Missing required field: $field");
            }
        }
        
        // Get current academic year and term
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;
        
        $termSql = "SELECT id FROM academic_terms WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $termStmt = $db->prepare($termSql);
        $termStmt->execute([$schoolId]);
        $academicTerm = $termStmt->fetch(PDO::FETCH_ASSOC);
        $academicTermId = $academicTerm['id'] ?? 1;
        
        // Check for conflicts
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
            jsonError('Teacher has conflicting schedule');
        }
        
        // Calculate period number based on start time
        $periodNumber = calculatePeriodNumber($periodData['start_time']);
        $isBreak = ($periodData['period_type'] ?? 'regular') === 'break' ? 1 : 0;
        
        if ($periodId) {
            // Update existing period
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
            // Insert new period
            $sql = "
                INSERT INTO timetables (
                    school_id, class_id, subject_id, teacher_id,
                    room_number, day, start_time, end_time,
                    period_number, is_break,
                    academic_year_id, academic_term_id,
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ";
            
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
                $academicYearId,
                $academicTermId
            ];
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $periodId = $db->lastInsertId();
            
            // Update teacher's current weekly periods
            updateTeacherPeriods($db, $periodData['teacher_id'], $schoolId, 1);
            
            $message = 'Period added successfully';
        }
        
        jsonResponse([
            'success' => true,
            'message' => $message,
            'period_id' => $periodId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to save period: ' . $e->getMessage());
    }
}

// Helper function to calculate period number
function calculatePeriodNumber($startTime) {
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

// Helper function to update teacher periods
function updateTeacherPeriods($db, $teacherId, $schoolId, $increment = 1) {
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

// Function to delete period
function deletePeriod($db, $schoolId) {
    try {
        $periodId = $_POST['period_id'] ?? $_GET['period_id'] ?? '';
        
        if (empty($periodId)) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            $periodId = $data['period_id'] ?? '';
        }
        
        if (empty($periodId)) {
            jsonError('Period ID is required');
        }
        
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
        
        jsonResponse([
            'success' => true,
            'message' => 'Period deleted successfully',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to delete period: ' . $e->getMessage());
    }
}


// Function to get teacher workload - UPDATED FOR YOUR SCHEMA
function getTeacherLoad($db, $schoolId) {
    try {
        $teacherId = $_GET['teacher_id'] ?? '';
        if (empty($teacherId)) {
            jsonError('Teacher ID parameter is required');
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
                u.name as full_name,
                COALESCE(t.max_weekly_periods, 30) as max_weekly_periods,
                COALESCE(t.current_weekly_periods, 0) as current_weekly_periods
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.id = ?
        ";
        
        $teacherStmt = $db->prepare($teacherSql);
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$teacher) {
            jsonError('Teacher not found');
        }
        
        // Calculate workload statistics from timetable
        $totalPeriods = array_sum(array_column($schedule, 'periods_per_day'));
        $maxPeriods = $teacher['max_weekly_periods'] ?? 30;
        $utilizationPercentage = $maxPeriods > 0 ? round(($totalPeriods / $maxPeriods) * 100, 2) : 0;
        
        jsonResponse([
            'success' => true,
            'teacher' => [
                'id' => $teacherId,
                'full_name' => $teacher['full_name'],
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
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get teacher workload: ' . $e->getMessage());
    }
}

// Debug function to check database structure
function debugDatabase($db) {
    try {
        // Check teachers table structure
        $sql = "DESCRIBE teachers";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'teachers_table_structure' => $structure,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Debug failed: ' . $e->getMessage());
    }
}

// Add to the beginning of your API
error_log("Session ID: " . session_id());
error_log("School Auth: " . print_r($_SESSION['school_auth'] ?? 'Not set', true));
error_log("School Info: " . print_r($_SESSION['school_info'] ?? 'Not set', true));

// Function to copy timetable
function copyTimetable($db, $schoolId) {
    try {
        $sourceClassId = $_POST['source_class_id'] ?? '';
        $targetClassIds = isset($_POST['target_class_ids']) ? 
            json_decode($_POST['target_class_ids'], true) : [];
        $overwrite = $_POST['overwrite'] ?? false;
        
        if (empty($sourceClassId) || empty($targetClassIds)) {
            jsonError('Source class and target classes are required');
        }
        
        // Get source class timetable
        $sourceSql = "SELECT * FROM timetables WHERE class_id = ? AND school_id = ?";
        $sourceStmt = $db->prepare($sourceSql);
        $sourceStmt->execute([$sourceClassId, $schoolId]);
        $sourcePeriods = $sourceStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($sourcePeriods)) {
            jsonError('No timetable found for source class');
        }
        
        $copiedCount = 0;
        $errors = [];
        
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
                
                try {
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
                        $period['academic_year_id'] ?? 1,
                        $period['academic_term_id'] ?? 1
                    ]);
                    
                    // Update teacher's current weekly periods
                    updateTeacherPeriods($db, $period['teacher_id'], $schoolId, 1);
                    
                    $copiedCount++;
                } catch (Exception $e) {
                    $errors[] = "Failed to copy to class $targetClassId: " . $e->getMessage();
                }
            }
        }
        
        $response = [
            'success' => true,
            'message' => "Timetable copied to " . count($targetClassIds) . " class(es)",
            'copied' => $copiedCount,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!empty($errors)) {
            $response['warnings'] = $errors;
        }
        
        jsonResponse($response);
        
    } catch (Exception $e) {
        jsonError('Failed to copy timetable: ' . $e->getMessage());
    }
}

// Function to generate timetable
function generateTimetable($db, $schoolId) {
    try {
        $grade = $_POST['grade'] ?? $_GET['grade'] ?? '';
        
        // This would contain complex logic to auto-generate timetable
        // For now, return success message
        jsonResponse([
            'success' => true,
            'message' => 'Timetable generation feature coming soon',
            'generated' => 0,
            'grade' => $grade,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to generate timetable: ' . $e->getMessage());
    }
}

// ... [Include other existing functions from your API] ...

?>