<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

// Set JSON header first
header('Content-Type: application/json');

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Validate CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken) || !isset($_SESSION['csrf_tokens'][$csrfToken]) || $_SESSION['csrf_tokens'][$csrfToken] < time()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}

// Get parameters
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
$tableName = $_POST['table_name'] ?? '';
$skipDuplicates = isset($_POST['skip_duplicates']) && $_POST['skip_duplicates'] === 'true';
$hasHeaders = isset($_POST['has_headers']) && $_POST['has_headers'] === 'true';

if ($schoolId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid school ID']);
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
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
try {
    $schoolDb = Database::getSchoolConnection($school['database_name']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Cannot connect to school database: ' . $e->getMessage()]);
    exit;
}

// Process CSV file
$file = $_FILES['csv_file'];
$filePath = $file['tmp_name'];
$originalName = $file['name'];

// Validate file extension
$fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed']);
    exit;
}

// Parse CSV
$records = parseCSVFile($filePath, $hasHeaders);

if (empty($records)) {
    echo json_encode(['success' => false, 'message' => 'No valid data found in CSV file']);
    exit;
}

// Table-specific import handlers
switch ($tableName) {
    case 'users':
        $result = importUsers($schoolDb, $schoolId, $records, $skipDuplicates);
        break;
    case 'admins':
        $result = importUsersAsType($schoolDb, $schoolId, $records, $skipDuplicates, 'admin');
        break;
    case 'parents':
        $result = importUsersAsType($schoolDb, $schoolId, $records, $skipDuplicates, 'parent');
        break;
    case 'students':
        $result = importStudents($schoolDb, $schoolId, $records, $skipDuplicates);
        break;
    case 'teachers':
        $result = importTeachers($schoolDb, $schoolId, $records, $skipDuplicates);
        break;
    case 'classes':
        $result = importClasses($schoolDb, $schoolId, $records, $skipDuplicates);
        break;
    case 'subjects':
        $result = importSubjects($schoolDb, $schoolId, $records, $skipDuplicates);
        break;
    case 'academic_years':
    case 'academic_terms':
    case 'attendance':
    case 'exams':
    case 'exam_grades':
    case 'homework':
        $result = importGenericTable($schoolDb, $schoolId, $tableName, $records, $skipDuplicates);
        break;
    case 'all':
        echo json_encode(['success' => false, 'message' => 'Full CSV imports are not supported. Import one table at a time so columns can be validated safely.']);
        exit;
    default:
        echo json_encode(['success' => false, 'message' => 'Unsupported table: ' . $tableName]);
        exit;
}

// Return result
echo json_encode($result);
exit;

// CSV parsing function
function parseCSVFile($filePath, $hasHeaders = true) {
    $records = [];
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        $headers = [];
        $firstRow = true;
        $rowNumber = 0;
        
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            
            // Skip empty rows
            if (empty(array_filter($row, function($value) { 
                return $value !== null && $value !== ''; 
            }))) {
                continue;
            }
            
            if ($firstRow && $hasHeaders) {
                $headers = array_map('trim', $row);
                $firstRow = false;
                continue;
            }
            
            if ($hasHeaders && !empty($headers)) {
                // Combine headers with row data
                if (count($row) === count($headers)) {
                    $record = [];
                    foreach ($headers as $index => $header) {
                        $record[$header] = $row[$index] ?? '';
                    }
                    $records[] = $record;
                } else {
                    // Log mismatch but continue
                    error_log("CSV row $rowNumber has " . count($row) . " columns, expected " . count($headers));
                }
            } else {
                // Use numeric keys
                $records[] = $row;
            }
        }
        
        fclose($handle);
    }
    
    return $records;
}

// Import users
function importUsers($db, $schoolId, $records, $skipDuplicates) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($records as $index => $row) {
            try {
                // Check for duplicate
                if ($skipDuplicates && isset($row['email']) && !empty($row['email'])) {
                    $checkStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
                    $checkStmt->execute([$schoolId, $row['email']]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                // Prepare data
                $data = [
                    'school_id' => $schoolId,
                    'name' => trim($row['name'] ?? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))),
                    'email' => !empty($row['email']) ? $row['email'] : null,
                    'phone' => !empty($row['phone']) ? $row['phone'] : null,
                    'username' => !empty($row['username']) ? $row['username'] : null,
                    'password' => password_hash($row['password'] ?? 'password123', PASSWORD_DEFAULT),
                    'user_type' => $row['user_type'] ?? 'teacher',
                    'gender' => $row['gender'] ?? null,
                    'date_of_birth' => !empty($row['date_of_birth']) ? $row['date_of_birth'] : null,
                    'blood_group' => $row['blood_group'] ?? null,
                    'religion' => $row['religion'] ?? null,
                    'address' => $row['address'] ?? null,
                    'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'users', $data);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Imported $imported users, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'errors' => [$e->getMessage()]
        ];
    }
}

function importUsersAsType($db, $schoolId, $records, $skipDuplicates, $userType) {
    foreach ($records as &$row) {
        $row['user_type'] = $userType;
    }
    unset($row);

    return importUsers($db, $schoolId, $records, $skipDuplicates);
}

// Import teachers
function importTeachers($db, $schoolId, $records, $skipDuplicates) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($records as $index => $row) {
            try {
                // First, check if we should skip duplicates
                $skipThis = false;
                if ($skipDuplicates) {
                    if (isset($row['employee_id']) && !empty($row['employee_id'])) {
                        $checkStmt = $db->prepare("SELECT id FROM teachers WHERE school_id = ? AND employee_id = ?");
                        $checkStmt->execute([$schoolId, $row['employee_id']]);
                        if ($checkStmt->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }
                    
                    if (isset($row['email']) && !empty($row['email'])) {
                        $checkStmt = $db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
                        $checkStmt->execute([$schoolId, $row['email']]);
                        if ($checkStmt->fetch()) {
                            $skipped++;
                            continue;
                        }
                    }
                }
                
                // Create user record first
                $userData = [
                    'school_id' => $schoolId,
                    'name' => trim($row['name'] ?? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? 'Teacher'))),
                    'email' => !empty($row['email']) ? $row['email'] : null,
                    'phone' => !empty($row['phone']) ? $row['phone'] : null,
                    'username' => !empty($row['username']) ? $row['username'] : null,
                    'password' => password_hash($row['password'] ?? 'teacher123', PASSWORD_DEFAULT),
                    'user_type' => 'teacher',
                    'gender' => $row['gender'] ?? null,
                    'date_of_birth' => !empty($row['date_of_birth']) ? $row['date_of_birth'] : null,
                    'blood_group' => $row['blood_group'] ?? null,
                    'religion' => $row['religion'] ?? null,
                    'address' => $row['address'] ?? null,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'users', $userData);
                $userId = $db->lastInsertId();
                
                // Now create teacher record
                $teacherData = [
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'employee_id' => $row['employee_id'] ?? 'TCH-' . str_pad($imported + 1, 3, '0', STR_PAD_LEFT),
                    'qualification' => $row['qualification'] ?? null,
                    'specialization' => $row['specialization'] ?? null,
                    'experience_years' => isset($row['experience_years']) ? (int)$row['experience_years'] : null,
                    'joining_date' => !empty($row['joining_date']) ? $row['joining_date'] : date('Y-m-d'),
                    'leaving_date' => !empty($row['leaving_date']) ? $row['leaving_date'] : null,
                    'salary_grade' => $row['salary_grade'] ?? null,
                    'bank_name' => $row['bank_name'] ?? null,
                    'bank_account' => $row['bank_account'] ?? null,
                    'ifsc_code' => $row['ifsc_code'] ?? null,
                    'is_active' => 1
                ];
                
                insertDynamic($db, 'teachers', $teacherData);
                
                // Assign teacher role
                assignRoleIfPossible($db, $userId, 3);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Imported $imported teachers, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'errors' => [$e->getMessage()]
        ];
    }
}

// Import students
function importStudents($db, $schoolId, $records, $skipDuplicates) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($records as $index => $row) {
            try {
                // Check for duplicate
                if ($skipDuplicates && isset($row['admission_number']) && !empty($row['admission_number'])) {
                    $checkStmt = $db->prepare("SELECT id FROM students WHERE school_id = ? AND admission_number = ?");
                    $checkStmt->execute([$schoolId, $row['admission_number']]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                // Create user record
                $userData = [
                    'school_id' => $schoolId,
                    'name' => ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''),
                    'email' => !empty($row['email']) ? $row['email'] : null,
                    'phone' => !empty($row['phone']) ? $row['phone'] : null,
                    'username' => $row['admission_number'] ?? null,
                    'password' => password_hash($row['password'] ?? 'student123', PASSWORD_DEFAULT),
                    'user_type' => 'student',
                    'gender' => $row['gender'] ?? null,
                    'date_of_birth' => !empty($row['date_of_birth']) ? $row['date_of_birth'] : null,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'users', $userData);
                $userId = $db->lastInsertId();
                
                // Create student record
                $studentData = [
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'admission_number' => $row['admission_number'] ?? '',
                    'roll_number' => $row['roll_number'] ?? null,
                    'class_id' => isset($row['class_id']) ? (int)$row['class_id'] : null,
                    'section_id' => isset($row['section_id']) ? (int)$row['section_id'] : null,
                    'admission_date' => !empty($row['admission_date']) ? $row['admission_date'] : date('Y-m-d'),
                    'first_name' => $row['first_name'] ?? '',
                    'middle_name' => $row['middle_name'] ?? null,
                    'last_name' => $row['last_name'] ?? '',
                    'date_of_birth' => !empty($row['date_of_birth']) ? $row['date_of_birth'] : null,
                    'current_address' => $row['current_address'] ?? null,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'students', $studentData);
                
                // Assign student role
                assignRoleIfPossible($db, $userId, 4);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Imported $imported students, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'errors' => [$e->getMessage()]
        ];
    }
}

// Import classes
function importClasses($db, $schoolId, $records, $skipDuplicates) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($records as $index => $row) {
            try {
                // Check for duplicate
                if ($skipDuplicates && isset($row['code']) && !empty($row['code'])) {
                    $checkStmt = $db->prepare("SELECT id FROM classes WHERE school_id = ? AND code = ? AND academic_year_id = ?");
                    $academicYearId = isset($row['academic_year_id']) ? (int)$row['academic_year_id'] : 1;
                    $checkStmt->execute([$schoolId, $row['code'], $academicYearId]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                $data = [
                    'school_id' => $schoolId,
                    'name' => $row['name'] ?? '',
                    'code' => $row['code'] ?? '',
                    'description' => $row['description'] ?? null,
                    'grade_level' => $row['grade_level'] ?? null,
                    'class_teacher_id' => isset($row['class_teacher_id']) ? (int)$row['class_teacher_id'] : null,
                    'capacity' => isset($row['capacity']) ? (int)$row['capacity'] : 40,
                    'room_number' => $row['room_number'] ?? null,
                    'academic_year_id' => isset($row['academic_year_id']) ? (int)$row['academic_year_id'] : 1,
                    'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'classes', $data);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Imported $imported classes, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'errors' => [$e->getMessage()]
        ];
    }
}

// Import subjects
function importSubjects($db, $schoolId, $records, $skipDuplicates) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    try {
        $db->beginTransaction();
        
        foreach ($records as $index => $row) {
            try {
                // Check for duplicate
                if ($skipDuplicates && isset($row['code']) && !empty($row['code'])) {
                    $checkStmt = $db->prepare("SELECT id FROM subjects WHERE school_id = ? AND code = ?");
                    $checkStmt->execute([$schoolId, $row['code']]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                $data = [
                    'school_id' => $schoolId,
                    'name' => $row['name'] ?? '',
                    'code' => $row['code'] ?? '',
                    'type' => $row['type'] ?? 'core',
                    'description' => $row['description'] ?? null,
                    'credit_hours' => isset($row['credit_hours']) ? (float)$row['credit_hours'] : 1.0,
                    'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : 1,
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                insertDynamic($db, 'subjects', $data);
                
                $imported++;
                
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $db->commit();
        
        return [
            'success' => true,
            'message' => "Imported $imported subjects, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage(),
            'errors' => [$e->getMessage()]
        ];
    }
}

function importGenericTable($db, $schoolId, $tableName, $records, $skipDuplicates) {
    $allowedTables = ['academic_years', 'academic_terms', 'attendance', 'exams', 'exam_grades', 'homework'];
    if (!in_array($tableName, $allowedTables, true) || !tableExists($db, $tableName)) {
        return ['success' => false, 'message' => 'Unsupported or missing table: ' . $tableName];
    }

    $imported = 0;
    $skipped = 0;
    $errors = [];

    try {
        $db->beginTransaction();
        foreach ($records as $index => $row) {
            try {
                $data = $row;
                $columns = getTableColumns($db, $tableName);
                if (in_array('school_id', $columns, true)) {
                    $data['school_id'] = $schoolId;
                }
                if (in_array('created_at', $columns, true) && empty($data['created_at'])) {
                    $data['created_at'] = date('Y-m-d H:i:s');
                }
                if (in_array('updated_at', $columns, true) && empty($data['updated_at'])) {
                    $data['updated_at'] = date('Y-m-d H:i:s');
                }

                if ($skipDuplicates && !empty($data['id'])) {
                    $checkStmt = $db->prepare("SELECT id FROM `$tableName` WHERE id = ?");
                    $checkStmt->execute([$data['id']]);
                    if ($checkStmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }

                insertDynamic($db, $tableName, $data);
                $imported++;
            } catch (Exception $e) {
                $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        $db->commit();

        return [
            'success' => true,
            'message' => "Imported $imported records into $tableName, $skipped skipped",
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'message' => 'Import failed: ' . $e->getMessage(), 'errors' => [$e->getMessage()]];
    }
}

function insertDynamic($db, $tableName, array $data) {
    $columns = getTableColumns($db, $tableName);
    $data = array_intersect_key($data, array_flip($columns));
    $data = array_filter($data, static function ($value) {
        return $value !== '';
    });

    if (empty($data)) {
        throw new Exception("No matching columns found for $tableName");
    }

    $columnSql = '`' . implode('`, `', array_keys($data)) . '`';
    $placeholders = ':' . implode(', :', array_keys($data));
    $stmt = $db->prepare("INSERT INTO `$tableName` ($columnSql) VALUES ($placeholders)");
    $stmt->execute($data);
}

function getTableColumns($db, $tableName) {
    $stmt = $db->query("SHOW COLUMNS FROM `$tableName`");
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

function tableExists($db, $tableName) {
    $stmt = $db->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function assignRoleIfPossible($db, $userId, $roleId) {
    if (!tableExists($db, 'user_roles')) {
        return;
    }
    try {
        $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        $roleStmt->execute([$userId, $roleId]);
    } catch (Exception $e) {
        // Role assignment is optional for older tenant databases.
    }
}
?>
