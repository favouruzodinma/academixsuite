<?php
/**
 * Edit Class Page
 * Allows admin to edit class details, manage sections, view students and timetable,
 * and assign teachers to subjects.
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_edit_class.log');

error_log("=== EDIT CLASS PAGE START ===");
error_log("Script: " . __FILE__);

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

/**
 * Initialize session safely
 */
function initializeSession() {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 86400,
                'read_and_close' => false,
                'cookie_secure' => !IS_LOCAL,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax'
            ]);
            error_log("Session started successfully");
        }
        return true;
    } catch (Exception $e) {
        error_log("Session initialization error: " . $e->getMessage());
        return false;
    }
}

// Start session
initializeSession();

/**
 * Get school slug from router
 */
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'edit-class.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

error_log("School Slug: " . $schoolSlug);

// Validate school slug
if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'School identifier missing']));
}

/**
 * Get school information
 */
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

// Redirect if school not found
if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

error_log("School ID: " . ($school['id'] ?? 'N/A'));
error_log("School Name: " . ($school['name'] ?? 'N/A'));

/**
 * Verify authentication
 */
$isAuthenticated = isset($_SESSION['school_auth']) && 
                   is_array($_SESSION['school_auth']) && 
                   ($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug;

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

error_log("Authenticated User ID: " . $userId);

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Admin privileges required.";
    exit;
}

/**
 * Load required files
 */
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    error_log("Loading autoload from: " . $autoloadPath);
    
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    require_once $autoloadPath;
    error_log("Autoload loaded successfully");
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    error_log("Database class found");
    
    // Include NotificationManager for notifications
    $notificationManagerPath = __DIR__ . '/../../../includes/NotificationManager.php';
    if (file_exists($notificationManagerPath)) {
        require_once $notificationManagerPath;
        error_log("NotificationManager loaded successfully");
    }
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR loading configuration: " . $e->getMessage());
    http_response_code(500);
    die("System configuration loading failed. Please try again later.");
}

/**
 * Connect to school database
 */
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        error_log("Attempting to connect to database: " . $school['database_name']);
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        if ($schoolDb) {
            error_log("School database connection successful");
        } else {
            throw new Exception("Database connection returned null");
        }
    } else {
        throw new Exception("No database name configured for school");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
    $_SESSION['toast_error'] = "Database connection failed. Please contact support.";
}

/**
 * Initialize notification variables
 */
$notificationCount = 0;
$notifications = [];

if ($schoolDb && class_exists('NotificationManager')) {
    try {
        $notificationManager = new NotificationManager($schoolDb, $school['id'], $userId, $userType, $school);
        $notificationCount = $notificationManager->getUnreadCount();
        $notifications = $notificationManager->getNotifications(5, false);
    } catch (Exception $e) {
        error_log("ERROR initializing NotificationManager: " . $e->getMessage());
    }
}

/**
 * Get class ID from URL
 */
$classId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$classId) {
    $_SESSION['toast_error'] = "No class selected.";
    header("Location: class-list.php");
    exit;
}

/**
 * Fetch class details and related data
 */
$class = null;
$sections = [];
$academicYears = [];
$allTeachers = [];
$availableTeachersForSections = []; // teachers not assigned elsewhere (for section teacher)
$students = [];
$subjects = [];
$timetable = [];

if ($schoolDb) {
    try {
        // Get all academic years for dropdown
        $yearStmt = $schoolDb->prepare("
            SELECT id, name FROM academic_years
            WHERE school_id = ? ORDER BY start_date DESC
        ");
        $yearStmt->execute([$school['id']]);
        $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get all active teachers
        $teacherStmt = $schoolDb->prepare("
            SELECT id, name, email FROM users
            WHERE school_id = ? AND user_type = 'teacher' AND is_active = 1
            ORDER BY name
        ");
        $teacherStmt->execute([$school['id']]);
        $allTeachers = $teacherStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get teachers already assigned as class teacher in other classes/sections (excluding current class for section teacher logic)
        $assignedStmt = $schoolDb->prepare("
            SELECT DISTINCT class_teacher_id FROM (
                SELECT class_teacher_id FROM classes WHERE school_id = ? AND is_active = 1 AND class_teacher_id IS NOT NULL AND id != ?
                UNION
                SELECT class_teacher_id FROM sections WHERE school_id = ? AND is_active = 1 AND class_teacher_id IS NOT NULL
            ) AS assigned
        ");
        $assignedStmt->execute([$school['id'], $classId, $school['id']]);
        $assignedTeacherIds = $assignedStmt->fetchAll(PDO::FETCH_COLUMN);

        // Available teachers for section teacher = all teachers not in assigned list
        $availableTeachersForSections = array_filter($allTeachers, function($t) use ($assignedTeacherIds) {
            return !in_array($t['id'], $assignedTeacherIds);
        });
        $availableTeachersForSections = array_values($availableTeachersForSections);

        // Get class details
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.id = ? AND c.school_id = ?
        ");
        $classStmt->execute([$classId, $school['id']]);
        $class = $classStmt->fetch(PDO::FETCH_ASSOC);
        if (!$class) {
            throw new Exception("Class not found");
        }

        // Get sections for this class
        $sectionStmt = $schoolDb->prepare("
            SELECT s.*, u.name as class_teacher_name,
                   COUNT(DISTINCT st.id) as student_count
            FROM sections s
            LEFT JOIN users u ON s.class_teacher_id = u.id
            LEFT JOIN students st ON s.id = st.section_id AND st.status = 'active'
            WHERE s.class_id = ? AND s.school_id = ?
            GROUP BY s.id
            ORDER BY s.name
        ");
        $sectionStmt->execute([$classId, $school['id']]);
        $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get students in this class
        $studentStmt = $schoolDb->prepare("
            SELECT s.*, u.name as guardian_name, sec.name as section_name
            FROM students s
            LEFT JOIN sections sec ON s.section_id = sec.id
            LEFT JOIN guardians g ON s.id = g.student_id AND g.is_primary = 1
            LEFT JOIN users u ON g.user_id = u.id
            WHERE sec.class_id = ? AND s.school_id = ? AND s.status = 'active'
            ORDER BY sec.name, s.first_name
        ");
        $studentStmt->execute([$classId, $school['id']]);
        $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get subjects for this class with teacher
        $subjectStmt = $schoolDb->prepare("
            SELECT cs.id as class_subject_id, s.*, u.name as teacher_name, cs.teacher_id
            FROM class_subjects cs
            JOIN subjects s ON cs.subject_id = s.id
            LEFT JOIN users u ON cs.teacher_id = u.id
            WHERE cs.class_id = ?
            ORDER BY s.name
        ");
        $subjectStmt->execute([$classId]);
        $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get timetable for this class
        $timetableStmt = $schoolDb->prepare("
            SELECT t.*, s.name as subject_name, u.name as teacher_name
            FROM timetables t
            LEFT JOIN subjects s ON t.subject_id = s.id
            LEFT JOIN users u ON t.teacher_id = u.id
            WHERE t.class_id = ? AND t.school_id = ?
            ORDER BY FIELD(t.day, 'monday','tuesday','wednesday','thursday','friday','saturday'), t.period_number
        ");
        $timetableStmt->execute([$classId, $school['id']]);
        $timetable = $timetableStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    } catch (Exception $e) {
        error_log("Error fetching class data: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading class data.";
        header("Location: class-list.php");
        exit;
    }
}

/**
 * Handle form submissions
 */
$message = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Validate CSRF token (using global functions from autoload)
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = "Invalid security token.";
    } else {
        try {
            if (!$schoolDb) {
                throw new Exception("Database connection not available");
            }
            
            switch ($action) {
                // Update class details
                case 'update_class':
                    if (empty($_POST['name']) || empty($_POST['code']) || empty($_POST['academic_year_id'])) {
                        throw new Exception("Class name, code, and academic year are required");
                    }
                    
                    // Check if code exists for another class
                    $checkStmt = $schoolDb->prepare("
                        SELECT id FROM classes
                        WHERE school_id = ? AND code = ? AND academic_year_id = ? AND id != ?
                    ");
                    $checkStmt->execute([$school['id'], $_POST['code'], $_POST['academic_year_id'], $classId]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Class code already exists for this academic year");
                    }
                    
                    $schoolDb->beginTransaction();
                    
                    $stmt = $schoolDb->prepare("
                        UPDATE classes SET
                            name = ?, code = ?, description = ?, grade_level = ?,
                            capacity = ?, room_number = ?, class_teacher_id = ?,
                            academic_year_id = ?, is_active = ?
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['code'],
                        $_POST['description'] ?? null,
                        $_POST['grade_level'] ?? null,
                        $_POST['capacity'] ?? 40,
                        $_POST['room_number'] ?? null,
                        !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                        $_POST['academic_year_id'],
                        isset($_POST['is_active']) ? 1 : 0,
                        $classId,
                        $school['id']
                    ]);
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, 'update', 'class', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $classId,
                        json_encode(['updated_fields' => array_keys($_POST)]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $schoolDb->commit();
                    $success = true;
                    $message = "Class updated successfully!";
                    
                    // Refresh class data
                    $classStmt->execute([$classId, $school['id']]);
                    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
                    break;
                
                // Add new section
                case 'add_section':
                    if (empty($_POST['name']) || empty($_POST['code'])) {
                        throw new Exception("Section name and code are required");
                    }
                    
                    // Check if code exists for this class
                    $checkStmt = $schoolDb->prepare("
                        SELECT id FROM sections
                        WHERE school_id = ? AND class_id = ? AND code = ?
                    ");
                    $checkStmt->execute([$school['id'], $classId, $_POST['code']]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Section code already exists for this class");
                    }
                    
                    $schoolDb->beginTransaction();
                    
                    $stmt = $schoolDb->prepare("
                        INSERT INTO sections (school_id, class_id, name, code, room_number, capacity, class_teacher_id, is_active, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $school['id'],
                        $classId,
                        $_POST['name'],
                        $_POST['code'],
                        $_POST['room_number'] ?? null,
                        $_POST['capacity'] ?? 40,
                        !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                        isset($_POST['is_active']) ? 1 : 1
                    ]);
                    
                    $sectionId = $schoolDb->lastInsertId();
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, 'create', 'section', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $sectionId,
                        json_encode(['name' => $_POST['name'], 'code' => $_POST['code']]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $schoolDb->commit();
                    $success = true;
                    $message = "Section added successfully!";
                    
                    // Refresh sections
                    $sectionStmt->execute([$classId, $school['id']]);
                    $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;
                
                // Edit section
                case 'edit_section':
                    if (empty($_POST['section_id']) || empty($_POST['name']) || empty($_POST['code'])) {
                        throw new Exception("Section ID, name, and code are required");
                    }
                    
                    // Check if code exists for another section in same class
                    $checkStmt = $schoolDb->prepare("
                        SELECT id FROM sections
                        WHERE school_id = ? AND class_id = ? AND code = ? AND id != ?
                    ");
                    $checkStmt->execute([$school['id'], $classId, $_POST['code'], $_POST['section_id']]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Section code already exists for this class");
                    }
                    
                    $schoolDb->beginTransaction();
                    
                    $stmt = $schoolDb->prepare("
                        UPDATE sections SET
                            name = ?, code = ?, room_number = ?, capacity = ?,
                            class_teacher_id = ?, is_active = ?
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['code'],
                        $_POST['room_number'] ?? null,
                        $_POST['capacity'] ?? 40,
                        !empty($_POST['class_teacher_id']) ? $_POST['class_teacher_id'] : null,
                        isset($_POST['is_active']) ? 1 : 0,
                        $_POST['section_id'],
                        $school['id']
                    ]);
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, 'update', 'section', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $_POST['section_id'],
                        json_encode(['updated_fields' => array_keys($_POST)]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $schoolDb->commit();
                    $success = true;
                    $message = "Section updated successfully!";
                    
                    // Refresh sections
                    $sectionStmt->execute([$classId, $school['id']]);
                    $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;
                
                // Delete section
                case 'delete_section':
                    if (empty($_POST['section_id'])) {
                        throw new Exception("Section ID is required");
                    }
                    
                    // Check if section has students
                    $studentCheck = $schoolDb->prepare("
                        SELECT COUNT(*) as count FROM students
                        WHERE section_id = ? AND status = 'active'
                    ");
                    $studentCheck->execute([$_POST['section_id']]);
                    $studentCount = $studentCheck->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;
                    
                    if ($studentCount > 0) {
                        throw new Exception("Cannot delete section with active students. Reassign students first.");
                    }
                    
                    $schoolDb->beginTransaction();
                    
                    // Get section data for audit
                    $getStmt = $schoolDb->prepare("SELECT name, code FROM sections WHERE id = ?");
                    $getStmt->execute([$_POST['section_id']]);
                    $sectionData = $getStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Soft delete
                    $stmt = $schoolDb->prepare("UPDATE sections SET is_active = 0 WHERE id = ? AND school_id = ?");
                    $stmt->execute([$_POST['section_id'], $school['id']]);
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, old_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, 'delete', 'section', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $_POST['section_id'],
                        json_encode($sectionData),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $schoolDb->commit();
                    $success = true;
                    $message = "Section deleted successfully!";
                    
                    // Refresh sections
                    $sectionStmt->execute([$classId, $school['id']]);
                    $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;
                
                // Update subject teacher
                case 'update_subject_teacher':
                    if (empty($_POST['class_subject_id']) || !isset($_POST['teacher_id'])) {
                        throw new Exception("Subject and teacher are required");
                    }
                    $teacherId = $_POST['teacher_id'] ?: null;
                    $stmt = $schoolDb->prepare("UPDATE class_subjects SET teacher_id = ? WHERE id = ? AND class_id = ?");
                    $stmt->execute([$teacherId, $_POST['class_subject_id'], $classId]);
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, 'update', 'class_subject', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $_POST['class_subject_id'],
                        json_encode(['teacher_id' => $teacherId]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $success = true;
                    $message = "Subject teacher updated successfully!";
                    
                    // Refresh subjects
                    $subjectStmt->execute([$classId]);
                    $subjects = $subjectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    break;
                
                // Suspend/activate class (toggle active)
                case 'toggle_class_status':
                    $newStatus = isset($_POST['activate']) ? 1 : 0;
                    $stmt = $schoolDb->prepare("UPDATE classes SET is_active = ? WHERE id = ? AND school_id = ?");
                    $stmt->execute([$newStatus, $classId, $school['id']]);
                    
                    // Audit log
                    $auditStmt = $schoolDb->prepare("
                        INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                        VALUES (?, ?, ?, ?, 'class', ?, ?, ?, ?, ?, NOW())
                    ");
                    $auditStmt->execute([
                        $school['id'], $userId, $userType, $newStatus ? 'activate' : 'suspend', $classId,
                        json_encode(['is_active' => $newStatus]),
                        $_SERVER['REMOTE_ADDR'] ?? null,
                        $_SERVER['HTTP_USER_AGENT'] ?? null,
                        $_SERVER['REQUEST_URI'] ?? null
                    ]);
                    
                    $success = true;
                    $message = $newStatus ? "Class activated successfully!" : "Class suspended successfully!";
                    
                    // Refresh class data
                    $classStmt->execute([$classId, $school['id']]);
                    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
                    break;
                
                default:
                    throw new Exception("Unknown action");
            }
        } catch (Exception $e) {
            if ($schoolDb && $schoolDb->inTransaction()) {
                $schoolDb->rollBack();
            }
            $error = $e->getMessage();
            error_log("Error processing action: " . $error);
        }
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? ($success ? $message : '');
$toastError = $_SESSION['toast_error'] ?? $error;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token (using global function)
$csrfToken = generateCsrfToken();

// Helper function for day format
function formatDay($day) {
    return ucfirst($day);
}

error_log("=== EDIT CLASS PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Edit Class - Manage class details, sections, students, subjects, and timetable">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Edit Class</title>
    
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
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .toast {
            min-width: 300px;
            background: white;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 10px;
            animation: slideIn 0.3s ease;
        }
        .toast.success {
            border-left-color: #28a745;
        }
        .toast.success .toast-header {
            background-color: #d4edda;
            color: #155724;
        }
        .toast.error {
            border-left-color: #dc3545;
        }
        .toast.error .toast-header {
            background-color: #f8d7da;
            color: #721c24;
        }
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .nav-tabs .nav-link {
            color: #495057;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #25A194;
            border-bottom: 2px solid #25A194;
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .action-btn {
            padding: 4px 8px;
            font-size: 12px;
            margin: 0 2px;
        }
        
        .timetable-table th {
            background: #25A194;
            color: white;
            text-align: center;
        }
        .timetable-table td {
            vertical-align: middle;
            text-align: center;
        }
        .break-row td {
            background: #f8d7da;
            color: #721c24;
            font-style: italic;
        }
        
        .danger-zone {
            background: #fff5f5;
            border: 1px solid #fcc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 30px;
        }
        
        .modal-header {
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer">
        <?php if (!empty($toastSuccess)): ?>
        <div class="toast success show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
            <div class="toast-header">
                <i class="ri-checkbox-circle-line me-2"></i>
                <strong class="me-auto">Success</strong>
                <small>just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <?php echo htmlspecialchars($toastSuccess); ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($toastError)): ?>
        <div class="toast error show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
            <div class="toast-header">
                <i class="ri-error-warning-line me-2"></i>
                <strong class="me-auto">Error</strong>
                <small>just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body">
                <?php echo htmlspecialchars($toastError); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Theme Customization Structure (same as other pages) -->
    
    

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php') ?>

    <main class="dashboard-main">
        <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Edit Class</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <a href="class-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Classes</a>
                        <span class="text-secondary-light"> / <?php echo htmlspecialchars($class['name']); ?></span>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="class-details.php?id=<?php echo $classId; ?>" class="btn btn-outline-primary">
                        <i class="ri-eye-line"></i> View Details
                    </a>
                    <a href="assign-subjects.php?class_id=<?php echo $classId; ?>" class="btn btn-outline-success">
                        <i class="ri-book-open-line"></i> Assign Subjects
                    </a>
                </div>
            </div>

            <!-- Class Edit Form -->
            <div class="card mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0"><i class="ri-edit-box-line me-2 text-primary"></i>Class Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_class">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($class['name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Class Code <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($class['code']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Academic Year <span class="text-danger">*</span></label>
                                <select name="academic_year_id" class="form-select" required>
                                    <option value="">Select Year</option>
                                    <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo $year['id'] == $class['academic_year_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Grade Level</label>
                                <input type="text" name="grade_level" class="form-control" value="<?php echo htmlspecialchars($class['grade_level'] ?? ''); ?>" placeholder="e.g., Primary, Secondary">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Capacity</label>
                                <input type="number" name="capacity" class="form-control" value="<?php echo $class['capacity'] ?? 40; ?>" min="1">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Room Number</label>
                                <input type="text" name="room_number" class="form-control" value="<?php echo htmlspecialchars($class['room_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Class Teacher</label>
                                <select name="class_teacher_id" class="form-select">
                                    <option value="">Select Teacher</option>
                                    <?php foreach ($allTeachers as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher['id'] == $class['class_teacher_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($class['description'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="classActive" value="1" <?php echo $class['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="classActive">Active Class</label>
                                </div>
                            </div>
                            <div class="col-12 mt-3">
                                <button type="submit" class="btn btn-primary-600 px-4">Update Class</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <ul class="nav nav-tabs mb-4" id="classTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="sections-tab" data-bs-toggle="tab" data-bs-target="#sections" type="button" role="tab">Sections</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="students-tab" data-bs-toggle="tab" data-bs-target="#students" type="button" role="tab">Students</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="subjects-tab" data-bs-toggle="tab" data-bs-target="#subjects" type="button" role="tab">Subjects</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="timetable-tab" data-bs-toggle="tab" data-bs-target="#timetable" type="button" role="tab">Timetable</button>
                </li>
            </ul>

            <div class="tab-content" id="classTabsContent">
                <!-- Sections Tab -->
                <div class="tab-pane fade show active" id="sections" role="tabpanel">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="ri-grid-line me-2 text-success"></i>Sections</h5>
                            <button type="button" class="btn btn-sm btn-primary-600" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                                <i class="ri-add-line"></i> Add Section
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($sections)): ?>
                                <p class="text-muted text-center py-3">No sections added yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Section</th>
                                                <th>Code</th>
                                                <th>Teacher</th>
                                                <th>Room</th>
                                                <th>Capacity</th>
                                                <th>Students</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sections as $section): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($section['name']); ?></td>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($section['code']); ?></span></td>
                                                <td><?php echo htmlspecialchars($section['class_teacher_name'] ?? 'Not Assigned'); ?></td>
                                                <td><?php echo htmlspecialchars($section['room_number'] ?? 'N/A'); ?></td>
                                                <td><?php echo $section['capacity'] ?? 40; ?></td>
                                                <td><?php echo $section['student_count'] ?? 0; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $section['is_active'] ? 'bg-success' : 'bg-danger'; ?>">
                                                        <?php echo $section['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary edit-section-btn" data-section='<?php echo json_encode($section); ?>'>
                                                        <i class="ri-edit-line"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger delete-section-btn" data-id="<?php echo $section['id']; ?>" data-name="<?php echo htmlspecialchars($section['name']); ?>">
                                                        <i class="ri-delete-bin-line"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Students Tab -->
                <div class="tab-pane fade" id="students" role="tabpanel">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="ri-group-line me-2 text-primary"></i>Students in this Class</h5>
                            <a href="add-new-student.php?class_id=<?php echo $classId; ?>" class="btn btn-sm btn-primary-600">
                                <i class="ri-add-line"></i> Add Student
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($students)): ?>
                                <p class="text-muted text-center py-3">No students enrolled in this class yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Photo</th>
                                                <th>Admission No.</th>
                                                <th>Name</th>
                                                <th>Section</th>
                                                <th>Guardian</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students as $student): ?>
                                            <tr>
                                                <td>
                                                    <img src="<?php echo htmlspecialchars($student['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/avatar-placeholder.png'); ?>" 
                                                         class="student-avatar" alt="Avatar">
                                                </td>
                                                <td><?php echo htmlspecialchars($student['admission_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['section_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($student['guardian_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge bg-success"><?php echo ucfirst($student['status']); ?></span>
                                                </td>
                                                <td>
                                                    <a href="student-details.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-info" title="View">
                                                        <i class="ri-eye-line"></i>
                                                    </a>
                                                    <a href="edit-student.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                        <i class="ri-edit-line"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Subjects Tab -->
                <div class="tab-pane fade" id="subjects" role="tabpanel">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="ri-book-open-line me-2 text-warning"></i>Subjects Offered</h5>
                            <a href="assign-subjects.php?class_id=<?php echo $classId; ?>" class="btn btn-sm btn-primary-600">
                                <i class="ri-add-line"></i> Assign Subjects
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($subjects)): ?>
                                <p class="text-muted text-center py-3">No subjects assigned to this class yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th>Code</th>
                                                <th>Type</th>
                                                <th>Teacher</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($subjects as $subject): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($subject['name']); ?></td>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($subject['code']); ?></span></td>
                                                <td>
                                                    <span class="badge <?php 
                                                        echo $subject['type'] == 'core' ? 'bg-primary' : ($subject['type'] == 'elective' ? 'bg-success' : 'bg-secondary'); 
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $subject['type'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($subject['teacher_name'] ?? 'Not Assigned'); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary edit-subject-btn" 
                                                            data-class-subject-id="<?php echo $subject['class_subject_id']; ?>"
                                                            data-subject-name="<?php echo htmlspecialchars($subject['name']); ?>"
                                                            data-current-teacher="<?php echo $subject['teacher_id']; ?>">
                                                        <i class="ri-edit-line"></i> Change Teacher
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Timetable Tab -->
                <div class="tab-pane fade" id="timetable" role="tabpanel">
                    <div class="card mb-4">
                        <div class="card-header bg-white py-3">
                            <h5 class="mb-0"><i class="ri-calendar-todo-line me-2 text-info"></i>Class Timetable</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($timetable)): ?>
                                <p class="text-muted text-center py-3">No timetable entries for this class.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-bordered timetable-table">
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Period</th>
                                                <th>Time</th>
                                                <th>Subject</th>
                                                <th>Teacher</th>
                                                <th>Room</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($timetable as $entry): ?>
                                                <?php if ($entry['is_break']): ?>
                                                <tr class="break-row">
                                                    <td colspan="6" class="text-center">
                                                        <i class="ri-cup-line me-2"></i> Break / Free Period
                                                    </td>
                                                </tr>
                                                <?php else: ?>
                                                <tr>
                                                    <td><strong><?php echo formatDay($entry['day']); ?></strong></td>
                                                    <td><?php echo $entry['period_number']; ?></td>
                                                    <td><?php echo date('h:i A', strtotime($entry['start_time'])); ?> - <?php echo date('h:i A', strtotime($entry['end_time'])); ?></td>
                                                    <td><?php echo htmlspecialchars($entry['subject_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($entry['teacher_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($entry['room_number'] ?? 'N/A'); ?></td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="danger-zone">
                <h5 class="text-danger mb-3"><i class="ri-alert-line me-2"></i>Danger Zone</h5>
                <div class="row">
                    <div class="col-md-6">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle_class_status">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <?php if ($class['is_active']): ?>
                                <input type="hidden" name="activate" value="0">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('Suspend this class? It will be marked inactive.')">
                                    <i class="ri-pause-line"></i> Suspend Class
                                </button>
                            <?php else: ?>
                                <input type="hidden" name="activate" value="1">
                                <button type="submit" class="btn btn-success" onclick="return confirm('Activate this class?')">
                                    <i class="ri-play-line"></i> Activate Class
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <!-- Future actions like transfer can be added here -->
                    </div>
                </div>
            </div>
        </div>

        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    </main>

    <!-- Add Section Modal -->
    <div class="modal fade" id="addSectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_section">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Section Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g., A" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Section Code <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control" placeholder="e.g., A" required>
                                <small class="text-muted">Unique within the class</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Room Number</label>
                                <input type="text" name="room_number" class="form-control" placeholder="e.g., A101">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Capacity</label>
                                <input type="number" name="capacity" class="form-control" value="40" min="1">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Class Teacher</label>
                                <select name="class_teacher_id" class="form-select">
                                    <option value="">Select Teacher</option>
                                    <?php foreach ($availableTeachersForSections as $teacher): ?>
                                    <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Only teachers not already assigned elsewhere are shown.</small>
                            </div>
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="modalIsActive" value="1" checked>
                                    <label class="form-check-label" for="modalIsActive">Active Section</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-600">Add Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editSectionForm">
                    <input type="hidden" name="action" value="edit_section">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="section_id" id="edit_section_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Section Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="edit_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Section Code <span class="text-danger">*</span></label>
                                <input type="text" name="code" id="edit_code" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Room Number</label>
                                <input type="text" name="room_number" id="edit_room_number" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Capacity</label>
                                <input type="number" name="capacity" id="edit_capacity" class="form-control" min="1">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Class Teacher</label>
                                <select name="class_teacher_id" id="edit_class_teacher_id" class="form-select">
                                    <option value="">Select Teacher</option>
                                    <!-- Options will be populated by JavaScript -->
                                </select>
                                <small class="text-muted">Only teachers not already assigned elsewhere are shown, plus the current teacher.</small>
                            </div>
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="edit_is_active" value="1">
                                    <label class="form-check-label" for="edit_is_active">Active Section</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-600">Update Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Section Modal -->
    <div class="modal fade" id="deleteSectionModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <i class="ri-delete-bin-line text-danger" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Delete Section</h5>
                    <p id="deleteSectionMessage">Are you sure you want to delete this section?</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="delete_section">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section_id" id="delete_section_id">
                        <div class="d-flex justify-content-center gap-2 mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Yes, Delete</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Teacher Modal -->
    <div class="modal fade" id="editSubjectTeacherModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Subject Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_subject_teacher">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="class_subject_id" id="edit_class_subject_id">
                    <div class="modal-body">
                        <p id="edit_subject_name" class="fw-bold"></p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Teacher</label>
                            <select name="teacher_id" class="form-select" id="edit_teacher_id">
                                <option value="">None (Unassigned)</option>
                                <?php foreach ($allTeachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-600">Update Teacher</button>
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
        var availableTeachers = <?php echo json_encode($availableTeachersForSections); ?>;
        var allTeachers = <?php echo json_encode($allTeachers); ?>;

        $(document).ready(function() {
            // Initialize toasts
            $('.toast').toast({ autohide: true, delay: 5000 }).toast('show');

            // Current year
            $('.current-year').text(new Date().getFullYear());

            // Edit Section modal
            $('.edit-section-btn').on('click', function() {
                var section = $(this).data('section');
                
                $('#edit_section_id').val(section.id);
                $('#edit_name').val(section.name);
                $('#edit_code').val(section.code);
                $('#edit_room_number').val(section.room_number || '');
                $('#edit_capacity').val(section.capacity || 40);
                $('#edit_is_active').prop('checked', section.is_active == 1);
                
                // Build teacher dropdown
                var currentTeacherId = section.class_teacher_id ? section.class_teacher_id : '';
                var currentTeacherName = section.class_teacher_name ? section.class_teacher_name : '';
                var teacherSelect = $('#edit_class_teacher_id');
                teacherSelect.empty().append('<option value="">Select Teacher</option>');
                
                // Add current teacher if exists and not already in available list
                if (currentTeacherId) {
                    var alreadyInAvailable = availableTeachers.some(t => t.id == currentTeacherId);
                    if (!alreadyInAvailable) {
                        teacherSelect.append('<option value="' + currentTeacherId + '">' + currentTeacherName + ' (current)</option>');
                    }
                }
                
                // Add available teachers
                availableTeachers.forEach(function(teacher) {
                    if (teacher.id == currentTeacherId) return;
                    teacherSelect.append('<option value="' + teacher.id + '">' + teacher.name + '</option>');
                });
                
                teacherSelect.val(currentTeacherId);
                
                $('#editSectionModal').modal('show');
            });

            // Delete Section modal
            $('.delete-section-btn').on('click', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                $('#delete_section_id').val(id);
                $('#deleteSectionMessage').text('Are you sure you want to delete section "' + name + '"? This will not delete students but will mark the section as inactive.');
                $('#deleteSectionModal').modal('show');
            });

            // Edit Subject Teacher modal
            $('.edit-subject-btn').on('click', function() {
                var classSubjectId = $(this).data('class-subject-id');
                var subjectName = $(this).data('subject-name');
                var currentTeacher = $(this).data('current-teacher');
                
                $('#edit_class_subject_id').val(classSubjectId);
                $('#edit_subject_name').text('Subject: ' + subjectName);
                $('#edit_teacher_id').val(currentTeacher);
                
                $('#editSubjectTeacherModal').modal('show');
            });
        });
    </script>
</body>
</html>