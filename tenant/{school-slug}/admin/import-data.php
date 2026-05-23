<?php
/**
 * School Data Import Page
 * Handles bulk import of data into school database tables with progress tracking
 * 
 * @package AcademixSuite
 * @version 3.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_import.log');
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '256M');

error_log("=== IMPORT DATA PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

// Start session
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if (($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify admin access
if ($userType !== 'admin') {
    error_log("ERROR: User does not have admin privileges");
    header('HTTP/1.1 403 Forbidden');
    die("Access denied. Admin privileges required.");
}

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    require_once $autoloadPath;
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Define importable tables and their structures (MATCHING YOUR DATABASE)
$importTables = [
    'academic_years' => [
        'name' => 'Academic Years',
        'required_fields' => ['name', 'start_date', 'end_date'],
        'optional_fields' => ['status', 'is_default'],
        'sample_data' => [
            ['name', 'start_date', 'end_date', 'status', 'is_default'],
            ['2024/2025', '2024-09-01', '2025-07-31', 'active', '1'],
            ['2025/2026', '2025-09-01', '2026-07-31', 'upcoming', '0']
        ]
    ],
    'classes' => [
        'name' => 'Classes',
        'required_fields' => ['name', 'code', 'academic_year_id'],
        'optional_fields' => ['capacity', 'room_number', 'description', 'grade_level', 'campus_id', 'class_teacher_id'],
        'sample_data' => [
            ['name', 'code', 'academic_year_id', 'capacity', 'room_number'],
            ['Grade 1', 'G01', '1', '40', 'Room 101'],
            ['Grade 2', 'G02', '1', '38', 'Room 102']
        ]
    ],
    'sections' => [
        'name' => 'Sections',
        'required_fields' => ['name', 'code', 'class_id'],
        'optional_fields' => ['capacity', 'room_number', 'class_teacher_id'],
        'sample_data' => [
            ['name', 'code', 'class_id', 'capacity', 'room_number'],
            ['Section A', 'SEC-A', '1', '40', 'Room 101A'],
            ['Section B', 'SEC-B', '1', '38', 'Room 101B']
        ]
    ],
    'subjects' => [
        'name' => 'Subjects',
        'required_fields' => ['name', 'code'],
        'optional_fields' => ['type', 'description', 'credit_hours'],
        'sample_data' => [
            ['name', 'code', 'type', 'credit_hours'],
            ['Mathematics', 'MATH101', 'core', '4'],
            ['English', 'ENG101', 'core', '4']
        ]
    ],
    'students' => [
        'name' => 'Students',
        'required_fields' => ['first_name', 'last_name', 'admission_number', 'date_of_birth'],
        'optional_fields' => [
            'middle_name', 'roll_number', 'class_id', 'section_id', 
            'admission_date', 'gender', 'student_phone', 'student_email', 
            'blood_group', 'current_address', 'permanent_address', 
            'previous_school', 'previous_class', 'transfer_certificate_no',
            'allergies', 'medical_conditions', 'doctor_name', 'doctor_phone',
            'birth_place', 'nationality', 'mother_tongue', 'campus_id'
        ],
        'sample_data' => [
            ['first_name', 'last_name', 'admission_number', 'date_of_birth', 'class_id', 'roll_number'],
            ['John', 'Doe', 'ADM-2024-001', '2010-05-15', '1', '101'],
            ['Jane', 'Smith', 'ADM-2024-002', '2010-08-22', '1', '102']
        ]
    ],
    'guardians' => [
        'name' => 'Guardians (Parents)',
        'description' => 'Import guardians first, then link them to students using guardian_links',
        'required_fields' => ['name', 'email'],
        'optional_fields' => ['phone', 'address', 'profile_photo'],
        'sample_data' => [
            ['name', 'email', 'phone', 'address'],
            ['John Smith Sr.', 'john.smith@email.com', '+1234567890', '123 Main St'],
            ['Jane Doe', 'jane.doe@email.com', '+0987654321', '456 Oak Ave']
        ]
    ],
    'guardian_links' => [
        'name' => 'Guardian-Student Relationships',
        'description' => 'Link guardians to students after both are imported',
        'required_fields' => ['guardian_email', 'student_admission_number', 'relationship'],
        'optional_fields' => ['is_primary', 'can_pickup', 'emergency_contact'],
        'sample_data' => [
            ['guardian_email', 'student_admission_number', 'relationship', 'is_primary'],
            ['john.smith@email.com', 'ADM-2024-001', 'father', '1'],
            ['jane.doe@email.com', 'ADM-2024-001', 'mother', '0']
        ]
    ],
    'teachers' => [
        'name' => 'Teachers',
        'required_fields' => ['name', 'email', 'employee_id'],
        'optional_fields' => [
            'phone', 'qualification', 'specialization', 'joining_date', 
            'gender', 'experience_years', 'salary_grade'
        ],
        'sample_data' => [
            ['name', 'email', 'employee_id', 'phone', 'qualification'],
            ['Robert Johnson', 'robert.j@school.com', 'TCH-001', '+1234567890', 'M.Ed'],
            ['Sarah Williams', 'sarah.w@school.com', 'TCH-002', '+0987654321', 'B.Ed']
        ]
    ],
    'fee_categories' => [
        'name' => 'Fee Categories',
        'required_fields' => ['name'],
        'optional_fields' => ['description'],
        'sample_data' => [
            ['name', 'description'],
            ['Tuition Fee', 'Regular tuition fees'],
            ['Library Fee', 'Annual library fees']
        ]
    ],
    'fee_structures' => [
        'name' => 'Fee Structures',
        'required_fields' => ['class_id', 'fee_category_id', 'amount', 'academic_year_id'],
        'optional_fields' => ['academic_term_id', 'due_date', 'late_fee'],
        'sample_data' => [
            ['class_id', 'fee_category_id', 'amount', 'academic_year_id', 'due_date'],
            ['1', '1', '5000.00', '1', '2024-10-01'],
            ['1', '2', '1000.00', '1', '2024-10-01']
        ]
    ]
];

// Handle template download
if (isset($_GET['download_template'])) {
    $table = $_GET['download_template'];
    
    if (!isset($importTables[$table])) {
        die("Invalid table specified");
    }
    
    $format = $_GET['format'] ?? 'csv';
    $tableInfo = $importTables[$table];
    
    // Generate filename
    $filename = $table . '_import_template_' . date('Y-m-d');
    
    if ($format === 'csv') {
        $filename .= '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Add description as comment if available
        if (!empty($tableInfo['description'])) {
            fputcsv($output, ['# ' . $tableInfo['description']]);
        }
        
        // Add headers
        $headers = array_merge($tableInfo['required_fields'], $tableInfo['optional_fields']);
        fputcsv($output, $headers);
        
        // Add sample rows
        if (!empty($tableInfo['sample_data'])) {
            foreach ($tableInfo['sample_data'] as $row) {
                // Pad row to match header count
                $paddedRow = array_pad($row, count($headers), '');
                fputcsv($output, $paddedRow);
            }
        }
        
        fclose($output);
        exit;
        
    } elseif ($format === 'excel') {
        $filename .= '.xls';
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo "<html>";
        echo "<head>";
        echo "<meta charset='UTF-8'>";
        echo "<title>" . $tableInfo['name'] . " Import Template</title>";
        echo "</head>";
        echo "<body>";
        
        if (!empty($tableInfo['description'])) {
            echo "<p><strong>Note:</strong> " . htmlspecialchars($tableInfo['description']) . "</p>";
        }
        
        echo "<table border='1'>";
        
        // Add headers
        echo "<tr>";
        $headers = array_merge($tableInfo['required_fields'], $tableInfo['optional_fields']);
        foreach ($headers as $header) {
            $required = in_array($header, $tableInfo['required_fields']) ? ' *' : '';
            echo "<th>" . str_replace('_', ' ', ucfirst($header)) . $required . "</th>";
        }
        echo "</tr>";
        
        // Add sample rows
        if (!empty($tableInfo['sample_data'])) {
            foreach ($tableInfo['sample_data'] as $row) {
                echo "<tr>";
                $paddedRow = array_pad($row, count($headers), '');
                foreach ($paddedRow as $cell) {
                    echo "<td>" . htmlspecialchars($cell) . "</td>";
                }
                echo "</tr>";
            }
        }
        
        echo "</table>";
        echo "</body>";
        echo "</html>";
        exit;
    }
}

// Initialize variables
$importResults = [];
$currentTable = $_GET['table'] ?? 'students';
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];

// Get user details
if ($schoolDb) {
    $userStmt = $schoolDb->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u 
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.id = ? AND u.school_id = ?
        LIMIT 1
    ");
    if ($userStmt) {
        $userStmt->execute([$userId, $school['id']]);
        $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($adminUserData) {
            $adminUser = $adminUserData;
        }
    }
}

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $importTable = $_POST['import_table'] ?? $currentTable;
    $file = $_FILES['import_file'];
    $skipErrors = isset($_POST['skip_errors']);
    
    $importResults = [
        'table' => $importTable,
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'errors' => [],
        'start_time' => microtime(true)
    ];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        try {
            if ($fileType === 'csv') {
                // Process CSV file
                $handle = fopen($file['tmp_name'], 'r');
                
                if ($handle !== false) {
                    // Read and validate headers
                    $headers = fgetcsv($handle);
                    if (!$headers) {
                        throw new Exception("Invalid CSV file: No headers found");
                    }
                    
                    // Clean headers (remove BOM, trim)
                    $headers = array_map(function($h) {
                        // Remove UTF-8 BOM if present
                        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
                        return trim($h);
                    }, $headers);
                    
                    // Count total rows
                    $rowCount = 0;
                    while (fgetcsv($handle) !== false) {
                        $rowCount++;
                    }
                    rewind($handle);
                    fgetcsv($handle); // Skip header row
                    
                    $importResults['total'] = $rowCount;
                    
                    // Process rows
                    $rowNumber = 1;
                    while (($data = fgetcsv($handle)) !== false) {
                        // Ensure data array matches header count
                        if (count($data) < count($headers)) {
                            $data = array_pad($data, count($headers), null);
                        }
                        
                        $rowData = array_combine($headers, $data);
                        
                        // Remove empty values
                        $rowData = array_filter($rowData, function($value) {
                            return $value !== null && $value !== '';
                        });
                        
                        // Process the row
                        $result = processImportRow($schoolDb, $importTable, $rowData, $school['id']);
                        
                        if ($result['success']) {
                            $importResults['success']++;
                        } else {
                            $importResults['failed']++;
                            $importResults['errors'][] = "Row $rowNumber: " . $result['message'];
                            
                            if (!$skipErrors) {
                                break; // Stop on first error if not skipping
                            }
                        }
                        
                        $rowNumber++;
                        
                        // For AJAX progress updates
                        if (isset($_POST['ajax'])) {
                            echo json_encode([
                                'progress' => round(($rowNumber / $importResults['total']) * 100),
                                'current' => $rowNumber,
                                'total' => $importResults['total'],
                                'success' => $importResults['success'],
                                'failed' => $importResults['failed']
                            ]);
                            ob_flush();
                            flush();
                        }
                    }
                    fclose($handle);
                }
            } else {
                $importResults['errors'][] = "Unsupported file format. Please use CSV.";
            }
            
            $importResults['execution_time'] = round(microtime(true) - $importResults['start_time'], 2);
            
            // Create audit log
            if ($schoolDb && $importResults['success'] > 0) {
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        new_values, ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, 'import', ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $importTable,
                    json_encode([
                        'total' => $importResults['total'],
                        'success' => $importResults['success'],
                        'failed' => $importResults['failed']
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            }

            // ── Send welcome emails to all newly imported users ───────────────
            if (!empty($_SESSION['imported_passwords']) && $schoolDb) {
                $svcPath = __DIR__ . '/../../../includes/Services/WelcomeEmailService.php';
                if (file_exists($svcPath)) {
                    require_once $svcPath;
                }
                $emailsSent  = 0;
                $emailErrors = 0;
                foreach ($_SESSION['imported_passwords'] as $impUserId => $impPassword) {
                    try {
                        $uStmt = $schoolDb->prepare("SELECT id, name, email, username, user_type FROM users WHERE id = ? LIMIT 1");
                        $uStmt->execute([(int) $impUserId]);
                        $impUser = $uStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$impUser || empty($impUser['email'])) continue;

                        $role = match (strtolower($impUser['user_type'] ?? '')) {
                            'student'              => 'student',
                            'parent', 'guardian'   => 'parent',
                            'teacher'              => 'teacher',
                            'admin'                => 'admin',
                            default                => 'staff',
                        };

                        $ok = false;
                        if (class_exists('WelcomeEmailService')) {
                            $svc = new WelcomeEmailService($school);
                            $ok  = $svc->send($role, [
                                'name'     => $impUser['name'],
                                'email'    => $impUser['email'],
                                'username' => $impUser['username'] ?? $impUser['email'],
                                'password' => $impPassword,
                            ]);
                        }
                        $ok ? $emailsSent++ : $emailErrors++;
                    } catch (Throwable $emailEx) {
                        error_log("import-data welcome email error for user {$impUserId}: " . $emailEx->getMessage());
                        $emailErrors++;
                    }
                }
                // Clear consumed passwords
                unset($_SESSION['imported_passwords']);
                error_log("import-data: welcome emails sent={$emailsSent} errors={$emailErrors}");
            }

        } catch (Exception $e) {
            $importResults['errors'][] = "Error processing file: " . $e->getMessage();
        }
    } else {
        $importResults['errors'][] = "File upload error: " . getUploadErrorMessage($file['error']);
    }
}

// Helper function to process import row
function processImportRow($db, $table, $data, $schoolId) {
    try {
        switch ($table) {
            case 'students':
                return importStudent($db, $data, $schoolId);
            case 'guardians':
                return importGuardian($db, $data, $schoolId);
            case 'guardian_links':
                return importGuardianLink($db, $data, $schoolId);
            case 'teachers':
                return importTeacher($db, $data, $schoolId);
            case 'classes':
                return importClass($db, $data, $schoolId);
            case 'sections':
                return importSection($db, $data, $schoolId);
            case 'subjects':
                return importSubject($db, $data, $schoolId);
            case 'academic_years':
                return importAcademicYear($db, $data, $schoolId);
            case 'fee_categories':
                return importFeeCategory($db, $data, $schoolId);
            case 'fee_structures':
                return importFeeStructure($db, $data, $schoolId);
            default:
                return ['success' => false, 'message' => 'Unknown table'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

// Import function for Students (matches your database structure)
function importStudent($db, $data, $schoolId) {
    // Validate required fields
    if (empty($data['first_name']) || empty($data['last_name'])) {
        return ['success' => false, 'message' => 'First name and last name are required'];
    }
    
    if (empty($data['admission_number'])) {
        return ['success' => false, 'message' => 'Admission number is required'];
    }
    
    if (empty($data['date_of_birth'])) {
        return ['success' => false, 'message' => 'Date of birth is required'];
    }
    
    // Check if admission number exists
    $checkStmt = $db->prepare("SELECT id FROM students WHERE school_id = ? AND admission_number = ?");
    $checkStmt->execute([$schoolId, $data['admission_number']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Admission number already exists'];
    }
    
    // Create user account for student
    $fullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ?? '') . ' ' . $data['last_name']);
    $email = $data['student_email'] ?? strtolower($data['first_name'] . '.' . $data['last_name'] . '@student.school.edu');
    $username = explode('@', $email)[0];
    $password = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert into users table
    $userStmt = $db->prepare("
        INSERT INTO users (
            school_id, name, email, phone, username, password, user_type,
            gender, date_of_birth, address, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'student', ?, ?, ?, 1, NOW())
    ");
    
    $userStmt->execute([
        $schoolId,
        $fullName,
        $email,
        $data['student_phone'] ?? null,
        $username,
        $hashedPassword,
        $data['gender'] ?? null,
        $data['date_of_birth'],
        $data['current_address'] ?? null
    ]);
    
    $userId = $db->lastInsertId();
    
    // Assign student role (role_id = 4)
    $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 4, NOW())");
    $roleStmt->execute([$userId]);
    
    // Get default campus ID or use provided
    $campusId = $data['campus_id'] ?? null;
    if (!$campusId) {
        $campusStmt = $db->prepare("SELECT id FROM campuses WHERE school_id = ? AND is_default = 1 LIMIT 1");
        $campusStmt->execute([$schoolId]);
        $campus = $campusStmt->fetch(PDO::FETCH_ASSOC);
        $campusId = $campus['id'] ?? null;
    }
    
    // Insert into students table
    $stmt = $db->prepare("
        INSERT INTO students (
            school_id, campus_id, user_id, admission_number, roll_number,
            class_id, section_id, admission_date, first_name, middle_name,
            last_name, date_of_birth, birth_place, nationality, mother_tongue,
            current_address, permanent_address, previous_school, previous_class,
            transfer_certificate_no, blood_group, allergies, medical_conditions,
            doctor_name, doctor_phone, status, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, 'active', NOW()
        )
    ");
    
    $admissionDate = !empty($data['admission_date']) ? $data['admission_date'] : date('Y-m-d');
    
    $stmt->execute([
        $schoolId,
        $campusId,
        $userId,
        $data['admission_number'],
        $data['roll_number'] ?? null,
        $data['class_id'] ?? null,
        $data['section_id'] ?? null,
        $admissionDate,
        $data['first_name'],
        $data['middle_name'] ?? null,
        $data['last_name'],
        $data['date_of_birth'],
        $data['birth_place'] ?? null,
        $data['nationality'] ?? null,
        $data['mother_tongue'] ?? null,
        $data['current_address'] ?? null,
        $data['permanent_address'] ?? null,
        $data['previous_school'] ?? null,
        $data['previous_class'] ?? null,
        $data['transfer_certificate_no'] ?? null,
        $data['blood_group'] ?? null,
        $data['allergies'] ?? null,
        $data['medical_conditions'] ?? null,
        $data['doctor_name'] ?? null,
        $data['doctor_phone'] ?? null
    ]);
    
    $studentId = $db->lastInsertId();
    
    // Store password in session for potential email notification
    if (!isset($_SESSION['imported_passwords'])) {
        $_SESSION['imported_passwords'] = [];
    }
    $_SESSION['imported_passwords'][$userId] = $password;
    
    return ['success' => true, 'message' => "Student imported successfully (ID: $studentId)"];
}

// Import function for Guardians (Parents)
function importGuardian($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['email'])) {
        return ['success' => false, 'message' => 'Name and email are required'];
    }
    
    // Check if email exists
    $checkStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
    $checkStmt->execute([$schoolId, $data['email']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Email already exists'];
    }
    
    // Generate username from email
    $username = explode('@', $data['email'])[0];
    
    // Generate random password
    $password = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert parent user
    $stmt = $db->prepare("
        INSERT INTO users (
            school_id, name, email, phone, username, password, user_type,
            address, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'parent', ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['name'],
        $data['email'],
        $data['phone'] ?? null,
        $username,
        $hashedPassword,
        $data['address'] ?? null
    ]);
    
    $parentId = $db->lastInsertId();
    
    // Assign parent role (role_id = 5)
    $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 5, NOW())");
    $roleStmt->execute([$parentId]);
    
    // Store password
    if (!isset($_SESSION['imported_passwords'])) {
        $_SESSION['imported_passwords'] = [];
    }
    $_SESSION['imported_passwords'][$parentId] = $password;
    
    return ['success' => true, 'message' => "Guardian imported successfully (ID: $parentId)"];
}

// Import function for Guardian-Student Relationships
function importGuardianLink($db, $data, $schoolId) {
    if (empty($data['guardian_email']) || empty($data['student_admission_number']) || empty($data['relationship'])) {
        return ['success' => false, 'message' => 'Guardian email, student admission number, and relationship are required'];
    }
    
    // Find guardian by email
    $guardianStmt = $db->prepare("
        SELECT u.id FROM users u
        WHERE u.school_id = ? AND u.email = ? AND u.user_type = 'parent'
    ");
    $guardianStmt->execute([$schoolId, $data['guardian_email']]);
    $guardian = $guardianStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$guardian) {
        return ['success' => false, 'message' => 'Guardian not found with email: ' . $data['guardian_email']];
    }
    
    // Find student by admission number
    $studentStmt = $db->prepare("
        SELECT s.id FROM students s
        WHERE s.school_id = ? AND s.admission_number = ?
    ");
    $studentStmt->execute([$schoolId, $data['student_admission_number']]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        return ['success' => false, 'message' => 'Student not found with admission number: ' . $data['student_admission_number']];
    }
    
    // Check if relationship already exists
    $checkStmt = $db->prepare("
        SELECT id FROM guardians 
        WHERE school_id = ? AND user_id = ? AND student_id = ?
    ");
    $checkStmt->execute([$schoolId, $guardian['id'], $student['id']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Guardian-student relationship already exists'];
    }
    
    // Insert guardian relationship
    $relationship = $data['relationship'];
    $isPrimary = isset($data['is_primary']) ? (int)$data['is_primary'] : 0;
    $canPickup = isset($data['can_pickup']) ? (int)$data['can_pickup'] : 1;
    $emergencyContact = isset($data['emergency_contact']) ? (int)$data['emergency_contact'] : 0;
    
    $stmt = $db->prepare("
        INSERT INTO guardians (
            school_id, user_id, student_id, relationship, is_primary, can_pickup, emergency_contact
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $schoolId,
        $guardian['id'],
        $student['id'],
        $relationship,
        $isPrimary,
        $canPickup,
        $emergencyContact
    ]);
    
    return ['success' => true, 'message' => 'Guardian-student relationship created successfully'];
}

// Import function for Teachers
function importTeacher($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['email']) || empty($data['employee_id'])) {
        return ['success' => false, 'message' => 'Name, email, and employee ID are required'];
    }
    
    // Check if email exists
    $checkStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
    $checkStmt->execute([$schoolId, $data['email']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Email already exists'];
    }
    
    // Check if employee ID exists
    $checkEmpStmt = $db->prepare("SELECT id FROM teachers WHERE school_id = ? AND employee_id = ?");
    $checkEmpStmt->execute([$schoolId, $data['employee_id']]);
    if ($checkEmpStmt->fetch()) {
        return ['success' => false, 'message' => 'Employee ID already exists'];
    }
    
    // Generate username
    $username = explode('@', $data['email'])[0];
    
    // Generate random password
    $password = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert teacher user
    $userStmt = $db->prepare("
        INSERT INTO users (
            school_id, name, email, phone, username, password, user_type,
            gender, date_of_birth, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, ?, 1, NOW())
    ");
    
    $userStmt->execute([
        $schoolId,
        $data['name'],
        $data['email'],
        $data['phone'] ?? null,
        $username,
        $hashedPassword,
        $data['gender'] ?? null,
        $data['date_of_birth'] ?? null
    ]);
    
    $userId = $db->lastInsertId();
    
    // Assign teacher role (role_id = 3)
    $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 3, NOW())");
    $roleStmt->execute([$userId]);
    
    // Insert teacher record
    $teacherStmt = $db->prepare("
        INSERT INTO teachers (
            school_id, user_id, employee_id, qualification, specialization,
            experience_years, joining_date, salary_grade, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    
    $teacherStmt->execute([
        $schoolId,
        $userId,
        $data['employee_id'],
        $data['qualification'] ?? null,
        $data['specialization'] ?? null,
        $data['experience_years'] ?? null,
        $data['joining_date'] ?? null,
        $data['salary_grade'] ?? null
    ]);
    
    // Store password
    if (!isset($_SESSION['imported_passwords'])) {
        $_SESSION['imported_passwords'] = [];
    }
    $_SESSION['imported_passwords'][$userId] = $password;
    
    return ['success' => true, 'message' => 'Teacher imported successfully'];
}

// Import function for Classes
function importClass($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['code']) || empty($data['academic_year_id'])) {
        return ['success' => false, 'message' => 'Name, code, and academic year are required'];
    }
    
    // Check if code exists
    $checkStmt = $db->prepare("SELECT id FROM classes WHERE school_id = ? AND code = ? AND academic_year_id = ?");
    $checkStmt->execute([$schoolId, $data['code'], $data['academic_year_id']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Class code already exists for this academic year'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO classes (
            school_id, name, code, description, grade_level, capacity, room_number,
            academic_year_id, class_teacher_id, campus_id, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['name'],
        $data['code'],
        $data['description'] ?? null,
        $data['grade_level'] ?? null,
        $data['capacity'] ?? 40,
        $data['room_number'] ?? null,
        $data['academic_year_id'],
        $data['class_teacher_id'] ?? null,
        $data['campus_id'] ?? null
    ]);
    
    return ['success' => true, 'message' => 'Class imported successfully'];
}

// Import function for Sections
function importSection($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['code']) || empty($data['class_id'])) {
        return ['success' => false, 'message' => 'Name, code, and class are required'];
    }
    
    // Check if code exists for this class
    $checkStmt = $db->prepare("SELECT id FROM sections WHERE school_id = ? AND code = ? AND class_id = ?");
    $checkStmt->execute([$schoolId, $data['code'], $data['class_id']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Section code already exists for this class'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO sections (
            school_id, class_id, name, code, capacity, room_number,
            class_teacher_id, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['class_id'],
        $data['name'],
        $data['code'],
        $data['capacity'] ?? 40,
        $data['room_number'] ?? null,
        $data['class_teacher_id'] ?? null
    ]);
    
    return ['success' => true, 'message' => 'Section imported successfully'];
}

// Import function for Subjects
function importSubject($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['code'])) {
        return ['success' => false, 'message' => 'Name and code are required'];
    }
    
    // Check if code exists
    $checkStmt = $db->prepare("SELECT id FROM subjects WHERE school_id = ? AND code = ?");
    $checkStmt->execute([$schoolId, $data['code']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Subject code already exists'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO subjects (
            school_id, name, code, type, description, credit_hours, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['name'],
        $data['code'],
        $data['type'] ?? 'core',
        $data['description'] ?? null,
        $data['credit_hours'] ?? 1.0
    ]);
    
    return ['success' => true, 'message' => 'Subject imported successfully'];
}

// Import function for Academic Years
function importAcademicYear($db, $data, $schoolId) {
    if (empty($data['name']) || empty($data['start_date']) || empty($data['end_date'])) {
        return ['success' => false, 'message' => 'Name, start date, and end date are required'];
    }
    
    // Check if name exists
    $checkStmt = $db->prepare("SELECT id FROM academic_years WHERE school_id = ? AND name = ?");
    $checkStmt->execute([$schoolId, $data['name']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Academic year name already exists'];
    }
    
    // If this is set as default, remove default from others
    if (!empty($data['is_default']) && $data['is_default'] == 1) {
        $resetStmt = $db->prepare("UPDATE academic_years SET is_default = 0 WHERE school_id = ?");
        $resetStmt->execute([$schoolId]);
    }
    
    $stmt = $db->prepare("
        INSERT INTO academic_years (
            school_id, name, start_date, end_date, status, is_default, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['name'],
        $data['start_date'],
        $data['end_date'],
        $data['status'] ?? 'upcoming',
        $data['is_default'] ?? 0
    ]);
    
    return ['success' => true, 'message' => 'Academic year imported successfully'];
}

// Import function for Fee Categories
function importFeeCategory($db, $data, $schoolId) {
    if (empty($data['name'])) {
        return ['success' => false, 'message' => 'Name is required'];
    }
    
    // Check if name exists
    $checkStmt = $db->prepare("SELECT id FROM fee_categories WHERE school_id = ? AND name = ?");
    $checkStmt->execute([$schoolId, $data['name']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Fee category name already exists'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO fee_categories (
            school_id, name, description, is_active, created_at
        ) VALUES (?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['name'],
        $data['description'] ?? null
    ]);
    
    return ['success' => true, 'message' => 'Fee category imported successfully'];
}

// Import function for Fee Structures
function importFeeStructure($db, $data, $schoolId) {
    if (empty($data['class_id']) || empty($data['fee_category_id']) || 
        empty($data['amount']) || empty($data['academic_year_id'])) {
        return ['success' => false, 'message' => 'Class, fee category, amount, and academic year are required'];
    }
    
    // Check if fee structure already exists
    $checkStmt = $db->prepare("
        SELECT id FROM fee_structures 
        WHERE school_id = ? AND class_id = ? AND fee_category_id = ? AND academic_year_id = ?
    ");
    $checkStmt->execute([$schoolId, $data['class_id'], $data['fee_category_id'], $data['academic_year_id']]);
    if ($checkStmt->fetch()) {
        return ['success' => false, 'message' => 'Fee structure already exists for this class, category, and year'];
    }
    
    $stmt = $db->prepare("
        INSERT INTO fee_structures (
            school_id, class_id, fee_category_id, amount, academic_year_id,
            academic_term_id, due_date, late_fee, is_active, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $schoolId,
        $data['class_id'],
        $data['fee_category_id'],
        $data['amount'],
        $data['academic_year_id'],
        $data['academic_term_id'] ?? null,
        $data['due_date'] ?? null,
        $data['late_fee'] ?? 0.00
    ]);
    
    return ['success' => true, 'message' => 'Fee structure imported successfully'];
}

function getUploadErrorMessage($error) {
    switch ($error) {
        case UPLOAD_ERR_INI_SIZE:
            return "File exceeds upload_max_filesize";
        case UPLOAD_ERR_FORM_SIZE:
            return "File exceeds MAX_FILE_SIZE directive";
        case UPLOAD_ERR_PARTIAL:
            return "File was only partially uploaded";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing temporary folder";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk";
        case UPLOAD_ERR_EXTENSION:
            return "File upload stopped by extension";
        default:
            return "Unknown upload error";
    }
}

// Generate CSRF token
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
$csrfToken = generateCsrfToken();

error_log("=== IMPORT DATA PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Import Data - School Management System">
    <meta name="keywords" content="Import Data, Bulk Upload, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Data - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .import-progress {
            display: none;
            margin-top: 20px;
        }
        .progress {
            height: 30px;
            border-radius: 15px;
        }
        .progress-bar {
            line-height: 30px;
            font-weight: 600;
            transition: width 0.3s ease;
        }
        .import-stats {
            display: flex;
            gap: 20px;
            margin: 15px 0;
        }
        .stat-card {
            flex: 1;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        .stat-card.success {
            background: #d4edda;
            color: #155724;
        }
        .stat-card.failed {
            background: #f8d7da;
            color: #721c24;
        }
        .stat-number {
            font-size: 24px;
            font-weight: 700;
        }
        .template-card {
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .template-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .table-badge {
            background: #25A194;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .required-field {
            color: #dc3545;
            font-size: 12px;
        }
        .optional-field {
            color: #6c757d;
            font-size: 12px;
        }
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 10px;
            margin-top: 15px;
        }
        .error-item {
            color: #721c24;
            font-size: 13px;
            padding: 5px 0;
            border-bottom: 1px solid #f5c6cb;
        }
        .error-item:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

<!-- Theme Customization Structure (keep as is) -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Import Data</h1>
                <div>
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light"> / Import Data</span>
                </div>
            </div>
        </div>

        <!-- Import Results -->
        <?php if (!empty($importResults)): ?>
        <div class="alert <?php echo $importResults['failed'] > 0 ? 'alert-warning' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
            <h5 class="alert-heading">Import Results</h5>
            <p>Table: <strong><?php echo ucfirst($importResults['table']); ?></strong></p>
            <div class="import-stats">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $importResults['total']; ?></div>
                    <div>Total Records</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?php echo $importResults['success']; ?></div>
                    <div>Successful</div>
                </div>
                <div class="stat-card failed">
                    <div class="stat-number"><?php echo $importResults['failed']; ?></div>
                    <div>Failed</div>
                </div>
            </div>
            <?php if (!empty($importResults['errors'])): ?>
            <div class="error-list">
                <strong>Errors:</strong>
                <?php foreach ($importResults['errors'] as $error): ?>
                <div class="error-item"><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <p class="mb-0 mt-2">Execution time: <?php echo $importResults['execution_time']; ?> seconds</p>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Main Import Section -->
        <div class="row">
            <!-- Template Downloads -->
            <div class="col-lg-5">
                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                    <div class="card-header border-bottom bg-base py-16 px-24">
                        <h6 class="text-lg fw-semibold mb-0">Download Templates</h6>
                        <p class="text-secondary-light mb-0">Choose a table to download import template</p>
                    </div>
                    <div class="card-body p-20">
                        <ul class="nav nav-pills mb-20" id="templateTabs" role="tablist">
                            <?php $first = true; ?>
                            <?php foreach ($importTables as $key => $table): ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                                        id="tab-<?php echo $key; ?>" 
                                        data-bs-toggle="pill" 
                                        data-bs-target="#content-<?php echo $key; ?>" 
                                        type="button" 
                                        role="tab">
                                    <?php echo $table['name']; ?>
                                </button>
                                <?php $first = false; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div class="tab-content">
                            <?php $first = true; ?>
                            <?php foreach ($importTables as $key => $table): ?>
                            <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                                 id="content-<?php echo $key; ?>" 
                                 role="tabpanel">
                                <div class="template-card">
                                    <span class="table-badge mb-3"><?php echo $table['name']; ?></span>
                                    
                                    <div class="mb-3">
                                        <strong>Required Fields:</strong>
                                        <div class="mt-2">
                                            <?php foreach ($table['required_fields'] as $field): ?>
                                            <span class="required-field me-2"><?php echo str_replace('_', ' ', ucfirst($field)); ?> *</span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <strong>Optional Fields:</strong>
                                        <div class="mt-2">
                                            <?php foreach ($table['optional_fields'] as $field): ?>
                                            <span class="optional-field me-2"><?php echo str_replace('_', ' ', ucfirst($field)); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-20">
                                        <strong>Sample Format:</strong>
                                        <div class="table-responsive mt-2">
                                            <table class="table table-sm table-bordered">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <?php foreach ($table['sample_data'][0] as $header): ?>
                                                        <th><?php echo str_replace('_', ' ', ucfirst($header)); ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php for ($i = 1; $i < count($table['sample_data']); $i++): ?>
                                                    <tr>
                                                        <?php foreach ($table['sample_data'][$i] as $cell): ?>
                                                        <td><?php echo htmlspecialchars($cell); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <?php endfor; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-3">
                                        <a href="?download_template=<?php echo $key; ?>&format=csv" 
                                           class="btn btn-primary-600 flex-grow-1">
                                            <i class="ri-file-excel-line me-2"></i>Download CSV
                                        </a>
                                        <a href="?download_template=<?php echo $key; ?>&format=excel" 
                                           class="btn btn-success-600 flex-grow-1">
                                            <i class="ri-file-excel-line me-2"></i>Download Excel
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php $first = false; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Section -->
            <div class="col-lg-7">
                <div class="shadow-1 radius-12 bg-base h-100 overflow-hidden">
                    <div class="card-header border-bottom bg-base py-16 px-24">
                        <h6 class="text-lg fw-semibold mb-0">Upload Data</h6>
                        <p class="text-secondary-light mb-0">Upload CSV file with your data</p>
                    </div>
                    <div class="card-body p-20">
                        <form method="POST" enctype="multipart/form-data" id="importForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            
                            <div class="mb-20">
                                <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                    Select Table
                                </label>
                                <select name="import_table" class="form-control form-select" required>
                                    <option value="">Choose table to import into</option>
                                    <?php foreach ($importTables as $key => $table): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $currentTable == $key ? 'selected' : ''; ?>>
                                        <?php echo $table['name']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-20">
                                <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                                    Upload CSV File
                                </label>
                                <div class="drop-zone p-20 text-center border border-neutral-400 radius-8 border-dashed bg-hover-neutral-200">
                                    <i class="ri-upload-cloud-line text-xxl text-secondary-light mb-3"></i>
                                    <p class="mb-2">Drag & drop your CSV file here or click to browse</p>
                                    <p class="text-secondary-light text-sm mb-0">Supported format: .csv (Max size: 10MB)</p>
                                    <input type="file" name="import_file" class="drop-zone__input" accept=".csv" required>
                                </div>
                            </div>
                            
                            <div class="mb-20">
                                <label class="checkbox-container">
                                    <input type="checkbox" name="skip_errors" value="1">
                                    <span class="checkmark"></span>
                                    Skip rows with errors and continue import
                                </label>
                            </div>
                            
                            <div class="import-progress">
                                <h6 class="fw-semibold mb-10">Import Progress</h6>
                                <div class="progress">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" 
                                         style="width: 0%"
                                         id="importProgress">0%</div>
                                </div>
                                <div class="d-flex justify-content-between mt-2 text-sm">
                                    <span id="currentCount">0</span>
                                    <span id="totalCount">0</span>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-6">
                                        <div class="stat-card success p-2">
                                            <span id="successCount">0</span> Successful
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="stat-card failed p-2">
                                            <span id="failedCount">0</span> Failed
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex gap-3">
                                <button type="submit" class="btn btn-primary-600 flex-grow-1" id="startImport">
                                    <i class="ri-upload-line me-2"></i>Start Import
                                </button>
                                <button type="reset" class="btn btn-outline-secondary flex-grow-1">
                                    <i class="ri-refresh-line me-2"></i>Reset
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-20 p-16 bg-neutral-50 radius-8">
                            <h6 class="fw-semibold mb-2">📌 Import Guidelines</h6>
                            <ul class="text-secondary-light text-sm mb-0">
                                <li>✓ Download the template for your chosen table first</li>
                                <li>✓ Fill in the data following the sample format</li>
                                <li>✓ All required fields (*) must be filled</li>
                                <li>✓ Save your file as CSV format</li>
                                <li>✓ Maximum file size: 10MB</li>
                                <li>✓ Maximum rows per import: 5000</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Imports -->
        <?php if ($schoolDb): 
            $recentStmt = $schoolDb->prepare("
                SELECT * FROM audit_logs 
                WHERE school_id = ? AND action = 'import' 
                ORDER BY created_at DESC LIMIT 10
            ");
            $recentStmt->execute([$school['id']]);
            $recentImports = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($recentImports)): ?>
        <div class="row mt-24">
            <div class="col-12">
                <div class="shadow-1 radius-12 bg-base overflow-hidden">
                    <div class="card-header border-bottom bg-base py-16 px-24">
                        <h6 class="text-lg fw-semibold mb-0">Recent Imports</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table bordered-table mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Table</th>
                                    <th>Records</th>
                                    <th>Success</th>
                                    <th>Failed</th>
                                    <th>User</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentImports as $import): 
                                    $details = json_decode($import['new_values'], true);
                                ?>
                                <tr>
                                    <td><?php echo date('d M Y H:i', strtotime($import['created_at'])); ?></td>
                                    <td><?php echo ucfirst($import['entity_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo $details['total'] ?? 0; ?></td>
                                    <td class="text-success"><?php echo $details['success'] ?? 0; ?></td>
                                    <td class="text-danger"><?php echo $details['failed'] ?? 0; ?></td>
                                    <td><?php echo htmlspecialchars($import['user_type'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Drag & drop upload
    document.querySelectorAll(".drop-zone__input").forEach((inputElement) => {
        const dropZoneElement = inputElement.closest(".drop-zone");

        dropZoneElement.addEventListener("click", (e) => {
            inputElement.click();
        });

        dropZoneElement.addEventListener("dragover", (e) => {
            e.preventDefault();
            dropZoneElement.classList.add("drop-zone--over");
        });

        ["dragleave", "dragend"].forEach((type) => {
            dropZoneElement.addEventListener(type, (e) => {
                dropZoneElement.classList.remove("drop-zone--over");
            });
        });

        dropZoneElement.addEventListener("drop", (e) => {
            e.preventDefault();
            if (e.dataTransfer.files.length) {
                inputElement.files = e.dataTransfer.files;
                updateFileName(dropZoneElement, e.dataTransfer.files[0].name);
            }
            dropZoneElement.classList.remove("drop-zone--over");
        });

        inputElement.addEventListener("change", (e) => {
            if (inputElement.files.length) {
                updateFileName(dropZoneElement, inputElement.files[0].name);
            }
        });
    });

    function updateFileName(dropZoneElement, fileName) {
        let promptElement = dropZoneElement.querySelector(".drop-zone__prompt");
        if (promptElement) {
            promptElement.innerHTML = `<strong>Selected:</strong> ${fileName}`;
        } else {
            dropZoneElement.innerHTML = `<p class="mb-0"><strong>Selected:</strong> ${fileName}</p>`;
        }
    }

    // Import form submission with progress
    $('#importForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('ajax', '1');
        
        // Show progress bar
        $('.import-progress').show();
        $('#startImport').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Importing...');
        
        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = (e.loaded / e.total) * 100;
                        $('#importProgress').css('width', percentComplete + '%').text(Math.round(percentComplete) + '%');
                    }
                });
                
                xhr.addEventListener('progress', function(e) {
                    // Handle response progress if needed
                });
                
                return xhr;
            },
            success: function(response) {
                // Parse response and update stats
                try {
                    const data = typeof response === 'string' ? JSON.parse(response) : response;
                    if (data.progress) {
                        $('#importProgress').css('width', data.progress + '%').text(data.progress + '%');
                        $('#currentCount').text(data.current);
                        $('#totalCount').text(data.total);
                        $('#successCount').text(data.success);
                        $('#failedCount').text(data.failed);
                    }
                } catch (e) {
                    // Complete response - reload page to show results
                    location.reload();
                }
            },
            error: function(xhr, status, error) {
                alert('Error during import: ' + error);
                $('#startImport').prop('disabled', false).html('<i class="ri-upload-line me-2"></i>Start Import');
            }
        });
    });

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>

</body>
</html>