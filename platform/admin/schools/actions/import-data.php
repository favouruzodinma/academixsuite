<?php
session_start();
require_once __DIR__ . '/../../../../includes/autoload.php';

header('Content-Type: application/json');

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if it's a JSON POST request
if (!isset($_SERVER['CONTENT_TYPE']) || stripos($_SERVER['CONTENT_TYPE'], 'application/json') === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid content type. Expected JSON']);
    exit;
}

// Get JSON data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate CSRF token using your existing function
if (!isset($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'CSRF token is required']);
    exit;
}

// Use your existing CSRF validation function
if (!function_exists('validateCSRFToken')) {
    // Define the function if not exists (from your autoload.php)
    function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        if ($_SESSION['csrf_tokens'][$token] < time()) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
}

if (!validateCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}
$schoolId = $data['school_id'] ?? 0;
$databaseName = $data['database_name'] ?? '';

if ($schoolId <= 0 || empty($databaseName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT name FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Check if import file was uploaded
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false, 
            'message' => 'No file uploaded or upload error',
            'next_step' => 'show_upload_form'
        ]);
        exit;
    }
    
    $uploadedFile = $_FILES['import_file'];
    
    // Validate file type
    $allowedExtensions = ['sql', 'gz', 'zip'];
    $fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: .sql, .gz, .zip']);
        exit;
    }
    
    // Create uploads directory
    $uploadDir = __DIR__ . '/../../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $uploadFile = $uploadDir . uniqid('import_') . '_' . basename($uploadedFile['name']);
    
    if (!move_uploaded_file($uploadedFile['tmp_name'], $uploadFile)) {
        throw new Exception('Failed to move uploaded file');
    }
    
    // Handle compressed files
    if ($fileExtension === 'gz') {
        $uncompressedFile = $uploadFile . '.sql';
        exec("gzip -dc $uploadFile > $uncompressedFile", $output, $returnVar);
        if ($returnVar === 0) {
            $uploadFile = $uncompressedFile;
        }
    } elseif ($fileExtension === 'zip') {
        $uncompressedFile = $uploadDir . uniqid('import_') . '.sql';
        exec("unzip -p $uploadFile > $uncompressedFile", $output, $returnVar);
        if ($returnVar === 0) {
            $uploadFile = $uncompressedFile;
        }
    }
    
    // Import the SQL file
    $command = sprintf(
        'mysql --user=%s --password=%s --host=%s %s < %s 2>&1',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_HOST),
        escapeshellarg($databaseName),
        escapeshellarg($uploadFile)
    );
    
    exec($command, $output, $returnVar);
    
    // Clean up uploaded files
    unlink($uploadFile);
    if (isset($uncompressedFile) && file_exists($uncompressedFile)) {
        unlink($uncompressedFile);
    }
    
    if ($returnVar !== 0) {
        throw new Exception("Import failed: " . implode("\n", $output));
    }
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'data_imported', ?, 'super_admin', NOW())
    ");
    $logDescription = "Data imported from file: " . $uploadedFile['name'];
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Data imported successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error importing data: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error importing data: ' . $e->getMessage()]);
}
?>