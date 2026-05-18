<?php
require_once __DIR__ . '/../../../../includes/autoload.php';

// Verify CSRF token
session_start();
if (!isset($_SESSION['csrf_tokens'][$_POST['csrf_token'] ?? '']) || 
    $_SESSION['csrf_tokens'][$_POST['csrf_token']] < time()) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token']);
    exit;
}

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $schoolId = $data['school_id'] ?? 0;
    $databaseName = $data['database_name'] ?? '';
    $includeSampleData = $data['include_sample_data'] ?? 'none';
    $advanced = $data['advanced'] ?? false;
    
    if ($schoolId <= 0) {
        throw new Exception('Invalid school ID');
    }
    
    // Get school data
    $db = Database::getPlatformConnection();
    $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$schoolId]);
    $school = $stmt->fetch();
    
    if (!$school) {
        throw new Exception('School not found');
    }
    
    // Check if database already exists
    if (Database::schoolDatabaseExists($databaseName)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database already exists',
            'database_name' => $databaseName
        ]);
        exit;
    }
    
    // Admin credentials for initial setup
    $adminData = [
        'id' => $schoolId,
        'admin_name' => $school['admin_name'] ?? 'School Admin',
        'admin_email' => $school['email'],
        'admin_phone' => $school['phone'] ?? '',
        'admin_password' => $school['admin_password'] ?? 'Password123' // In real app, generate random password
    ];
    
    // Use Tenant class to create database
    $result = Tenant::createSchoolDatabase($adminData);
    
    if ($result['success']) {
        // Update school record with database name
        $updateStmt = $db->prepare("UPDATE schools SET database_name = ? WHERE id = ?");
        $updateStmt->execute([$result['database_name'], $schoolId]);
        
        // Create school directories
        Tenant::createSchoolDirectories($schoolId);
        
        // Create school portal
        Tenant::ensureSchoolPortal(array_merge($school, ['database_name' => $result['database_name']]));
        
        // Add sample data if requested
        if ($includeSampleData !== 'none') {
            $sampleCount = $includeSampleData === 'full' ? 50 : 10;
            addSampleData($schoolId, $result['database_name'], $sampleCount);
        }
        
        // Log the action
        $auditStmt = $db->prepare("
            INSERT INTO platform_audit_logs 
            (school_id, action, description, created_by, created_at) 
            VALUES (?, 'database_create', ?, ?, NOW())
        ");
        $auditStmt->execute([
            $schoolId,
            "Created database: " . $result['database_name'],
            $_SESSION['super_admin']['id'] ?? 0
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Database created successfully',
            'database_name' => $result['database_name'],
            'admin_user_id' => $result['admin_user_id'] ?? 0
        ]);
    } else {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to create database: ' . $e->getMessage()
    ]);
}

function addSampleData($schoolId, $databaseName, $count = 10) {
    try {
        $schoolDb = Database::getSchoolConnection($databaseName);
        
        // Add sample users
        $userTypes = ['student', 'teacher', 'parent'];
        for ($i = 1; $i <= $count; $i++) {
            $userType = $userTypes[array_rand($userTypes)];
            $email = "sample{$i}.{$userType}@school{$schoolId}.com";
            
            $stmt = $schoolDb->prepare("
                INSERT INTO users 
                (school_id, name, email, password, user_type, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $hashedPassword = password_hash('Sample123', PASSWORD_BCRYPT);
            $stmt->execute([
                $schoolId,
                "Sample {$userType} {$i}",
                $email,
                $hashedPassword,
                $userType
            ]);
            
            $userId = $schoolDb->lastInsertId();
            
            // Add specific data based on user type
            if ($userType === 'student') {
                $studentStmt = $schoolDb->prepare("
                    INSERT INTO students 
                    (school_id, user_id, admission_number, roll_number, first_name, last_name, 
                     admission_date, date_of_birth, status) 
                    VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_SUB(CURDATE(), INTERVAL 15 YEAR), 'active')
                ");
                $studentStmt->execute([
                    $schoolId,
                    $userId,
                    'ADM' . str_pad($i, 5, '0', STR_PAD_LEFT),
                    'ROLL' . str_pad($i, 5, '0', STR_PAD_LEFT),
                    "Student",
                    "Sample {$i}"
                ]);
            } elseif ($userType === 'teacher') {
                $teacherStmt = $schoolDb->prepare("
                    INSERT INTO teachers 
                    (school_id, user_id, employee_id, qualification, joining_date, is_active) 
                    VALUES (?, ?, ?, ?, CURDATE(), 1)
                ");
                $teacherStmt->execute([
                    $schoolId,
                    $userId,
                    'EMP' . str_pad($i, 5, '0', STR_PAD_LEFT),
                    'B.Ed, M.Ed'
                ]);
            }
        }
        
        // Add sample classes
        $grades = ['JSS 1', 'JSS 2', 'JSS 3', 'SSS 1', 'SSS 2', 'SSS 3'];
        foreach ($grades as $grade) {
            $classStmt = $schoolDb->prepare("
                INSERT INTO classes 
                (school_id, name, code, description, is_active, created_at) 
                VALUES (?, ?, ?, ?, 1, NOW())
            ");
            $classStmt->execute([
                $schoolId,
                $grade,
                str_replace(' ', '_', strtoupper($grade)),
                "{$grade} Classroom",
                1
            ]);
        }
        
        // Add sample subjects
        $subjects = ['Mathematics', 'English Language', 'Physics', 'Chemistry', 'Biology', 
                    'Geography', 'History', 'Computer Science', 'Business Studies'];
        foreach ($subjects as $subject) {
            $subjectStmt = $schoolDb->prepare("
                INSERT INTO subjects 
                (school_id, name, code, type, is_active, created_at) 
                VALUES (?, ?, ?, 'core', 1, NOW())
            ");
            $subjectStmt->execute([
                $schoolId,
                $subject,
                substr(strtoupper($subject), 0, 3),
                1
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Sample data error: " . $e->getMessage());
        return false;
    }
}
?>