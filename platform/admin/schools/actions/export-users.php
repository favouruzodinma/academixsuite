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
$exportFormat = $data['format'] ?? 'csv';
$userTypes = $data['user_types'] ?? ['admin', 'teacher', 'student', 'parent'];

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
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Build user type condition
    $userTypePlaceholders = implode(',', array_fill(0, count($userTypes), '?'));
    
    // Get users data
    $userStmt = $schoolDb->prepare("
        SELECT 
            u.email,
            u.first_name,
            u.last_name,
            u.user_type,
            u.phone,
            u.is_active,
            u.email_verified_at,
            u.last_login_at,
            u.created_at,
            u.updated_at,
            GROUP_CONCAT(r.name SEPARATOR ', ') as roles
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.is_active = 1 
        AND u.user_type IN ($userTypePlaceholders)
        GROUP BY u.id
        ORDER BY u.user_type, u.created_at
    ");
    $userStmt->execute($userTypes);
    $users = $userStmt->fetchAll();
    
    $totalUsers = count($users);
    
    if ($totalUsers === 0) {
        echo json_encode(['success' => false, 'message' => 'No users found for export']);
        exit;
    }
    
    // Create exports directory
    $exportsDir = __DIR__ . '/../../exports/';
    if (!file_exists($exportsDir)) {
        mkdir($exportsDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "{$databaseName}_users_{$timestamp}";
    
    switch ($exportFormat) {
        case 'csv':
            $filename .= '.csv';
            $fileContent = generateCSV($users);
            $mimeType = 'text/csv';
            break;
            
        case 'excel':
            $filename .= '.xlsx';
            $fileContent = generateExcel($users, $school['name']);
            $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            break;
            
        case 'json':
            $filename .= '.json';
            $fileContent = json_encode($users, JSON_PRETTY_PRINT);
            $mimeType = 'application/json';
            break;
            
        case 'pdf':
            $filename .= '.pdf';
            $fileContent = generatePDF($users, $school['name']);
            $mimeType = 'application/pdf';
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid export format']);
            exit;
    }
    
    $exportFile = $exportsDir . $filename;
    file_put_contents($exportFile, $fileContent);
    
    $fileSize = filesize($exportFile);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'users_exported', ?, 'super_admin', NOW())
    ");
    $logDescription = "Users exported: $filename (" . formatBytes($fileSize) . "). Format: $exportFormat, Users: $totalUsers";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Users exported successfully',
        'filename' => $filename,
        'file_size' => formatBytes($fileSize),
        'total_users' => $totalUsers,
        'export_format' => $exportFormat,
        'download_url' => "../exports/" . $filename,
        'user_types' => $userTypes,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Error exporting users: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error exporting users: ' . $e->getMessage()]);
}

// Helper functions
function generateCSV($users) {
    $output = fopen('php://temp', 'w');
    
    // Write headers
    $headers = ['Email', 'First Name', 'Last Name', 'User Type', 'Phone', 'Active', 
                'Email Verified', 'Last Login', 'Created At', 'Updated At', 'Roles'];
    fputcsv($output, $headers);
    
    // Write data
    foreach ($users as $user) {
        fputcsv($output, [
            $user['email'],
            $user['first_name'],
            $user['last_name'],
            $user['user_type'],
            $user['phone'] ?? '',
            $user['is_active'] ? 'Yes' : 'No',
            $user['email_verified_at'] ? 'Yes' : 'No',
            $user['last_login_at'] ?? 'Never',
            $user['created_at'],
            $user['updated_at'],
            $user['roles'] ?? ''
        ]);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
}

function generateExcel($users, $schoolName) {
    // In a real implementation, use PHPExcel or PhpSpreadsheet
    // For simplicity, we'll create a CSV with Excel headers
    $excel = "sep=,\n"; // Excel separator
    $excel .= generateCSV($users);
    return $excel;
}

function generatePDF($users, $schoolName) {
    // In a real implementation, use TCPDF or Dompdf
    // For simplicity, return HTML that can be converted to PDF
    $html = "
        <html>
        <head>
            <title>Users Export - $schoolName</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 12px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #4CAF50; color: white; }
                .summary { background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>Users Export - $schoolName</h1>
            <p><strong>Generated:</strong> " . date('F j, Y, g:i a') . "</p>
            <p><strong>Total Users:</strong> " . count($users) . "</p>
            
            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Phone</th>
                        <th>Active</th>
                        <th>Last Login</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
    ";
    
    foreach ($users as $user) {
        $html .= "
            <tr>
                <td>{$user['email']}</td>
                <td>{$user['first_name']} {$user['last_name']}</td>
                <td>{$user['user_type']}</td>
                <td>" . ($user['phone'] ?? '') . "</td>
                <td>" . ($user['is_active'] ? 'Yes' : 'No') . "</td>
                <td>" . ($user['last_login_at'] ?? 'Never') . "</td>
                <td>{$user['created_at']}</td>
            </tr>
        ";
    }
    
    $html .= "
                </tbody>
            </table>
            <p><em>This is an automatically generated report. Contains " . count($users) . " user records.</em></p>
        </body>
        </html>
    ";
    
    return $html; // In reality, convert to PDF
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>