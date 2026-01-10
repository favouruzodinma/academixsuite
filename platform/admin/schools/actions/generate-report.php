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
$reportType = $data['report_type'] ?? 'usage_summary';

if ($schoolId <= 0 || empty($databaseName)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    $db = Database::getPlatformConnection();
    
    // Get school details
    $schoolStmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $schoolStmt->execute([$schoolId]);
    $school = $schoolStmt->fetch();
    
    if (!$school) {
        echo json_encode(['success' => false, 'message' => 'School not found']);
        exit;
    }
    
    // Connect to school database
    $schoolDb = Database::getSchoolConnection($databaseName);
    
    // Generate report based on type
    $report = [];
    $timestamp = date('Y-m-d_H-i-s');
    
    switch ($reportType) {
        case 'usage_summary':
            $report = generateUsageSummary($schoolDb, $school);
            $filename = "usage_summary_{$schoolId}_{$timestamp}.pdf";
            break;
            
        case 'user_activity':
            $report = generateUserActivityReport($schoolDb);
            $filename = "user_activity_{$schoolId}_{$timestamp}.csv";
            break;
            
        case 'financial':
            $report = generateFinancialReport($db, $schoolId);
            $filename = "financial_report_{$schoolId}_{$timestamp}.xlsx";
            break;
            
        case 'system_health':
            $report = generateSystemHealthReport($schoolDb, $school);
            $filename = "system_health_{$schoolId}_{$timestamp}.json";
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid report type']);
            exit;
    }
    
    // Create reports directory
    $reportsDir = __DIR__ . '/../../reports/';
    if (!file_exists($reportsDir)) {
        mkdir($reportsDir, 0755, true);
    }
    
    // Save report to file
    $reportFile = $reportsDir . $filename;
    
    if ($reportType === 'usage_summary') {
        // Generate PDF report (simplified - you'd use a PDF library like TCPDF or Dompdf)
        $pdfContent = generatePDFReport($report, $school);
        file_put_contents($reportFile, $pdfContent);
    } elseif ($reportType === 'user_activity') {
        // Generate CSV
        $csvContent = generateCSVReport($report);
        file_put_contents($reportFile, $csvContent);
    } else {
        // Save as JSON
        file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
    }
    
    $fileSize = filesize($reportFile);
    
    // Log the action
    $logStmt = $db->prepare("
        INSERT INTO platform_audit_logs 
        (school_id, event, description, user_type, created_at) 
        VALUES (?, 'report_generated', ?, 'super_admin', NOW())
    ");
    $logDescription = "Report generated: $filename (" . formatBytes($fileSize) . ")";
    $logStmt->execute([$schoolId, $logDescription]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Report generated successfully',
        'filename' => $filename,
        'file_size' => formatBytes($fileSize),
        'download_url' => "../reports/" . $filename,
        'report_type' => $reportType,
        'generated_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Error generating report: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error generating report: ' . $e->getMessage()]);
}

// Helper functions for report generation
function generateUsageSummary($db, $school) {
    $report = [
        'school_info' => [
            'name' => $school['name'],
            'email' => $school['email'],
            'created_at' => $school['created_at'],
            'status' => $school['status']
        ],
        'user_statistics' => [],
        'activity_summary' => [],
        'storage_usage' => [],
        'generated_at' => date('Y-m-d H:i:s')
    ];
    
    // Get user statistics
    $userStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_users,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
            SUM(CASE WHEN user_type = 'teacher' THEN 1 ELSE 0 END) as teachers,
            SUM(CASE WHEN user_type = 'student' THEN 1 ELSE 0 END) as students,
            SUM(CASE WHEN user_type = 'admin' THEN 1 ELSE 0 END) as admins,
            SUM(CASE WHEN user_type = 'parent' THEN 1 ELSE 0 END) as parents,
            MAX(last_login_at) as last_login
        FROM users
    ");
    $userStmt->execute();
    $report['user_statistics'] = $userStmt->fetch();
    
    // Get recent activity
    $activityStmt = $db->prepare("
        SELECT event, COUNT(*) as count, MAX(created_at) as last_occurrence
        FROM audit_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY event
        ORDER BY count DESC
        LIMIT 10
    ");
    $activityStmt->execute();
    $report['activity_summary'] = $activityStmt->fetchAll();
    
    return $report;
}

function generateUserActivityReport($db) {
    $stmt = $db->prepare("
        SELECT 
            u.email,
            u.first_name,
            u.last_name,
            u.user_type,
            u.last_login_at,
            u.is_active,
            COUNT(DISTINCT a.id) as activity_count,
            MAX(a.created_at) as last_activity
        FROM users u
        LEFT JOIN audit_logs a ON u.id = a.user_id
        GROUP BY u.id
        ORDER BY u.last_login_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function generateFinancialReport($db, $schoolId) {
    $report = [
        'invoices' => [],
        'payments' => [],
        'subscription_history' => [],
        'summary' => []
    ];
    
    // Get invoices
    $invoiceStmt = $db->prepare("
        SELECT * FROM invoices 
        WHERE school_id = ?
        ORDER BY created_at DESC
    ");
    $invoiceStmt->execute([$schoolId]);
    $report['invoices'] = $invoiceStmt->fetchAll();
    
    // Get payments
    $paymentStmt = $db->prepare("
        SELECT p.*, i.invoice_number 
        FROM payments p
        LEFT JOIN invoices i ON p.invoice_id = i.id
        WHERE p.school_id = ?
        ORDER BY p.paid_at DESC
    ");
    $paymentStmt->execute([$schoolId]);
    $report['payments'] = $paymentStmt->fetchAll();
    
    // Calculate summary
    $totalPaid = 0;
    $totalPending = 0;
    foreach ($report['invoices'] as $invoice) {
        if ($invoice['status'] === 'paid') {
            $totalPaid += $invoice['amount'];
        } elseif ($invoice['status'] === 'pending') {
            $totalPending += $invoice['amount'];
        }
    }
    
    $report['summary'] = [
        'total_invoices' => count($report['invoices']),
        'total_paid' => $totalPaid,
        'total_pending' => $totalPending,
        'paid_percentage' => count($report['invoices']) > 0 ? round(($totalPaid / ($totalPaid + $totalPending)) * 100, 2) : 0
    ];
    
    return $report;
}

function generateSystemHealthReport($db, $school) {
    $report = [
        'database' => [],
        'tables' => [],
        'performance' => []
    ];
    
    // Get table information
    $tablesStmt = $db->query("SHOW TABLE STATUS");
    $tables = $tablesStmt->fetchAll();
    
    $totalRows = 0;
    $totalSize = 0;
    
    foreach ($tables as $table) {
        $report['tables'][] = [
            'name' => $table['Name'],
            'rows' => $table['Rows'],
            'size' => formatBytes($table['Data_length'] + $table['Index_length']),
            'engine' => $table['Engine']
        ];
        
        $totalRows += $table['Rows'];
        $totalSize += $table['Data_length'] + $table['Index_length'];
    }
    
    $report['database'] = [
        'total_tables' => count($tables),
        'total_rows' => $totalRows,
        'total_size' => formatBytes($totalSize),
        'average_rows_per_table' => count($tables) > 0 ? round($totalRows / count($tables), 2) : 0
    ];
    
    return $report;
}

function generatePDFReport($data, $school) {
    // Simplified PDF generation - in real implementation, use TCPDF or Dompdf
    $html = "
        <html>
        <head>
            <title>Usage Report - {$school['name']}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                th { background-color: #4CAF50; color: white; }
                .summary { background: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <h1>Usage Report - {$school['name']}</h1>
            <p><strong>Generated:</strong> " . date('F j, Y, g:i a') . "</p>
            
            <div class='summary'>
                <h2>School Information</h2>
                <p><strong>Name:</strong> {$school['name']}</p>
                <p><strong>Email:</strong> {$school['email']}</p>
                <p><strong>Status:</strong> {$school['status']}</p>
                <p><strong>Created:</strong> " . date('F j, Y', strtotime($school['created_at'])) . "</p>
            </div>
            
            <h2>User Statistics</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>
                <tr><td>Total Users</td><td>{$data['user_statistics']['total_users']}</td></tr>
                <tr><td>Active Users</td><td>{$data['user_statistics']['active_users']}</td></tr>
                <tr><td>Teachers</td><td>{$data['user_statistics']['teachers']}</td></tr>
                <tr><td>Students</td><td>{$data['user_statistics']['students']}</td></tr>
                <tr><td>Admins</td><td>{$data['user_statistics']['admins']}</td></tr>
                <tr><td>Parents</td><td>{$data['user_statistics']['parents']}</td></tr>
            </table>
            
            <p><strong>Report ID:</strong> REP-" . strtoupper(uniqid()) . "</p>
            <p><em>This is an automatically generated report.</em></p>
        </body>
        </html>
    ";
    
    return $html; // In reality, convert this to PDF
}

function generateCSVReport($data) {
    $output = fopen('php://temp', 'w');
    
    // Write headers
    fputcsv($output, ['Email', 'First Name', 'Last Name', 'User Type', 'Last Login', 'Active', 'Activity Count']);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['email'],
            $row['first_name'],
            $row['last_name'],
            $row['user_type'],
            $row['last_login_at'],
            $row['is_active'] ? 'Yes' : 'No',
            $row['activity_count']
        ]);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
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