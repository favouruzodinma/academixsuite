<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_tokens'][$_POST['csrf_token']]) || $_SESSION['csrf_tokens'][$_POST['csrf_token']] < time()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}

// Get parameters
$action = $_POST['action'] ?? '';
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
$tableName = $_POST['table_name'] ?? '';
$databaseName = $_POST['database_name'] ?? '';

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

// Get school data
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school || empty($school['database_name'])) {
    echo json_encode(['success' => false, 'message' => 'School not found or database not created']);
    exit;
}

// Connect to school database
$schoolDb = Database::getSchoolConnection($school['database_name']);

switch ($action) {
    case 'export_table':
        exportTable($schoolDb, $tableName, $schoolId);
        break;
        
    case 'export_all_tables':
        exportAllTables($schoolDb, $schoolId, $school['database_name']);
        break;
        
    case 'import_table':
        importTable($schoolDb, $tableName, $schoolId);
        break;
        
    case 'generate_template':
        generateTemplate($schoolDb, $tableName);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
}

// Export single table to CSV
function exportTable($db, $tableName, $schoolId) {
    // Validate table name
    $allowedTables = [
        'users', 'students', 'teachers', 'classes', 'subjects', 
        'academic_years', 'academic_terms', 'roles', 'sections',
        'attendance', 'exams', 'exam_grades', 'fee_structures',
        'invoices', 'payments', 'guardians', 'homework', 'timetables',
        'announcements', 'events', 'settings'
    ];
    
    if (!in_array($tableName, $allowedTables)) {
        echo json_encode(['success' => false, 'message' => 'Invalid table name']);
        exit;
    }
    
    // Check if table exists
    try {
        $stmt = $db->query("SHOW TABLES LIKE '$tableName'");
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Table does not exist']);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error checking table: ' . $e->getMessage()]);
        exit;
    }
    
    // Get table data
    try {
        $stmt = $db->query("SELECT * FROM $tableName WHERE school_id = ?", [$schoolId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => 'No data found in table']);
            exit;
        }
        
        // Get column names
        $columns = array_keys($data[0]);
        
        // Generate CSV filename
        $filename = $tableName . '_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = __DIR__ . '/../../exports/' . $filename;
        
        // Ensure exports directory exists
        if (!is_dir(__DIR__ . '/../../exports')) {
            mkdir(__DIR__ . '/../../exports', 0777, true);
        }
        
        // Create CSV file
        $file = fopen($filepath, 'w');
        
        // Add BOM for UTF-8
        fwrite($file, "\xEF\xBB\xBF");
        
        // Write headers
        fputcsv($file, $columns);
        
        // Write data
        foreach ($data as $row) {
            // Convert arrays to JSON strings
            foreach ($row as &$value) {
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
            }
            fputcsv($file, $row);
        }
        
        fclose($file);
        
        // Return download link
        $downloadUrl = '/platform/admin/exports/' . $filename;
        
        // Log the export
        logExport($schoolId, $tableName, $filename, count($data));
        
        echo json_encode([
            'success' => true,
            'message' => 'Export completed successfully',
            'download_url' => $downloadUrl,
            'filename' => $filename,
            'records_exported' => count($data)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
    }
}

// Export all tables as SQL dump
function exportAllTables($db, $schoolId, $databaseName) {
    $tables = [
        'academic_years', 'academic_terms', 'classes', 'subjects',
        'users', 'roles', 'user_roles', 'students', 'teachers',
        'guardians', 'sections', 'settings'
    ];
    
    $sqlDump = "-- School Database Export\n";
    $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $sqlDump .= "-- School ID: $schoolId\n";
    $sqlDump .= "-- Database: $databaseName\n\n";
    
    $totalRecords = 0;
    
    foreach ($tables as $table) {
        try {
            // Check if table exists
            $checkStmt = $db->query("SHOW TABLES LIKE '$table'");
            if (!$checkStmt->fetch()) {
                continue;
            }
            
            // Get table structure
            $createStmt = $db->query("SHOW CREATE TABLE $table");
            $createResult = $createStmt->fetch(PDO::FETCH_ASSOC);
            
            $sqlDump .= "--\n-- Table structure for table `$table`\n--\n\n";
            $sqlDump .= $createResult['Create Table'] . ";\n\n";
            
            // Get table data
            $dataStmt = $db->query("SELECT * FROM $table WHERE school_id = ?", [$schoolId]);
            $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($data)) {
                $sqlDump .= "--\n-- Dumping data for table `$table`\n--\n\n";
                
                foreach ($data as $row) {
                    $columns = implode('`, `', array_keys($row));
                    $values = [];
                    
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($value)) {
                            $values[] = $value;
                        } else {
                            $values[] = "'" . addslashes($value) . "'";
                        }
                    }
                    
                    $valuesStr = implode(', ', $values);
                    $sqlDump .= "INSERT INTO `$table` (`$columns`) VALUES ($valuesStr);\n";
                }
                
                $sqlDump .= "\n";
                $totalRecords += count($data);
            }
            
        } catch (Exception $e) {
            // Skip tables that cause errors
            continue;
        }
    }
    
    // Save SQL file
    $filename = $databaseName . '_full_export_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = __DIR__ . '/../../exports/' . $filename;
    
    if (!is_dir(__DIR__ . '/../../exports')) {
        mkdir(__DIR__ . '/../../exports', 0777, true);
    }
    
    file_put_contents($filepath, $sqlDump);
    
    // Log the export
    logExport($schoolId, 'all_tables', $filename, $totalRecords);
    
    echo json_encode([
        'success' => true,
        'message' => 'Full database export completed',
        'download_url' => '/platform/admin/exports/' . $filename,
        'filename' => $filename,
        'tables_exported' => count($tables),
        'records_exported' => $totalRecords
    ]);
}

// Import table data from CSV
function importTable($db, $tableName, $schoolId) {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }
    
    $file = $_FILES['import_file'];
    $filePath = $file['tmp_name'];
    $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($fileType !== 'csv') {
        echo json_encode(['success' => false, 'message' => 'Only CSV files are supported']);
        exit;
    }
    
    // Validate table name
    $allowedTables = [
        'users', 'students', 'teachers', 'classes', 'subjects',
        'academic_years', 'academic_terms', 'roles', 'sections',
        'attendance', 'exams', 'exam_grades'
    ];
    
    if (!in_array($tableName, $allowedTables)) {
        echo json_encode(['success' => false, 'message' => 'Invalid table for import']);
        exit;
    }
    
    // Parse CSV file
    $records = parseCSV($filePath);
    
    if (empty($records)) {
        echo json_encode(['success' => false, 'message' => 'No data found in CSV file']);
        exit;
    }
    
    // Get table columns
    $stmt = $db->query("DESCRIBE $tableName");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Validate CSV headers
    $csvHeaders = array_keys($records[0]);
    $missingColumns = array_diff($columns, $csvHeaders);
    
    if (!empty($missingColumns)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Missing columns: ' . implode(', ', $missingColumns)
        ]);
        exit;
    }
    
    // Import records
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    $db->beginTransaction();
    
    try {
        foreach ($records as $index => $row) {
            // Add school_id if not present
            if (!isset($row['school_id'])) {
                $row['school_id'] = $schoolId;
            }
            
            // Prepare column names and values
            $colNames = [];
            $colValues = [];
            $placeholders = [];
            
            foreach ($row as $col => $value) {
                if (in_array($col, $columns)) {
                    $colNames[] = "`$col`";
                    $colValues[] = $value;
                    $placeholders[] = '?';
                }
            }
            
            $sql = "INSERT INTO $tableName (" . implode(', ', $colNames) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($colValues);
                $imported++;
            } catch (Exception $e) {
                // Check for duplicate entry
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $skipped++;
                } else {
                    $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                }
            }
        }
        
        $db->commit();
        
        // Log the import
        logImport($schoolId, $tableName, $file['name'], $imported, $skipped);
        
        echo json_encode([
            'success' => true,
            'message' => "Import completed: $imported records imported, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()]);
    }
}

// Generate template CSV for a table
function generateTemplate($db, $tableName) {
    $allowedTables = [
        'users', 'students', 'teachers', 'classes', 'subjects',
        'academic_years', 'academic_terms', 'roles'
    ];
    
    if (!in_array($tableName, $allowedTables)) {
        echo json_encode(['success' => false, 'message' => 'Invalid table for template']);
        exit;
    }
    
    try {
        // Get table structure
        $stmt = $db->query("DESCRIBE $tableName");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create CSV with headers
        $headers = [];
        $sampleData = [];
        
        foreach ($columns as $col) {
            $headers[] = $col['Field'];
            
            // Create sample data based on column type
            switch ($col['Type']) {
                case strpos($col['Type'], 'int') !== false:
                    $sampleData[] = 1;
                    break;
                case strpos($col['Type'], 'varchar') !== false:
                    $sampleData[] = 'sample_' . $col['Field'];
                    break;
                case strpos($col['Type'], 'date') !== false:
                    $sampleData[] = date('Y-m-d');
                    break;
                case strpos($col['Type'], 'datetime') !== false:
                case strpos($col['Type'], 'timestamp') !== false:
                    $sampleData[] = date('Y-m-d H:i:s');
                    break;
                case strpos($col['Type'], 'text') !== false:
                    $sampleData[] = 'Sample text for ' . $col['Field'];
                    break;
                case strpos($col['Type'], 'decimal') !== false:
                case strpos($col['Type'], 'float') !== false:
                    $sampleData[] = 0.00;
                    break;
                default:
                    $sampleData[] = '';
            }
        }
        
        // Generate CSV
        $filename = $tableName . '_template.csv';
        $filepath = __DIR__ . '/../../templates/' . $filename;
        
        if (!is_dir(__DIR__ . '/../../templates')) {
            mkdir(__DIR__ . '/../../templates', 0777, true);
        }
        
        $file = fopen($filepath, 'w');
        fwrite($file, "\xEF\xBB\xBF"); // BOM
        fputcsv($file, $headers);
        fputcsv($file, $sampleData);
        fclose($file);
        
        echo json_encode([
            'success' => true,
            'message' => 'Template generated successfully',
            'download_url' => '/platform/admin/templates/' . $filename,
            'filename' => $filename
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Template generation failed: ' . $e->getMessage()]);
    }
}

// Parse CSV file
function parseCSV($filePath) {
    $data = [];
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // Get headers
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }
        
        // Clean headers
        $headers = array_map('trim', $headers);
        
        // Read data
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            }
        }
        
        fclose($handle);
    }
    
    return $data;
}

// Log export activity
function logExport($schoolId, $tableName, $filename, $recordCount) {
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("
            INSERT INTO platform_audit_logs 
            (school_id, user_type, action, description, created_at)
            VALUES (?, 'super_admin', 'export_data', ?, NOW())
        ");
        $stmt->execute([
            $schoolId,
            "Exported $recordCount records from $tableName to $filename"
        ]);
    } catch (Exception $e) {
        error_log("Failed to log export: " . $e->getMessage());
    }
}

// Log import activity
function logImport($schoolId, $tableName, $filename, $imported, $skipped) {
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("
            INSERT INTO platform_audit_logs 
            (school_id, user_type, action, description, created_at)
            VALUES (?, 'super_admin', 'import_data', ?, NOW())
        ");
        $stmt->execute([
            $schoolId,
            "Imported $imported records to $tableName from $filename ($skipped skipped)"
        ]);
    } catch (Exception $e) {
        error_log("Failed to log import: " . $e->getMessage());
    }
}
?>