<?php
/**
 * AJAX endpoint to get subjects for a class
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../../logs/ajax_get_class_subjects.log');

error_log("=== GET CLASS SUBJECTS AJAX START ===");

// Start session
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('academix_tenant');
        $sessionConfig = __DIR__ . '/../../../../includes/session_config.php';
        if (is_file($sessionConfig)) {
            require_once $sessionConfig;
            session_start(academix_session_options());
        } else {
            session_start();
        }
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Set header for JSON response
header('Content-Type: application/json');

// Check if it's a POST request
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get parameters
$classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

if (!$classId || !$schoolId) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Configuration loading failed']);
    exit;
}

// Validate the signed-in school admin before exposing school data.
$schoolAuth = $_SESSION['school_auth'] ?? [];
if (!is_array($schoolAuth) || ($schoolAuth['user_type'] ?? '') !== 'admin' || (int)($schoolAuth['school_id'] ?? 0) !== $schoolId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized request']);
    exit;
}

$schoolSlug = (string)($schoolAuth['school_slug'] ?? '');
$databaseName = (string)($schoolAuth['database_name'] ?? ($_SESSION['school_info'][$schoolSlug]['database_name'] ?? ''));

if ($databaseName === '') {
    try {
        $platformDb = Database::getPlatformConnection();
        $stmt = $platformDb->prepare('SELECT database_name FROM schools WHERE id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$schoolId, $schoolSlug]);
        $databaseName = (string)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("ERROR: Could not load school database name: " . $e->getMessage());
    }
}

if ($databaseName === '') {
    echo json_encode(['success' => false, 'message' => 'School database not configured']);
    exit;
}

// Connect to school database
try {
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    if (!$schoolDb) {
        throw new Exception("Could not connect to school database");
    }
    
    // First try to get class-specific subjects
    $stmt = $schoolDb->prepare("
        SELECT s.*, cs.teacher_id
        FROM subjects s
        JOIN class_subjects cs ON s.id = cs.subject_id
        WHERE cs.class_id = ? AND s.school_id = ? AND s.is_active = 1
        ORDER BY s.name
    ");
    
    $stmt->execute([$classId, $schoolId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no class-specific subjects, get all subjects
    if (empty($subjects)) {
        $stmt = $schoolDb->prepare("
            SELECT * FROM subjects 
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([$schoolId]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    error_log("Found " . count($subjects) . " subjects for class ID: " . $classId);
    
    echo json_encode([
        'success' => true,
        'subjects' => $subjects
    ]);
    
} catch (Exception $e) {
    error_log("Error getting subjects: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
