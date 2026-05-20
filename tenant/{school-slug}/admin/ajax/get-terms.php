<?php
/**
 * AJAX endpoint to get terms for an academic year
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../../logs/ajax_get_terms.log');

error_log("=== GET TERMS AJAX START ===");

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
$academicYearId = isset($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : 0;
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;

if (!$academicYearId || !$schoolId) {
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
    
    // Get terms for the academic year
    $stmt = $schoolDb->prepare("
        SELECT id, name, start_date, end_date, is_default
        FROM academic_terms 
        WHERE school_id = ? AND academic_year_id = ?
        ORDER BY is_default DESC, start_date
    ");
    
    $stmt->execute([$schoolId, $academicYearId]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($terms) . " terms for academic year ID: " . $academicYearId);
    
    echo json_encode([
        'success' => true,
        'terms' => $terms
    ]);
    
} catch (Exception $e) {
    error_log("Error getting terms: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
