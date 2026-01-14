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

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
session_start();

// Database configuration
require_once __DIR__ . '/../../includes/database.php';

// Response helper function
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// Error response helper
function jsonError($message, $status = 400) {
    jsonResponse(['error' => $message], $status);
}

// Authentication check
function authenticateRequest() {
    if (!isset($_SESSION['school_auth']) || !isset($_SESSION['school_info'])) {
        jsonError('Authentication required', 401);
    }
    
    $schoolSlug = $_GET['school_slug'] ?? '';
    if (empty($schoolSlug)) {
        jsonError('School slug required', 400);
    }
    
    // Check if user has access to this school
    $schoolAuth = $_SESSION['school_auth'];
    $schoolInfo = $_SESSION['school_info'];
    
    if ($schoolAuth['school_slug'] !== $schoolSlug) {
        jsonError('Unauthorized access to this school', 403);
    }
    
    return [
        'school_id' => $schoolAuth['school_id'] ?? 0,
        'school_slug' => $schoolSlug,
        'school_info' => $schoolInfo[$schoolSlug] ?? [],
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
    
    // Get school slug from request
    $schoolSlug = $_GET['school_slug'] ?? '';
    if (empty($schoolSlug)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $schoolSlug = $input['school_slug'] ?? '';
    }
    
    if (empty($schoolSlug)) {
        jsonError('School slug parameter is required', 400);
    }
    
    // Authenticate request
    $auth = authenticateRequest();
    
    // Connect to school database
    $db = Database::getSchoolConnection($auth['school_info']['database_name']);
    if (!$db) {
        jsonError('Failed to connect to school database', 500);
    }
    
    // Get action from request
    $action = $_GET['action'] ?? '';
    if (empty($action)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    }
    
    if (empty($action)) {
        jsonError('Action parameter is required', 400);
    }
    
    // Handle different actions
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
            searchTeachers($db, $auth['school_id']);
            break;
            
        case 'search_subjects':
            searchSubjects($db, $auth['school_id']);
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
            
        case 'get_class_teachers':
            getClassTeachers($db, $auth['school_id']);
            break;
            
        case 'get_class_subjects':
            getClassSubjects($db, $auth['school_id']);
            break;
            
        default:
            jsonError('Invalid action', 400);
    }
    
} catch (Exception $e) {
    error_log("Timetable API Error: " . $e->getMessage());
    jsonError('Internal server error: ' . $e->getMessage(), 500);
}

// Function to get all teachers
function getTeachers($db, $schoolId) {
    try {
        $params = [$schoolId];
        
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.profile_image,
                u.is_active,
                t.specialization,
                t.qualification,
                t.experience_years,
                t.subjects_taught,
                t.max_weekly_periods,
                t.current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.user_type = 'teacher'
            AND u.is_active = 1
            ORDER BY u.first_name, u.last_name
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response
        $formattedTeachers = [];
        foreach ($teachers as $teacher) {
            $formattedTeachers[] = [
                'id' => $teacher['id'],
                'first_name' => $teacher['first_name'],
                'last_name' => $teacher['last_name'],
                'full_name' => $teacher['full_name'],
                'email' => $teacher['email'],
                'phone' => $teacher['phone'],
                'specialization' => $teacher['specialization'],
                'qualification' => $teacher['qualification'],
                'experience_years' => $teacher['experience_years'],
                'subjects_taught' => $teacher['subjects_taught'],
                'max_weekly_periods' => $teacher['max_weekly_periods'] ?? 30,
                'current_weekly_periods' => $teacher['current_weekly_periods'] ?? 0,
                'availability' => [
                    'remaining_periods' => ($teacher['max_weekly_periods'] ?? 30) - ($teacher['current_weekly_periods'] ?? 0),
                    'utilization_percentage' => $teacher['current_weekly_periods'] > 0 ? 
                        round(($teacher['current_weekly_periods'] / ($teacher['max_weekly_periods'] ?? 30)) * 100, 2) : 0
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

// Function to get all subjects
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
                is_compulsory,
                is_active,
                created_at
            FROM subjects
            WHERE school_id = ? 
            AND is_active = 1
            ORDER BY 
                is_compulsory DESC,
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
                'type' => $subject['type'],
                'credit_hours' => $subject['credit_hours'],
                'description' => $subject['description'],
                'is_compulsory' => (bool)$subject['is_compulsory'],
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

// Function to get all classes
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
                id,
                name,
                code,
                grade_level,
                section,
                capacity,
                current_students,
                room_number,
                class_teacher_id,
                is_active,
                created_at
            FROM classes
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND is_active = 1
            ORDER BY 
                grade_level ASC,
                section ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get class teachers' names
        $classIds = array_column($classes, 'id');
        $classTeachers = [];
        
        if (!empty($classIds)) {
            $placeholders = str_repeat('?,', count($classIds) - 1) . '?';
            $teacherSql = "
                SELECT 
                    c.id as class_id,
                    CONCAT(u.first_name, ' ', u.last_name) as teacher_name
                FROM classes c
                LEFT JOIN users u ON c.class_teacher_id = u.id
                WHERE c.id IN ($placeholders)
            ";
            
            $teacherStmt = $db->prepare($teacherSql);
            $teacherStmt->execute($classIds);
            $teacherResults = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($teacherResults as $result) {
                $classTeachers[$result['class_id']] = $result['teacher_name'];
            }
        }
        
        // Format response
        $formattedClasses = [];
        foreach ($classes as $class) {
            $formattedClasses[] = [
                'id' => $class['id'],
                'name' => $class['name'],
                'code' => $class['code'],
                'grade_level' => $class['grade_level'],
                'section' => $class['section'],
                'display_name' => "Grade {$class['grade_level']} - Section {$class['section']}",
                'capacity' => $class['capacity'],
                'current_students' => $class['current_students'],
                'room_number' => $class['room_number'],
                'class_teacher_id' => $class['class_teacher_id'],
                'class_teacher_name' => $classTeachers[$class['id']] ?? 'Not Assigned',
                'is_active' => (bool)$class['is_active'],
                'availability' => [
                    'seats_available' => $class['capacity'] - $class['current_students'],
                    'occupancy_percentage' => $class['capacity'] > 0 ? 
                        round(($class['current_students'] / $class['capacity']) * 100, 2) : 0
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

// Function to get class sections by grade level
function getClassSections($db, $schoolId) {
    try {
        $gradeLevel = $_GET['grade_level'] ?? '';
        if (empty($gradeLevel)) {
            jsonError('Grade level parameter is required');
        }
        
        // Get current academic year
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;
        
        $params = [$schoolId, $academicYearId, $gradeLevel];
        
        $sql = "
            SELECT 
                id,
                section,
                name,
                capacity
            FROM classes
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND grade_level = ?
            AND is_active = 1
            ORDER BY section ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'sections' => $sections,
            'count' => count($sections),
            'grade_level' => $gradeLevel,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch class sections: ' . $e->getMessage());
    }
}

// Function to get all grade levels
function getGradeLevels($db, $schoolId) {
    try {
        // Get current academic year
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;
        
        $params = [$schoolId, $academicYearId];
        
        $sql = "
            SELECT DISTINCT grade_level
            FROM classes
            WHERE school_id = ? 
            AND academic_year_id = ?
            AND is_active = 1
            ORDER BY grade_level ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $gradeLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'grade_levels' => array_column($gradeLevels, 'grade_level'),
            'count' => count($gradeLevels),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch grade levels: ' . $e->getMessage());
    }
}

// Function to get teacher by ID
function getTeacherById($db, $schoolId) {
    try {
        $teacherId = $_GET['teacher_id'] ?? '';
        if (empty($teacherId)) {
            jsonError('Teacher ID parameter is required');
        }
        
        $params = [$schoolId, $teacherId];
        
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                u.profile_image,
                u.is_active,
                t.specialization,
                t.qualification,
                t.experience_years,
                t.subjects_taught,
                t.max_weekly_periods,
                t.current_weekly_periods,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.id = ?
            AND u.user_type = 'teacher'
            AND u.is_active = 1
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$teacher) {
            jsonError('Teacher not found', 404);
        }
        
        // Get teacher's current schedule
        $scheduleSql = "
            SELECT 
                COUNT(*) as total_periods,
                GROUP_CONCAT(DISTINCT day) as days_working,
                GROUP_CONCAT(DISTINCT subject_id) as subject_ids
            FROM timetables
            WHERE school_id = ? 
            AND teacher_id = ?
        ";
        
        $scheduleStmt = $db->prepare($scheduleSql);
        $scheduleStmt->execute([$schoolId, $teacherId]);
        $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get subject names
        $subjectNames = [];
        if (!empty($schedule['subject_ids'])) {
            $subjectIds = explode(',', $schedule['subject_ids']);
            $subjectPlaceholders = str_repeat('?,', count($subjectIds) - 1) . '?';
            
            $subjectSql = "
                SELECT name 
                FROM subjects 
                WHERE id IN ($subjectPlaceholders)
            ";
            
            $subjectStmt = $db->prepare($subjectSql);
            $subjectStmt->execute($subjectIds);
            $subjectNames = $subjectStmt->fetchAll(PDO::FETCH_COLUMN, 0);
        }
        
        jsonResponse([
            'success' => true,
            'teacher' => [
                'id' => $teacher['id'],
                'first_name' => $teacher['first_name'],
                'last_name' => $teacher['last_name'],
                'full_name' => $teacher['full_name'],
                'email' => $teacher['email'],
                'phone' => $teacher['phone'],
                'specialization' => $teacher['specialization'],
                'qualification' => $teacher['qualification'],
                'experience_years' => $teacher['experience_years'],
                'subjects_taught' => $teacher['subjects_taught'],
                'max_weekly_periods' => $teacher['max_weekly_periods'] ?? 30,
                'current_weekly_periods' => $teacher['current_weekly_periods'] ?? 0,
                'availability' => [
                    'remaining_periods' => ($teacher['max_weekly_periods'] ?? 30) - ($teacher['current_weekly_periods'] ?? 0),
                    'utilization_percentage' => $teacher['current_weekly_periods'] > 0 ? 
                        round(($teacher['current_weekly_periods'] / ($teacher['max_weekly_periods'] ?? 30)) * 100, 2) : 0,
                    'total_periods_scheduled' => $schedule['total_periods'] ?? 0,
                    'days_working' => $schedule['days_working'] ? explode(',', $schedule['days_working']) : [],
                    'current_subjects' => $subjectNames
                ]
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to fetch teacher: ' . $e->getMessage());
    }
}

// Function to search teachers
function searchTeachers($db, $schoolId) {
    try {
        $searchTerm = $_GET['search'] ?? '';
        if (empty($searchTerm)) {
            jsonError('Search term is required');
        }
        
        $params = [$schoolId];
        $searchParam = "%{$searchTerm}%";
        
        $sql = "
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone,
                t.specialization,
                CONCAT(u.first_name, ' ', u.last_name) as full_name
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.school_id = ? 
            AND u.user_type = 'teacher'
            AND u.is_active = 1
            AND (
                u.first_name LIKE ? OR
                u.last_name LIKE ? OR
                u.email LIKE ? OR
                t.specialization LIKE ?
            )
            ORDER BY u.first_name, u.last_name
            LIMIT 20
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]));
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'teachers' => $teachers,
            'count' => count($teachers),
            'search_term' => $searchTerm,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to search teachers: ' . $e->getMessage());
    }
}

// Function to search subjects
function searchSubjects($db, $schoolId) {
    try {
        $searchTerm = $_GET['search'] ?? '';
        if (empty($searchTerm)) {
            jsonError('Search term is required');
        }
        
        $params = [$schoolId];
        $searchParam = "%{$searchTerm}%";
        
        $sql = "
            SELECT 
                id,
                name,
                code,
                type,
                credit_hours
            FROM subjects
            WHERE school_id = ? 
            AND is_active = 1
            AND (
                name LIKE ? OR
                code LIKE ? OR
                type LIKE ?
            )
            ORDER BY name ASC
            LIMIT 20
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$searchParam, $searchParam, $searchParam]));
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'subjects' => $subjects,
            'count' => count($subjects),
            'search_term' => $searchTerm,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to search subjects: ' . $e->getMessage());
    }
}

// Function to get available teachers for a specific time slot
function getAvailableTeachers($db, $schoolId) {
    try {
        $day = $_GET['day'] ?? '';
        $startTime = $_GET['start_time'] ?? '';
        $endTime = $_GET['end_time'] ?? '';
        
        if (empty($day) || empty($startTime) || empty($endTime)) {
            jsonError('Day, start_time, and end_time parameters are required');
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
                t.current_weekly_periods,
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
            $schoolId, $day, 
            $startTime, $startTime,
            $endTime, $endTime,
            $startTime, $endTime
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
        
        jsonResponse([
            'success' => true,
            'available_teachers' => $availableTeachers,
            'count' => count($availableTeachers),
            'day' => $day,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get available teachers: ' . $e->getMessage());
    }
}

// Function to get teacher workload
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
                u.first_name,
                u.last_name,
                t.max_weekly_periods,
                t.current_weekly_periods
            FROM users u
            LEFT JOIN teachers t ON u.id = t.user_id
            WHERE u.id = ?
        ";
        
        $teacherStmt = $db->prepare($teacherSql);
        $teacherStmt->execute([$teacherId]);
        $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate workload statistics
        $totalPeriods = array_sum(array_column($schedule, 'periods_per_day'));
        $maxPeriods = $teacher['max_weekly_periods'] ?? 30;
        $utilizationPercentage = $maxPeriods > 0 ? round(($totalPeriods / $maxPeriods) * 100, 2) : 0;
        
        jsonResponse([
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
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get teacher workload: ' . $e->getMessage());
    }
}

// Function to get class details
function getClassDetails($db, $schoolId) {
    try {
        $classId = $_GET['class_id'] ?? '';
        if (empty($classId)) {
            jsonError('Class ID parameter is required');
        }
        
        // Get current academic year
        $yearSql = "SELECT id FROM academic_years WHERE school_id = ? AND is_default = 1 LIMIT 1";
        $yearStmt = $db->prepare($yearSql);
        $yearStmt->execute([$schoolId]);
        $academicYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $academicYearId = $academicYear['id'] ?? 1;
        
        $params = [$schoolId, $academicYearId, $classId];
        
        $sql = "
            SELECT 
                c.*,
                CONCAT(u.first_name, ' ', u.last_name) as class_teacher_name,
                u.email as class_teacher_email
            FROM classes c
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.school_id = ? 
            AND c.academic_year_id = ?
            AND c.id = ?
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$class) {
            jsonError('Class not found', 404);
        }
        
        // Get class timetable
        $timetableSql = "
            SELECT 
                COUNT(*) as total_periods,
                GROUP_CONCAT(DISTINCT day) as scheduled_days,
                GROUP_CONCAT(DISTINCT subject_id) as subject_ids,
                GROUP_CONCAT(DISTINCT teacher_id) as teacher_ids
            FROM timetables
            WHERE school_id = ? 
            AND class_id = ?
        ";
        
        $timetableStmt = $db->prepare($timetableSql);
        $timetableStmt->execute([$schoolId, $classId]);
        $timetable = $timetableStmt->fetch(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'class' => [
                'id' => $class['id'],
                'name' => $class['name'],
                'code' => $class['code'],
                'grade_level' => $class['grade_level'],
                'section' => $class['section'],
                'display_name' => "Grade {$class['grade_level']} - Section {$class['section']}",
                'capacity' => $class['capacity'],
                'current_students' => $class['current_students'],
                'room_number' => $class['room_number'],
                'class_teacher_id' => $class['class_teacher_id'],
                'class_teacher_name' => $class['class_teacher_name'],
                'class_teacher_email' => $class['class_teacher_email'],
                'is_active' => (bool)$class['is_active']
            ],
            'timetable_summary' => [
                'total_periods' => $timetable['total_periods'] ?? 0,
                'scheduled_days' => $timetable['scheduled_days'] ? explode(',', $timetable['scheduled_days']) : [],
                'total_subjects' => $timetable['subject_ids'] ? count(explode(',', $timetable['subject_ids'])) : 0,
                'total_teachers' => $timetable['teacher_ids'] ? count(explode(',', $timetable['teacher_ids'])) : 0
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get class details: ' . $e->getMessage());
    }
}

// Function to get class teachers
function getClassTeachers($db, $schoolId) {
    try {
        $classId = $_GET['class_id'] ?? '';
        if (empty($classId)) {
            jsonError('Class ID parameter is required');
        }
        
        $sql = "
            SELECT DISTINCT 
                t.teacher_id,
                u.first_name,
                u.last_name,
                u.email,
                sub.name as subject_name,
                COUNT(*) as periods_per_week
            FROM timetables t
            JOIN users u ON t.teacher_id = u.id
            JOIN subjects sub ON t.subject_id = sub.id
            WHERE t.school_id = ? 
            AND t.class_id = ?
            GROUP BY t.teacher_id, sub.name
            ORDER BY u.first_name, u.last_name
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, $classId]);
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'teachers' => $teachers,
            'count' => count($teachers),
            'class_id' => $classId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get class teachers: ' . $e->getMessage());
    }
}

// Function to get class subjects
function getClassSubjects($db, $schoolId) {
    try {
        $classId = $_GET['class_id'] ?? '';
        if (empty($classId)) {
            jsonError('Class ID parameter is required');
        }
        
        $sql = "
            SELECT DISTINCT 
                t.subject_id,
                s.name,
                s.code,
                s.type,
                s.credit_hours,
                COUNT(*) as periods_per_week,
                GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name)) as teachers
            FROM timetables t
            JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN users u ON t.teacher_id = u.id
            WHERE t.school_id = ? 
            AND t.class_id = ?
            GROUP BY t.subject_id
            ORDER BY s.name
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$schoolId, $classId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'success' => true,
            'subjects' => $subjects,
            'count' => count($subjects),
            'class_id' => $classId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        jsonError('Failed to get class subjects: ' . $e->getMessage());
    }
}