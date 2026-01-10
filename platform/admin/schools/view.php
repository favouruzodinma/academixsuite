<?php
// Start session and load required files
require_once __DIR__ . '/../../../includes/autoload.php';

// Check if super admin is logged in
$auth = new Auth();
if (!$auth->isLoggedIn('super_admin')) {
    header("Location: /platform/login.php");
    exit;
}

// Get super admin data
$superAdmin = $_SESSION['super_admin'];

// Get school ID from query parameter
$schoolId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($schoolId <= 0) {
    header("Location: ../index.php");
    exit;
}

// Get school data from platform database
$db = Database::getPlatformConnection();
$stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
$stmt->execute([$schoolId]);
$school = $stmt->fetch();

if (!$school) {
    header("Location: ../index.php?error=school_not_found");
    exit;
}

// Initialize success/error messages
$success = isset($_GET['success']) ? urldecode($_GET['success']) : '';
$error = isset($_GET['error']) ? urldecode($_GET['error']) : '';

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submissions for managing school data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    // Verify CSRF token
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = "Invalid CSRF token";
        header("Location: ?id=$schoolId&error=" . urlencode($error));
        exit;
    }
    
    try {
        // Check if school database exists
        if (!empty($school['database_name'])) {
            if (!Database::schoolDatabaseExists($school['database_name'])) {
                $error = "School database not accessible. Please contact support.";
                header("Location: ?id=$schoolId&error=" . urlencode($error));
                exit;
            }
            
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            switch ($action) {
                case 'create_class':
                    // Validate class data
                    $className = trim($_POST['name'] ?? '');
                    $classCode = trim($_POST['code'] ?? '');
                    $gradeLevel = trim($_POST['grade_level'] ?? '');
                    $capacity = (int)($_POST['capacity'] ?? 40);
                    $roomNumber = trim($_POST['room_number'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $academicYearId = isset($_POST['academic_year_id']) ? (int)$_POST['academic_year_id'] : 0;
                    
                    if (empty($className)) {
                        $error = "Class name is required";
                    } elseif (empty($classCode)) {
                        $error = "Class code is required";
                    } else {
                        // If no academic year selected, get or create default
                        if ($academicYearId <= 0) {
                            $academicYearId = getDefaultAcademicYear($schoolDb, $schoolId);
                        }
                        
                        // Check if class with same code already exists
                        $checkStmt = $schoolDb->prepare("SELECT id FROM classes WHERE school_id = ? AND code = ?");
                        $checkStmt->execute([$schoolId, $classCode]);
                        if ($checkStmt->fetch()) {
                            $error = "A class with code '$classCode' already exists in this school. Please use a different class code.";
                        } else {
                            $stmt = $schoolDb->prepare("
                                INSERT INTO classes (
                                    school_id, name, code, description, 
                                    grade_level, capacity, room_number,
                                    academic_year_id, is_active
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
                            
                            $stmt->execute([
                                $schoolId,
                                $className,
                                $classCode,
                                $description,
                                $gradeLevel,
                                $capacity,
                                $roomNumber,
                                $academicYearId,
                                $isActive
                            ]);
                            
                            $classId = $schoolDb->lastInsertId();
                            $success = "Class '$className' created successfully!";
                            
                            // Create default sections (A, B, C)
                            createDefaultSections($schoolDb, $schoolId, $classId);
                            
                            // Log activity
                            logActivity($schoolDb, $schoolId, $superAdmin['id'] ?? 0, 
                                       'class_created', "Created class: $className ($classCode)");
                            
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'create_subject':
                    // Validate subject data
                    $subjectName = trim($_POST['name'] ?? '');
                    $subjectCode = trim($_POST['code'] ?? '');
                    $subjectType = $_POST['type'] ?? 'core';
                    $creditHours = (float)($_POST['credit_hours'] ?? 1.0);
                    $description = trim($_POST['description'] ?? '');
                    
                    if (empty($subjectName)) {
                        $error = "Subject name is required";
                    } elseif (empty($subjectCode)) {
                        $error = "Subject code is required";
                    } else {
                        // Check if subject with same code already exists
                        $checkStmt = $schoolDb->prepare("SELECT id FROM subjects WHERE school_id = ? AND code = ?");
                        $checkStmt->execute([$schoolId, $subjectCode]);
                        if ($checkStmt->fetch()) {
                            $error = "A subject with code '$subjectCode' already exists in this school. Please use a different subject code.";
                        } else {
                            $stmt = $schoolDb->prepare("
                                INSERT INTO subjects (
                                    school_id, name, code, type,
                                    description, credit_hours, is_active
                                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
                            
                            $stmt->execute([
                                $schoolId,
                                $subjectName,
                                $subjectCode,
                                $subjectType,
                                $description,
                                $creditHours,
                                $isActive
                            ]);
                            
                            $success = "Subject '$subjectName' created successfully!";
                            
                            // Log activity
                            logActivity($schoolDb, $schoolId, $superAdmin['id'] ?? 0, 
                                       'subject_created', "Created subject: $subjectName ($subjectCode)");
                            
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'assign_subject':
                    $classId = (int)($_POST['class_id'] ?? 0);
                    $subjectId = (int)($_POST['subject_id'] ?? 0);
                    $teacherId = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
                    
                    if ($classId <= 0) {
                        $error = "Please select a class";
                    } elseif ($subjectId <= 0) {
                        $error = "Please select a subject";
                    } else {
                        // Check if assignment already exists
                        $checkStmt = $schoolDb->prepare("
                            SELECT id FROM class_subjects 
                            WHERE class_id = ? AND subject_id = ?
                        ");
                        $checkStmt->execute([$classId, $subjectId]);
                        
                        if ($checkStmt->fetch()) {
                            $error = "This subject is already assigned to the selected class";
                        } else {
                            $stmt = $schoolDb->prepare("
                                INSERT INTO class_subjects (
                                    class_id, subject_id, teacher_id
                                ) VALUES (?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $classId,
                                $subjectId,
                                $teacherId
                            ]);
                            
                            $success = "Subject assigned to class successfully!";
                            
                            // Log activity
                            logActivity($schoolDb, $schoolId, $superAdmin['id'] ?? 0, 
                                       'subject_assigned', "Assigned subject to class");
                            
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'add_academic_year':
                    $yearName = trim($_POST['name'] ?? '');
                    $startDate = $_POST['start_date'] ?? '';
                    $endDate = $_POST['end_date'] ?? '';
                    $status = $_POST['status'] ?? 'upcoming';
                    $isDefault = isset($_POST['is_default']) ? (int)$_POST['is_default'] : 0;
                    
                    if (empty($yearName)) {
                        $error = "Academic year name is required";
                    } elseif (empty($startDate)) {
                        $error = "Start date is required";
                    } elseif (empty($endDate)) {
                        $error = "End date is required";
                    } else {
                        // Check if academic year with same name already exists
                        $checkStmt = $schoolDb->prepare("SELECT id FROM academic_years WHERE school_id = ? AND name = ?");
                        $checkStmt->execute([$schoolId, $yearName]);
                        if ($checkStmt->fetch()) {
                            $error = "An academic year with name '$yearName' already exists. Please use a different name.";
                        } else {
                            $stmt = $schoolDb->prepare("
                                INSERT INTO academic_years (
                                    school_id, name, start_date, end_date, 
                                    is_default, status
                                ) VALUES (?, ?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $schoolId,
                                $yearName,
                                $startDate,
                                $endDate,
                                $isDefault,
                                $status
                            ]);
                            $success = "Academic year added successfully";
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'add_section':
                    $classId = (int)($_POST['class_id'] ?? 0);
                    $sectionName = trim($_POST['name'] ?? '');
                    $sectionCode = trim($_POST['code'] ?? '');
                    $roomNumber = trim($_POST['room_number'] ?? '');
                    $capacity = (int)($_POST['capacity'] ?? 40);
                    
                    if ($classId <= 0) {
                        $error = "Please select a class";
                    } elseif (empty($sectionName)) {
                        $error = "Section name is required";
                    } elseif (empty($sectionCode)) {
                        $error = "Section code is required";
                    } else {
                        // Check if section with same code already exists for this class
                        $checkStmt = $schoolDb->prepare("SELECT id FROM sections WHERE class_id = ? AND code = ?");
                        $checkStmt->execute([$classId, $sectionCode]);
                        if ($checkStmt->fetch()) {
                            $error = "A section with code '$sectionCode' already exists for this class. Please use a different section code.";
                        } else {
                            $stmt = $schoolDb->prepare("
                                INSERT INTO sections (
                                    school_id, class_id, name, code, room_number, 
                                    capacity, is_active
                                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
                            
                            $stmt->execute([
                                $schoolId,
                                $classId,
                                $sectionName,
                                $sectionCode,
                                $roomNumber,
                                $capacity,
                                $isActive
                            ]);
                            $success = "Section added successfully";
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'delete_class':
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        $error = "Invalid class ID";
                    } else {
                        // Check if class has students assigned
                        $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as student_count FROM students WHERE class_id = ? AND status = 'active'");
                        $checkStmt->execute([$id]);
                        $result = $checkStmt->fetch();
                        
                        if ($result && $result['student_count'] > 0) {
                            $error = "Cannot delete class with active students. Please reassign or remove students first.";
                        } else {
                            // Soft delete by setting is_active to 0
                            $stmt = $schoolDb->prepare("UPDATE classes SET is_active = 0 WHERE id = ?");
                            $stmt->execute([$id]);
                            $success = "Class deleted successfully";
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'delete_subject':
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        $error = "Invalid subject ID";
                    } else {
                        // Check if subject is assigned to any class
                        $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as assignment_count FROM class_subjects WHERE subject_id = ?");
                        $checkStmt->execute([$id]);
                        $result = $checkStmt->fetch();
                        
                        if ($result && $result['assignment_count'] > 0) {
                            $error = "Cannot delete subject that is assigned to classes. Please remove assignments first.";
                        } else {
                            // Soft delete by setting is_active to 0
                            $stmt = $schoolDb->prepare("UPDATE subjects SET is_active = 0 WHERE id = ?");
                            $stmt->execute([$id]);
                            $success = "Subject deleted successfully";
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'delete_academic_year':
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        $error = "Invalid academic year ID";
                    } else {
                        // Check if this is the default or active academic year
                        $checkStmt = $schoolDb->prepare("SELECT is_default, status FROM academic_years WHERE id = ?");
                        $checkStmt->execute([$id]);
                        $yearData = $checkStmt->fetch();
                        
                        if ($yearData && ($yearData['is_default'] == 1 || $yearData['status'] == 'active')) {
                            $error = "Cannot delete default or active academic year";
                        } else {
                            // Check if there are classes using this academic year
                            $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as class_count FROM classes WHERE academic_year_id = ? AND is_active = 1");
                            $checkStmt->execute([$id]);
                            $result = $checkStmt->fetch();
                            
                            if ($result && $result['class_count'] > 0) {
                                $error = "Cannot delete academic year that is assigned to classes. Please reassign classes first.";
                            } else {
                                $stmt = $schoolDb->prepare("DELETE FROM academic_years WHERE id = ?");
                                $stmt->execute([$id]);
                                $success = "Academic year deleted successfully";
                                header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                                exit;
                            }
                        }
                    }
                    break;
                    
                case 'delete_section':
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        $error = "Invalid section ID";
                    } else {
                        // Check if section has students assigned
                        $checkStmt = $schoolDb->prepare("SELECT COUNT(*) as student_count FROM students WHERE section_id = ? AND status = 'active'");
                        $checkStmt->execute([$id]);
                        $result = $checkStmt->fetch();
                        
                        if ($result && $result['student_count'] > 0) {
                            $error = "Cannot delete section with active students. Please reassign students first.";
                        } else {
                            // Soft delete by setting is_active to 0
                            $stmt = $schoolDb->prepare("UPDATE sections SET is_active = 0 WHERE id = ?");
                            $stmt->execute([$id]);
                            $success = "Section deleted successfully";
                            header("Location: ?id=$schoolId&success=" . urlencode($success) . "&tab=management");
                            exit;
                        }
                    }
                    break;
                    
                case 'update_school':
                    // Handle school update from edit modal
                    $name = trim($_POST['name'] ?? '');
                    $status = $_POST['status'] ?? 'pending';
                    $email = trim($_POST['email'] ?? '');
                    $phone = trim($_POST['phone'] ?? '');
                    $address = trim($_POST['address'] ?? '');
                    $city = trim($_POST['city'] ?? '');
                    $state = trim($_POST['state'] ?? '');
                    
                    if (empty($name)) {
                        $error = "School name is required";
                    } else {
                        // Check if school with same name already exists (excluding current school)
                        $checkStmt = $db->prepare("SELECT id FROM schools WHERE name = ? AND id != ?");
                        $checkStmt->execute([$name, $schoolId]);
                        if ($checkStmt->fetch()) {
                            $error = "A school with name '$name' already exists. Please use a different name.";
                        } else {
                            $stmt = $db->prepare("
                                UPDATE schools 
                                SET name = ?, status = ?, email = ?, phone = ?, 
                                    address = ?, city = ?, state = ?, updated_at = NOW()
                                WHERE id = ?
                            ");
                            
                            $stmt->execute([
                                $name,
                                $status,
                                $email,
                                $phone,
                                $address,
                                $city,
                                $state,
                                $schoolId
                            ]);
                            
                            $success = "School details updated successfully";
                            
                            // Update local school data
                            $school['name'] = $name;
                            $school['status'] = $status;
                            $school['email'] = $email;
                            $school['phone'] = $phone;
                            $school['address'] = $address;
                            $school['city'] = $city;
                            $school['state'] = $state;
                            
                            header("Location: ?id=$schoolId&success=" . urlencode($success));
                            exit;
                        }
                    }
                    break;
                    
                default:
                    $error = "Invalid action specified";
            }
            
            // If we get here with an error, redirect with error
            if (!empty($error)) {
                header("Location: ?id=$schoolId&error=" . urlencode($error));
                exit;
            }
        } else {
            $error = "School database not found";
            header("Location: ?id=$schoolId&error=" . urlencode($error));
            exit;
        }
    } catch (PDOException $e) {
        // Handle specific database errors
        if ($e->getCode() == 23000) { // Integrity constraint violation
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, 'unique_subject_school') !== false) {
                $error = "A subject with this code already exists in this school. Please use a different subject code.";
            } elseif (strpos($errorMessage, 'unique_class_school') !== false) {
                $error = "A class with this code already exists in this school. Please use a different class code.";
            } elseif (strpos($errorMessage, 'unique_section_class') !== false) {
                $error = "A section with this code already exists for this class. Please use a different section code.";
            } elseif (strpos($errorMessage, 'unique_academic_year_school') !== false) {
                $error = "An academic year with this name already exists in this school. Please use a different name.";
            } else {
                $error = "Database constraint violation. Please ensure all values are unique.";
            }
        } else {
            $error = "Database error: " . $e->getMessage();
        }
        error_log("School data management error: " . $e->getMessage());
        header("Location: ?id=$schoolId&error=" . urlencode($error));
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        error_log("School data management error: " . $e->getMessage());
        header("Location: ?id=$schoolId&error=" . urlencode($error));
        exit;
    }
}

// Helper functions
function getDefaultAcademicYear($db, $schoolId) {
    // Try to get active academic year
    $stmt = $db->prepare("
        SELECT id FROM academic_years 
        WHERE school_id = ? AND status = 'active' 
        LIMIT 1
    ");
    $stmt->execute([$schoolId]);
    $year = $stmt->fetch();
    
    if ($year) {
        return $year['id'];
    }
    
    // Try to get default academic year
    $stmt = $db->prepare("
        SELECT id FROM academic_years 
        WHERE school_id = ? AND is_default = 1 
        LIMIT 1
    ");
    $stmt->execute([$schoolId]);
    $year = $stmt->fetch();
    
    if ($year) {
        return $year['id'];
    }
    
    // Get any academic year
    $stmt = $db->prepare("
        SELECT id FROM academic_years 
        WHERE school_id = ?
        ORDER BY start_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$schoolId]);
    $year = $stmt->fetch();
    
    if ($year) {
        return $year['id'];
    }
    
    // Create default academic year
    $currentYear = date('Y');
    $nextYear = $currentYear + 1;
    $yearName = "$currentYear-$nextYear";
    
    $stmt = $db->prepare("
        INSERT INTO academic_years (
            school_id, name, start_date, end_date,
            status, is_default
        ) VALUES (?, ?, ?, ?, 'active', 1)
    ");
    
    $stmt->execute([
        $schoolId,
        $yearName,
        "$currentYear-09-01",
        "$nextYear-06-30"
    ]);
    
    return $db->lastInsertId();
}

function createDefaultSections($db, $schoolId, $classId) {
    $sections = ['A', 'B', 'C'];
    
    foreach ($sections as $section) {
        try {
            // Check if section already exists
            $checkStmt = $db->prepare("SELECT id FROM sections WHERE class_id = ? AND code = ?");
            $checkStmt->execute([$classId, $section]);
            
            if (!$checkStmt->fetch()) {
                $stmt = $db->prepare("
                    INSERT INTO sections (
                        school_id, class_id, name, code,
                        capacity, is_active
                    ) VALUES (?, ?, ?, ?, 40, ?)
                ");
                
                $stmt->execute([
                    $schoolId,
                    $classId,
                    "Section $section",
                    $section,
                    1
                ]);
            }
        } catch (Exception $e) {
            // Log but continue with other sections
            error_log("Error creating default section $section: " . $e->getMessage());
        }
    }
}

function logActivity($db, $schoolId, $userId, $event, $description) {
    try {
        // Check if audit_logs table exists
        $checkTable = $db->prepare("SHOW TABLES LIKE 'audit_logs'");
        $checkTable->execute();
        
        if ($checkTable->fetch()) {
            $stmt = $db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type,
                    event, description, ip_address, created_at
                ) VALUES (?, ?, 'super_admin', ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $schoolId,
                $userId,
                $event,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// Get school statistics
$stats = ['students' => 0, 'teachers' => 0, 'admins' => 0, 'parents' => 0];
try {
    if (class_exists('Tenant')) {
        $tenantStats = Tenant::getSchoolStatistics($schoolId);
        if (is_array($tenantStats)) {
            $stats = $tenantStats;
        }
    }
} catch (Exception $e) {
    error_log("Error getting school statistics: " . $e->getMessage());
}

// Get current subscription
$subscription = null;
try {
    $subStmt = $db->prepare("
        SELECT s.*, p.name as plan_name, p.price_monthly 
        FROM subscriptions s 
        LEFT JOIN plans p ON s.plan_id = p.id 
        WHERE s.school_id = ? 
        ORDER BY s.created_at DESC 
        LIMIT 1
    ");
    $subStmt->execute([$schoolId]);
    $subscription = $subStmt->fetch();
} catch (Exception $e) {
    error_log("Error getting subscription: " . $e->getMessage());
}

// Try to get admin user from school's database
$admin = null;
$recentActivities = [];
$students = [];
$teachers = [];
$classes = [];
$subjects = [];
$academicYears = [];
$sections = [];
$classSubjects = [];

try {
    // Connect to school's database
    if (!empty($school['database_name'])) {
        if (Database::schoolDatabaseExists($school['database_name'])) {
            $schoolDb = Database::getSchoolConnection($school['database_name']);
            
            // Get admin user
            try {
                $adminStmt = $schoolDb->prepare("
                    SELECT u.* FROM users u 
                    WHERE u.user_type = 'admin' AND u.is_active = 1 
                    ORDER BY u.id ASC 
                    LIMIT 1
                ");
                $adminStmt->execute();
                $admin = $adminStmt->fetch();
            } catch (Exception $e) {
                error_log("Error fetching admin: " . $e->getMessage());
            }
            
            // Get students
            try {
                $studentStmt = $schoolDb->prepare("
                    SELECT 
                        s.id,
                        s.admission_number as student_id,
                        CONCAT(s.first_name, ' ', s.last_name) as full_name,
                        s.first_name,
                        s.last_name,
                        u.email,
                        u.phone,
                        s.class_id,
                        s.section_id,
                        s.status,
                        s.created_at
                    FROM students s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.status = 'active' 
                    ORDER BY s.created_at DESC 
                    LIMIT 20
                ");
                $studentStmt->execute();
                $students = $studentStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching students: " . $e->getMessage());
            }
            
            // Get teachers
            try {
                $teacherStmt = $schoolDb->prepare("
                    SELECT 
                        t.id,
                        t.employee_id,
                        u.name as full_name,
                        u.email,
                        u.phone,
                        t.qualification,
                        t.specialization as subject,
                        t.is_active as status,
                        u.created_at
                    FROM teachers t
                    JOIN users u ON t.user_id = u.id
                    WHERE t.is_active = 1 
                    ORDER BY t.id DESC 
                    LIMIT 20
                ");
                $teacherStmt->execute();
                $teachers = $teacherStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching teachers: " . $e->getMessage());
            }
            
            // Get classes
            try {
                $classStmt = $schoolDb->prepare("
                    SELECT c.*, 
                           a.name as academic_year_name,
                           t.name as teacher_name,
                           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id AND s.status = 'active') as student_count,
                           (SELECT COUNT(*) FROM class_subjects cs WHERE cs.class_id = c.id) as subject_count
                    FROM classes c
                    LEFT JOIN academic_years a ON c.academic_year_id = a.id
                    LEFT JOIN teachers t ON c.class_teacher_id = t.id
                    WHERE c.school_id = ? AND c.is_active = 1
                    ORDER BY c.name ASC
                ");
                $classStmt->execute([$schoolId]);
                $classes = $classStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching classes: " . $e->getMessage());
            }
            
            // Get subjects
            try {
                $subjectStmt = $schoolDb->prepare("
                    SELECT * FROM subjects 
                    WHERE school_id = ? AND is_active = 1
                    ORDER BY name ASC
                ");
                $subjectStmt->execute([$schoolId]);
                $subjects = $subjectStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching subjects: " . $e->getMessage());
            }
            
            // Get academic years
            try {
                $academicStmt = $schoolDb->prepare("
                    SELECT * FROM academic_years 
                    WHERE school_id = ?
                    ORDER BY start_date DESC
                ");
                $academicStmt->execute([$schoolId]);
                $academicYears = $academicStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching academic years: " . $e->getMessage());
            }
            
            // Get sections
            try {
                $sectionStmt = $schoolDb->prepare("
                    SELECT s.*, c.name as class_name,
                           t.name as teacher_name
                    FROM sections s
                    JOIN classes c ON s.class_id = c.id
                    LEFT JOIN teachers t ON s.class_teacher_id = t.id
                    WHERE s.school_id = ? AND s.is_active = 1
                    ORDER BY s.class_id, s.name
                ");
                $sectionStmt->execute([$schoolId]);
                $sections = $sectionStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching sections: " . $e->getMessage());
            }
            
            // Get class-subject assignments
            try {
                $classSubjectStmt = $schoolDb->prepare("
                    SELECT cs.*, c.name as class_name, s.name as subject_name,
                           t.employee_id, u.name as teacher_name
                    FROM class_subjects cs
                    JOIN classes c ON cs.class_id = c.id
                    JOIN subjects s ON cs.subject_id = s.id
                    LEFT JOIN teachers t ON cs.teacher_id = t.id
                    LEFT JOIN users u ON t.user_id = u.id
                    WHERE c.school_id = ?
                    ORDER BY c.name, s.name
                ");
                $classSubjectStmt->execute([$schoolId]);
                $classSubjects = $classSubjectStmt->fetchAll();
            } catch (Exception $e) {
                error_log("Error fetching class subjects: " . $e->getMessage());
            }
            
            // Get recent activities
            try {
                $checkTable = $schoolDb->prepare("SHOW TABLES LIKE 'audit_logs'");
                $checkTable->execute();
                if ($checkTable->fetch()) {
                    $activitiesStmt = $schoolDb->prepare("
                        SELECT * FROM audit_logs 
                        WHERE school_id = ? 
                        ORDER BY created_at DESC 
                        LIMIT 5
                    ");
                    $activitiesStmt->execute([$schoolId]);
                    $recentActivities = $activitiesStmt->fetchAll();
                }
            } catch (Exception $e) {
                error_log("Error checking audit logs table: " . $e->getMessage());
            }
        }
    }
} catch (Exception $e) {
    error_log("Error accessing school database for school ID {$schoolId}: " . $e->getMessage());
}

// Get recent activities from platform audit_logs as fallback
if (empty($recentActivities)) {
    try {
        $checkTable = $db->prepare("SHOW TABLES LIKE 'platform_audit_logs'");
        $checkTable->execute();
        
        if ($checkTable->fetch()) {
            $activitiesStmt = $db->prepare("
                SELECT * FROM platform_audit_logs 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT 5
            ");
            $activitiesStmt->execute([$schoolId]);
            $recentActivities = $activitiesStmt->fetchAll();
        }
    } catch (Exception $e) {
        error_log("Error accessing platform audit logs: " . $e->getMessage());
        $recentActivities = [];
    }
}

// Format data for display
$statusClass = '';
$statusText = 'Pending';
switch($school['status'] ?? 'pending') {
    case 'active':
        $statusClass = 'status-active';
        $statusText = 'Operational';
        break;
    case 'trial':
        $statusClass = 'status-trial';
        $statusText = 'Trial';
        break;
    case 'suspended':
        $statusClass = 'status-suspended';
        $statusText = 'Suspended';
        break;
    case 'pending':
        $statusClass = 'status-pending';
        $statusText = 'Pending';
        break;
    default:
        $statusClass = 'status-pending';
        $statusText = ucfirst($school['status'] ?? 'pending');
}

// Calculate renewal date
$renewalDate = null;
if ($subscription && isset($subscription['current_period_end']) && $subscription['current_period_end']) {
    $renewalDate = date('F j, Y', strtotime($subscription['current_period_end']));
}

// Calculate uptime (simulated)
$uptime = 94.7 + (rand(-10, 10) / 10);

// Prepare JSON data for charts
$chartData = [
    'labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    'users' => [],
    'apiCalls' => []
];

// User distribution based on stats
$userDistribution = [
    'labels' => ['Teachers', 'Students', 'Administrators', 'Parents'],
    'data' => [
        $stats['teachers'] ?? 0,
        $stats['students'] ?? 0,
        $stats['admins'] ?? 0,
        $stats['parents'] ?? 0
    ]
];

// Generate simulated data for charts
for ($i = 0; $i < 7; $i++) {
    $baseUsers = ($stats['students'] ?? 0) + ($stats['teachers'] ?? 0) + ($stats['admins'] ?? 0);
    $chartData['users'][] = $baseUsers + rand(-200, 200);
    $chartData['apiCalls'][] = rand(40000, 80000);
}

// Get default tab from URL
$defaultTab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($school['name'] ?? 'School Details'); ?> | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        :root {
            --brand-primary: #2563eb;
            --brand-surface: #ffffff;
            --brand-bg: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--brand-bg); 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Mobile-optimized scrollbar */
        ::-webkit-scrollbar { 
            width: 4px; 
            height: 4px; 
        }
        ::-webkit-scrollbar-track { 
            background: #f1f5f9; 
        }
        ::-webkit-scrollbar-thumb { 
            background: #cbd5e1; 
            border-radius: 10px; 
        }

        .sidebar-link { 
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); 
            border-left: 3px solid transparent; 
        }
        .sidebar-link:hover { 
            background: #f1f5f9; 
            color: var(--brand-primary); 
        }
        .active-link { 
            background: #eff6ff; 
            color: var(--brand-primary); 
            border-left-color: var(--brand-primary); 
            font-weight: 600; 
        }
        
        .dropdown-content { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
        }
        .dropdown-open .dropdown-content { 
            max-height: 500px; 
        }
        .dropdown-open .chevron { 
            transform: rotate(180deg); 
        }

        /* Responsive Breakpoints */
        @media (max-width: 640px) {
            .xs-hidden { display: none !important; }
            .xs-block { display: block !important; }
            .xs-flex { display: flex !important; }
            .xs-flex-col { flex-direction: column; }
            .xs-w-full { width: 100%; }
            .xs-text-center { text-align: center; }
            .xs-p-2 { padding: 0.5rem; }
            .xs-p-4 { padding: 1rem; }
            .xs-space-y-2 > * + * { margin-top: 0.5rem; }
            .xs-space-y-4 > * + * { margin-top: 1rem; }
            .xs-gap-2 { gap: 0.5rem; }
            .xs-text-sm { font-size: 0.875rem; }
            .xs-text-xs { font-size: 0.75rem; }
        }

        @media (min-width: 641px) and (max-width: 768px) {
            .sm-hidden { display: none !important; }
            .sm-block { display: block !important; }
            .sm-w-full { width: 100%; }
            .sm-flex-col { flex-direction: column; }
        }

        @media (min-width: 769px) and (max-width: 1024px) {
            .md-hidden { display: none !important; }
            .md-block { display: block !important; }
        }

        /* Touch-friendly sizes */
        .touch-target {
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Mobile navigation */
        .mobile-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e2e8f0;
            z-index: 100;
            padding: 0.5rem;
            display: none;
        }

        .mobile-nav-item {
            flex: 1;
            text-align: center;
            padding: 0.75rem 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
        }

        .mobile-nav-item.active {
            color: #2563eb;
        }

        .mobile-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 0.25rem;
        }

        @media (max-width: 768px) {
            .mobile-nav {
                display: flex;
            }
            
            main {
                padding-bottom: 80px !important;
            }
        }

        .glass-header { 
            background: rgba(255, 255, 255, 0.95); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 50;
        }
        
        .detail-card { 
            border: 1px solid #e2e8f0; 
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); 
            border-radius: 20px;
            overflow: hidden;
        }

        /* Status indicators */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .status-trial {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .status-suspended {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .status-pending {
            background-color: #e0e7ff;
            color: #3730a3;
            border: 1px solid #c7d2fe;
        }

        /* Progress bars */
        .progress-container {
            width: 100%;
            height: 8px;
            background-color: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-success {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .progress-danger {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        /* Timeline */
        .timeline-item {
            position: relative;
            padding-left: 24px;
            margin-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 8px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid #2563eb;
            background: white;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: 5px;
            top: 20px;
            width: 2px;
            height: calc(100% + 12px);
            background: #e2e8f0;
        }
        
        .timeline-item:last-child::after {
            display: none;
        }

        /* Tabs */
        .tabs-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .tabs-container::-webkit-scrollbar {
            display: none;
        }
        
        .tab-button {
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .tab-button:hover {
            color: #2563eb;
        }
        
        .tab-button.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: linear-gradient(to top, rgba(37, 99, 235, 0.05), transparent);
        }

        /* User Type Tabs */
        .user-type-tab {
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .user-type-tab:hover {
            color: #2563eb;
        }

        .user-type-tab.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
            background: linear-gradient(to top, rgba(37, 99, 235, 0.05), transparent);
        }

        .user-type-content {
            display: none;
        }

        .user-type-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        /* Card hover effects */
        .hover-lift {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            padding: 1rem;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(20px);
            transition: transform 0.3s ease;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }

        /* Mobile menu overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .sidebar-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* Form elements */
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: white;
            transition: all 0.2s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Responsive tables */
        .responsive-table {
            display: block;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .responsive-table table {
            min-width: 640px;
        }

        /* Utility classes for responsive design */
        .truncate-mobile {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .hide-on-mobile {
            display: none;
        }

        @media (min-width: 768px) {
            .hide-on-mobile {
                display: block;
            }
            
            .show-on-mobile {
                display: none;
            }
        }

        /* Safe area support for newer iPhones */
        @supports (padding: max(0px)) {
            .safe-area-bottom {
                padding-bottom: max(1rem, env(safe-area-inset-bottom));
            }
            
            .safe-area-top {
                padding-top: max(1rem, env(safe-area-inset-top));
            }
        }

        /* Tab content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* School management specific */
        .management-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        @media (max-width: 640px) {
            .management-grid {
                grid-template-columns: 1fr;
            }
        }

        .management-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.2s ease;
        }

        .management-card:hover {
            border-color: #2563eb;
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.1);
        }

        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .management-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .management-actions {
            display: flex;
            gap: 0.5rem;
        }

        .management-action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .management-action-btn:hover {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }

        .management-stats {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-state-icon {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        .empty-state-text {
            color: #64748b;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body class="antialiased overflow-x-hidden selection:bg-blue-100">

    <!-- Mobile Sidebar Overlay -->
    <div id="sidebarOverlay" class="sidebar-overlay lg:hidden" onclick="mobileSidebarToggle()"></div>

    <!-- Edit School Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Edit School Details</h3>
                <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <form id="editSchoolForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="update_school">
                
                <div class="xs-space-y-4">
                    <div class="form-group">
                        <label class="form-label">Institution Name</label>
                        <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($school['name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="active" <?php echo ($school['status'] ?? '') == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="trial" <?php echo ($school['status'] ?? '') == 'trial' ? 'selected' : ''; ?>>Trial</option>
                            <option value="suspended" <?php echo ($school['status'] ?? '') == 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                            <option value="pending" <?php echo ($school['status'] ?? '') == 'pending' ? 'selected' : ''; ?>>Pending</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($school['email'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" value="<?php echo htmlspecialchars($school['phone'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-input" rows="3"><?php echo htmlspecialchars($school['address'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">City</label>
                        <input type="text" name="city" class="form-input" value="<?php echo htmlspecialchars($school['city'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">State</label>
                        <input type="text" name="state" class="form-input" value="<?php echo htmlspecialchars($school['state'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 mt-8 pt-6 border-t border-slate-100 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('editModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target order-1 xs:order-2">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Modal -->
    <div id="actionsModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">School Actions</h3>
                <button onclick="closeModal('actionsModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="xs-space-y-3">
                <button onclick="performAction('backup', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-emerald-200 hover:bg-emerald-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-database text-emerald-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-bold text-slate-900 truncate">Create Backup</h4>
                        <p class="text-sm text-slate-500 truncate">Generate system backup</p>
                    </div>
                </button>
                
                <button onclick="performAction('restart', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-amber-200 hover:bg-amber-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-redo text-amber-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-bold text-slate-900 truncate">Restart Services</h4>
                        <p class="text-sm text-slate-500 truncate">Restart school services</p>
                    </div>
                </button>
                
                <button onclick="performAction('suspend', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-red-200 hover:bg-red-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-pause text-red-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-bold text-slate-900 truncate">Suspend Account</h4>
                        <p class="text-sm text-slate-500 truncate">Temporarily suspend school</p>
                    </div>
                </button>
                
                <button onclick="performAction('terminate', <?php echo $schoolId; ?>)" class="w-full text-left p-4 rounded-xl border border-slate-200 hover:border-red-200 hover:bg-red-50 transition flex items-center gap-3 touch-target">
                    <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-trash text-red-600"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h4 class="font-bold text-slate-900 truncate">Terminate Account</h4>
                        <p class="text-sm text-slate-500 truncate">Permanently delete school</p>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div id="addClassModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Add New Class</h3>
                <button onclick="closeModal('addClassModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <form id="addClassForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_class">
                
                <div class="xs-space-y-4">
                    <div class="form-group">
                        <label class="form-label">Class Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g., Grade 1, Class 2A" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Class Code *</label>
                        <input type="text" name="code" class="form-input" placeholder="e.g., G1, C2A" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-input" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Grade Level</label>
                            <input type="text" name="grade_level" class="form-input" placeholder="e.g., Primary 1">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity" class="form-input" value="40" min="1" max="100">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" class="form-input" placeholder="e.g., Room 101">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Academic Year</label>
                            <select name="academic_year_id" class="form-input">
                                <option value="0">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_active" class="mr-2" checked>
                            <span class="text-sm">Active</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 mt-8 pt-6 border-t border-slate-100 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('addClassModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target order-1 xs:order-2">
                        Create Class
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div id="addSubjectModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Add New Subject</h3>
                <button onclick="closeModal('addSubjectModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <form id="addSubjectForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="create_subject">
                
                <div class="xs-space-y-4">
                    <div class="form-group">
                        <label class="form-label">Subject Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g., Mathematics, English" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Subject Code *</label>
                        <input type="text" name="code" class="form-input" placeholder="e.g., MATH, ENG" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-input">
                            <option value="core">Core</option>
                            <option value="elective">Elective</option>
                            <option value="extra_curricular">Extra Curricular</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-input" rows="2" placeholder="Optional description"></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Credit Hours</label>
                            <input type="number" name="credit_hours" class="form-input" value="1.0" step="0.5" min="0.5" max="10">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_active" class="mr-2" checked>
                            <span class="text-sm">Active</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 mt-8 pt-6 border-t border-slate-100 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('addSubjectModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target order-1 xs:order-2">
                        Create Subject
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Academic Year Modal -->
    <div id="addAcademicYearModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Add Academic Year</h3>
                <button onclick="closeModal('addAcademicYearModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <form id="addAcademicYearForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_academic_year">
                
                <div class="xs-space-y-4">
                    <div class="form-group">
                        <label class="form-label">Academic Year Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g., 2024-2025, Session 2024" required>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Start Date *</label>
                            <input type="date" name="start_date" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">End Date *</label>
                            <input type="date" name="end_date" class="form-input" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input">
                            <option value="upcoming">Upcoming</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_default" class="mr-2">
                            <span class="text-sm">Set as default academic year</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 mt-8 pt-6 border-t border-slate-100 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('addAcademicYearModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target order-1 xs:order-2">
                        Create Academic Year
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Section Modal -->
    <div id="addSectionModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Add New Section</h3>
                <button onclick="closeModal('addSectionModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <form id="addSectionForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="add_section">
                
                <div class="xs-space-y-4">
                    <div class="form-group">
                        <label class="form-label">Select Class *</label>
                        <select name="class_id" class="form-input" required>
                            <option value="">Select a class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name'] . ' (' . $class['code'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Section Name *</label>
                        <input type="text" name="name" class="form-input" placeholder="e.g., Section A, Morning Batch" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Section Code *</label>
                        <input type="text" name="code" class="form-input" placeholder="e.g., SEC-A, MORN" required>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Room Number</label>
                            <input type="text" name="room_number" class="form-input" placeholder="e.g., Room 101">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Capacity</label>
                            <input type="number" name="capacity" class="form-input" value="40" min="1" max="100">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_active" class="mr-2" checked>
                            <span class="text-sm">Active</span>
                        </label>
                    </div>
                </div>
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 mt-8 pt-6 border-t border-slate-100 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('addSectionModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target order-1 xs:order-2">
                        Create Section
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">Confirm Delete</h3>
                <button onclick="closeModal('deleteModal')" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <div class="mb-6">
                <p class="text-slate-600" id="deleteMessage">Are you sure you want to delete this item?</p>
            </div>
            
            <form id="deleteForm" method="POST">
                <input type="hidden" name="school_id" value="<?php echo $schoolId; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" id="deleteAction">
                <input type="hidden" name="id" id="deleteId">
                
                <div class="flex flex-col xs:flex-row justify-end xs:gap-3 xs-space-y-3 xs:space-y-0">
                    <button type="button" onclick="closeModal('deleteModal')" class="w-full xs:w-auto px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target order-2 xs:order-1">
                        Cancel
                    </button>
                    <button type="submit" class="w-full xs:w-auto px-6 py-3 bg-red-600 text-white font-bold rounded-xl hover:bg-red-700 transition touch-target order-1 xs:order-2">
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">

        <?php 
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        } else {
            echo '<div class="w-64 bg-white border-r border-slate-200 p-4 hidden lg:block">Sidebar not found</div>';
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden safe-area-bottom">
            
            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40 safe-area-top">
                <div class="flex items-center gap-3">
                    <button onclick="mobileSidebarToggle()" class="lg:hidden text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-bars-staggered"></i>
                    </button>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest truncate-mobile" style="max-width: 150px;">School Management</h1>
                        <span class="px-2 py-0.5 bg-blue-600 text-[10px] text-white font-black rounded uppercase hidden xs:inline">Live</span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <a href="../index.php" class="hidden sm:flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-blue-600 text-sm font-medium transition touch-target">
                        <i class="fas fa-arrow-left"></i>
                        <span class="hidden sm:inline">Back to Registry</span>
                    </a>
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-clock hidden xs:inline"></i>
                        <span id="timestamp" class="truncate-mobile" style="max-width: 120px;">Loading...</span>
                    </div>
                </div>
            </header>

            <!-- Mobile Navigation -->
            <nav class="mobile-nav safe-area-bottom">
                <a href="#overviewTab" onclick="switchMobileTab(event, 'overview')" class="mobile-nav-item <?php echo $defaultTab === 'overview' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <div>Overview</div>
                </a>
                <a href="#usersTab" onclick="switchMobileTab(event, 'users')" class="mobile-nav-item <?php echo $defaultTab === 'users' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <div>Users</div>
                </a>
                <a href="#managementTab" onclick="switchMobileTab(event, 'management')" class="mobile-nav-item <?php echo $defaultTab === 'management' ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>
                    <div>Manage</div>
                </a>
                <a href="#analyticsTab" onclick="switchMobileTab(event, 'analytics')" class="mobile-nav-item <?php echo $defaultTab === 'analytics' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <div>Analytics</div>
                </a>
                <a href="#settingsTab" onclick="switchMobileTab(event, 'settings')" class="mobile-nav-item <?php echo $defaultTab === 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <div>Settings</div>
                </a>
                <a href="#logsTab" onclick="switchMobileTab(event, 'logs')" class="mobile-nav-item <?php echo $defaultTab === 'logs' ? 'active' : ''; ?>">
                    <i class="fas fa-history"></i>
                    <div>Logs</div>
                </a>
            </nav>

            <!-- Tabs Navigation -->
            <div class="border-b border-slate-200 bg-white hidden md:block">
                <div class="max-w-7xl mx-auto px-4 lg:px-8">
                    <div class="tabs-container">
                        <div class="flex">
                            <button class="tab-button <?php echo $defaultTab === 'overview' ? 'active' : ''; ?>" onclick="switchTab(event, 'overview')">
                                <i class="fas fa-chart-bar mr-2"></i>Overview
                            </button>
                            <button class="tab-button <?php echo $defaultTab === 'users' ? 'active' : ''; ?>" onclick="switchTab(event, 'users')">
                                <i class="fas fa-users mr-2"></i>Users
                            </button>
                            <button class="tab-button <?php echo $defaultTab === 'management' ? 'active' : ''; ?>" onclick="switchTab(event, 'management')">
                                <i class="fas fa-cogs mr-2"></i>Manage School
                            </button>
                            <button class="tab-button <?php echo $defaultTab === 'analytics' ? 'active' : ''; ?>" onclick="switchTab(event, 'analytics')">
                                <i class="fas fa-chart-line mr-2"></i>Analytics
                            </button>
                            <button class="tab-button <?php echo $defaultTab === 'settings' ? 'active' : ''; ?>" onclick="switchTab(event, 'settings')">
                                <i class="fas fa-cog mr-2"></i>Settings
                            </button>
                            <button class="tab-button <?php echo $defaultTab === 'logs' ? 'active' : ''; ?>" onclick="switchTab(event, 'logs')">
                                <i class="fas fa-history mr-2"></i>Activity Logs
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <!-- Success/Error Messages -->
                <?php if (!empty($success)): ?>
                <div class="max-w-7xl mx-auto mb-4" id="successMessage">
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 rounded-xl">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2"></i>
                            <span><?php echo htmlspecialchars($success); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($error)): ?>
                <div class="max-w-7xl mx-auto mb-4" id="errorMessage">
                    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span><?php echo htmlspecialchars($error); ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- School Header -->
                <div class="max-w-7xl mx-auto mb-6 sm:mb-8">
                    <div class="bg-white detail-card p-4 sm:p-6">
                        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 sm:gap-6 mb-4 sm:mb-6">
                            <div class="flex items-center gap-3 sm:gap-4">
                                <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-xl sm:rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-university text-white text-lg sm:text-2xl"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h2 class="text-lg sm:text-2xl font-black text-slate-900 mb-1 truncate"><?php echo htmlspecialchars($school['name'] ?? 'Unnamed School'); ?></h2>
                                    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <i class="fas fa-circle text-[8px] mr-1"></i> <span class="truncate"><?php echo $statusText; ?></span>
                                        </span>
                                        <span class="text-xs sm:text-sm text-slate-500 font-medium">
                                            <i class="fas fa-hashtag mr-1"></i><?php echo $school['id']; ?>
                                        </span>
                                        <span class="text-xs sm:text-sm text-slate-500 font-medium truncate" style="max-width: 150px;">
                                            <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars(($school['city'] ?? '') . ', ' . ($school['state'] ?? '')); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex flex-wrap gap-2 sm:gap-3 mt-4 lg:mt-0">
                                <button onclick="openModal('editModal')" class="flex-1 xs:flex-none px-4 sm:px-5 py-2 sm:py-2.5 bg-white border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition flex items-center justify-center gap-2 touch-target min-h-[44px]">
                                    <i class="fas fa-edit"></i> <span class="hidden sm:inline">Edit</span>
                                </button>
                                <button onclick="openModal('actionsModal')" class="flex-1 xs:flex-none px-4 sm:px-5 py-2 sm:py-2.5 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition flex items-center justify-center gap-2 touch-target min-h-[44px]">
                                    <i class="fas fa-cog"></i> <span class="hidden sm:inline">Actions</span>
                                </button>
                                <button onclick="generateReport(<?php echo $schoolId; ?>)" class="flex-1 xs:flex-none px-4 sm:px-5 py-2 sm:py-2.5 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition flex items-center justify-center gap-2 touch-target min-h-[44px]">
                                    <i class="fas fa-file-export"></i> <span class="hidden sm:inline">Export</span>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                            <div class="bg-slate-50 rounded-xl p-3 sm:p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Active Users</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-lg sm:text-2xl font-black text-slate-900"><?php echo ($stats['students'] ?? 0) + ($stats['teachers'] ?? 0) + ($stats['admins'] ?? 0) + ($stats['parents'] ?? 0); ?></p>
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded">+12%</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-3 sm:p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Classes</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-lg sm:text-2xl font-black text-slate-900"><?php echo count($classes); ?></p>
                                    <span class="text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded"><?php echo array_sum(array_column($classes, 'student_count')); ?> students</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-3 sm:p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Subjects</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-lg sm:text-2xl font-black text-slate-900"><?php echo count($subjects); ?></p>
                                    <span class="text-xs font-bold text-amber-600 bg-amber-100 px-2 py-1 rounded">Active</span>
                                </div>
                            </div>
                            
                            <div class="bg-slate-50 rounded-xl p-3 sm:p-4">
                                <p class="text-xs font-bold text-slate-500 uppercase mb-1">Health Score</p>
                                <div class="flex items-end justify-between">
                                    <p class="text-lg sm:text-2xl font-black text-slate-900"><?php echo number_format($uptime, 1); ?>%</p>
                                    <div class="w-3 h-3 bg-emerald-500 rounded-full animate-pulse"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Overview -->
                <div id="overviewTab" class="max-w-7xl mx-auto space-y-4 sm:space-y-6 tab-content <?php echo $defaultTab === 'overview' ? 'active' : ''; ?>">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                        <!-- Left Column -->
                        <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                            <!-- Performance Metrics -->
                            <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4 sm:mb-6">
                                    <h3 class="text-base sm:text-lg font-bold text-slate-900">Performance Metrics</h3>
                                    <select class="text-sm border border-slate-200 rounded-lg px-3 py-1.5 w-full sm:w-auto">
                                        <option>Last 7 days</option>
                                        <option>Last 30 days</option>
                                        <option>Last quarter</option>
                                    </select>
                                </div>
                                <div class="h-48 sm:h-64">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Resource Utilization -->
                            <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                                <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">Resource Utilization</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <div>
                                        <div class="flex justify-between text-xs sm:text-sm mb-1">
                                            <span class="font-medium text-slate-700">Database Size</span>
                                            <span class="font-bold">
                                                <?php 
                                                    $dbSize = 0;
                                                    if (!empty($school['database_name'])) {
                                                        $dbSize = rand(50, 200);
                                                    }
                                                    echo $dbSize; ?> MB / 200 MB
                                            </span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-success" style="width: <?php echo ($dbSize / 200) * 100; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="flex justify-between text-xs sm:text-sm mb-1">
                                            <span class="font-medium text-slate-700">Storage Usage</span>
                                            <span class="font-bold">145 GB / 200 GB</span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-warning" style="width: 72.5%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="flex justify-between text-xs sm:text-sm mb-1">
                                            <span class="font-medium text-slate-700">Bandwidth</span>
                                            <span class="font-bold">18.2 TB / 25 TB</span>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar progress-success" style="width: 72.8%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="space-y-4 sm:space-y-6">
                            <!-- School Details -->
                            <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                                <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-3 sm:mb-4">Institution Details</h3>
                                <div class="space-y-3 sm:space-y-4">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Type</p>
                                        <p class="text-sm font-medium"><?php echo ucfirst($school['type'] ?? 'Secondary'); ?> School</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Subscription</p>
                                        <span class="inline-block px-3 py-1 bg-slate-900 text-white text-xs font-bold rounded-lg">
                                            <?php echo $subscription['plan_name'] ?? 'No Subscription'; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Onboarded</p>
                                        <p class="text-sm font-medium"><?php echo date('F j, Y', strtotime($school['created_at'] ?? 'now')); ?></p>
                                    </div>
                                    <?php if ($renewalDate): ?>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Renewal Date</p>
                                        <p class="text-sm font-medium"><?php echo $renewalDate; ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase">Monthly Cost</p>
                                        <p class="text-sm font-bold text-slate-900">₦<?php echo number_format($subscription['price_monthly'] ?? 0, 2); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Admin Contact -->
                            <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                                <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-3 sm:mb-4">Primary Administrator</h3>
                                <?php if ($admin): ?>
                                <div class="flex items-center gap-3 mb-3 sm:mb-4">
                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin['name'] ?? 'Admin'); ?>&background=2563eb&color=fff" class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl">
                                    <div class="min-w-0">
                                        <p class="font-bold text-slate-900 truncate"><?php echo htmlspecialchars($admin['name'] ?? 'Administrator'); ?></p>
                                        <p class="text-xs sm:text-sm text-slate-500 truncate"><?php echo ucfirst($admin['user_type'] ?? 'Administrator'); ?></p>
                                    </div>
                                </div>
                                <div class="space-y-1.5 sm:space-y-2">
                                    <div class="flex items-center gap-2 text-xs sm:text-sm">
                                        <i class="fas fa-envelope text-slate-400"></i>
                                        <span class="truncate"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></span>
                                    </div>
                                    <?php if (!empty($admin['phone'])): ?>
                                    <div class="flex items-center gap-2 text-xs sm:text-sm">
                                        <i class="fas fa-phone text-slate-400"></i>
                                        <span class="truncate"><?php echo htmlspecialchars($admin['phone']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex items-center gap-2 text-xs sm:text-sm">
                                        <i class="fas fa-calendar text-slate-400"></i>
                                        <span class="truncate">Last active: <?php echo !empty($admin['last_login_at']) ? date('M j, Y', strtotime($admin['last_login_at'])) : 'Never'; ?></span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <p class="text-slate-500 text-sm">No admin assigned</p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Quick Actions -->
                            <div class="bg-gradient-to-br from-blue-500 to-blue-600 detail-card p-4 sm:p-6 text-white">
                                <h3 class="text-base sm:text-lg font-bold mb-3 sm:mb-4">Quick Actions</h3>
                                <div class="space-y-2 sm:space-y-3">
                                    <button onclick="sendMessage(<?php echo $schoolId; ?>)" class="w-full text-left p-2.5 sm:p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-2 sm:gap-3 touch-target">
                                        <i class="fas fa-comment"></i>
                                        <span>Send Message</span>
                                    </button>
                                    <button onclick="scheduleCall(<?php echo $schoolId; ?>)" class="w-full text-left p-2.5 sm:p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-2 sm:gap-3 touch-target">
                                        <i class="fas fa-phone"></i>
                                        <span>Schedule Call</span>
                                    </button>
                                    <button onclick="viewBilling(<?php echo $schoolId; ?>)" class="w-full text-left p-2.5 sm:p-3 rounded-lg bg-white/10 hover:bg-white/20 transition flex items-center gap-2 sm:gap-3 touch-target">
                                        <i class="fas fa-receipt"></i>
                                        <span>View Billing</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity -->
                    <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">Recent Activity</h3>
                        <div class="timeline">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                <div class="timeline-item">
                                    <div class="bg-slate-50 rounded-xl p-3 sm:p-4">
                                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-1 sm:gap-2 mb-1 sm:mb-2">
                                            <p class="font-bold text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($activity['event'] ?? 'Activity'); ?></p>
                                            <span class="text-xs text-slate-500"><?php echo date('M j, H:i', strtotime($activity['created_at'] ?? 'now')); ?></span>
                                        </div>
                                        <p class="text-xs sm:text-sm text-slate-600"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                                        <?php if (!empty($activity['user_type'])): ?>
                                        <p class="text-xs text-slate-500 mt-1">By: <?php echo htmlspecialchars($activity['user_type']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-slate-500 text-center py-4">No recent activity</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Users -->
                <div id="usersTab" class="max-w-7xl mx-auto space-y-4 sm:space-y-6 tab-content <?php echo $defaultTab === 'users' ? 'active' : ''; ?>">
                    <div class="bg-white detail-card p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4 sm:mb-6">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900">User Management</h3>
                            <button onclick="addUser(<?php echo $schoolId; ?>)" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target w-full sm:w-auto">
                                <i class="fas fa-user-plus mr-2"></i>Add User
                            </button>
                        </div>
                        
                        <!-- Tabs for different user types -->
                        <div class="mb-6">
                            <div class="flex overflow-x-auto border-b border-slate-200">
                                <button class="user-type-tab tab-button active" onclick="switchUserType('admins')">
                                    <i class="fas fa-user-shield mr-2"></i>Administrators
                                </button>
                                <button class="user-type-tab tab-button" onclick="switchUserType('teachers')">
                                    <i class="fas fa-chalkboard-teacher mr-2"></i>Teachers (<?php echo count($teachers); ?>)
                                </button>
                                <button class="user-type-tab tab-button" onclick="switchUserType('students')">
                                    <i class="fas fa-graduation-cap mr-2"></i>Students (<?php echo count($students); ?>)
                                </button>
                            </div>
                        </div>
                        
                        <!-- Administrators Table -->
                        <div id="adminsTab" class="user-type-content active">
                            <div class="responsive-table">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-slate-100">
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">User</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Role</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Status</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Last Active</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php if ($admin): ?>
                                        <tr class="hover:bg-slate-50 transition">
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-2 sm:gap-3">
                                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($admin['name'] ?? 'Admin'); ?>&background=2563eb&color=fff" class="w-8 h-8 rounded-lg">
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($admin['name'] ?? 'Administrator'); ?></p>
                                                        <p class="text-xs sm:text-sm text-slate-500 truncate"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded"><?php echo ucfirst($admin['user_type'] ?? 'admin'); ?></span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="flex items-center gap-1 sm:gap-2">
                                                    <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                                    <span class="text-xs sm:text-sm text-slate-700">Active</span>
                                                </span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="text-xs sm:text-sm text-slate-600"><?php echo !empty($admin['last_login_at']) ? date('M j, Y', strtotime($admin['last_login_at'])) : 'Never'; ?></span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-1">
                                                    <button onclick="viewAdmin(<?php echo $admin['id'] ?? 0; ?>)" class="text-blue-600 hover:text-blue-700 touch-target p-1" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="editUser(<?php echo $admin['id'] ?? 0; ?>)" class="text-emerald-600 hover:text-emerald-700 touch-target p-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Teachers Table -->
                        <div id="teachersTab" class="user-type-content">
                            <?php if (!empty($teachers)): ?>
                            <div class="responsive-table">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-slate-100">
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Teacher</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Employee ID</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Subject/Specialization</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Status</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php foreach ($teachers as $teacher): ?>
                                        <tr class="hover:bg-slate-50 transition">
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-2 sm:gap-3">
                                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($teacher['full_name'] ?? 'Teacher'); ?>&background=10b981&color=fff" class="w-8 h-8 rounded-lg">
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($teacher['full_name'] ?? 'Teacher'); ?></p>
                                                        <p class="text-xs sm:text-sm text-slate-500 truncate"><?php echo htmlspecialchars($teacher['email'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="text-xs sm:text-sm font-medium text-slate-700"><?php echo htmlspecialchars($teacher['employee_id'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="px-2 py-1 bg-purple-100 text-purple-700 text-xs font-bold rounded"><?php echo htmlspecialchars($teacher['subject'] ?? $teacher['specialization'] ?? 'General'); ?></span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="flex items-center gap-1 sm:gap-2">
                                                    <?php if (($teacher['status'] ?? 1) == 1): ?>
                                                    <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                                    <span class="text-xs sm:text-sm text-slate-700">Active</span>
                                                    <?php else: ?>
                                                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                                    <span class="text-xs sm:text-sm text-slate-700">Inactive</span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-1">
                                                    <button onclick="viewTeacher(<?php echo $schoolId; ?>, <?php echo $teacher['id']; ?>)" class="text-blue-600 hover:text-blue-700 touch-target p-1" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="editTeacher(<?php echo $schoolId; ?>, <?php echo $teacher['id']; ?>)" class="text-emerald-600 hover:text-emerald-700 touch-target p-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-chalkboard-teacher text-4xl text-slate-300 mb-4"></i>
                                <p class="text-slate-500">No teachers found</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Students Table -->
                        <div id="studentsTab" class="user-type-content">
                            <?php if (!empty($students)): ?>
                            <div class="responsive-table">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-slate-100">
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Student</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Admission Number</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Class/Section</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Status</th>
                                            <th class="text-left py-3 px-2 sm:px-4 text-xs sm:text-sm font-bold text-slate-500">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php foreach ($students as $student): ?>
                                        <tr class="hover:bg-slate-50 transition">
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-2 sm:gap-3">
                                                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($student['full_name'] ?? 'Student'); ?>&background=f59e0b&color=fff" class="w-8 h-8 rounded-lg">
                                                    <div class="min-w-0">
                                                        <p class="font-medium text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></p>
                                                        <p class="text-xs sm:text-sm text-slate-500 truncate"><?php echo htmlspecialchars($student['email'] ?? ''); ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="text-xs sm:text-sm font-medium text-slate-700"><?php echo htmlspecialchars($student['admission_number'] ?? $student['student_id'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="px-2 py-1 bg-amber-100 text-amber-700 text-xs font-bold rounded">
                                                    <?php 
                                                        echo 'Class ' . htmlspecialchars($student['class_id'] ?? 'N/A');
                                                        if (!empty($student['section_id'])) {
                                                            echo ' - Section ' . htmlspecialchars($student['section_id']);
                                                        }
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <span class="flex items-center gap-1 sm:gap-2">
                                                    <?php if (($student['status'] ?? 'active') == 'active'): ?>
                                                    <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                                    <span class="text-xs sm:text-sm text-slate-700">Active</span>
                                                    <?php else: ?>
                                                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                                    <span class="text-xs sm:text-sm text-slate-700"><?php echo ucfirst($student['status'] ?? 'inactive'); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                            <td class="py-3 sm:py-4 px-2 sm:px-4">
                                                <div class="flex items-center gap-1">
                                                    <button onclick="viewStudent(<?php echo $schoolId; ?>, <?php echo $student['id']; ?>)" class="text-blue-600 hover:text-blue-700 touch-target p-1" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button onclick="editStudent(<?php echo $schoolId; ?>, <?php echo $student['id']; ?>)" class="text-emerald-600 hover:text-emerald-700 touch-target p-1" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-graduation-cap text-4xl text-slate-300 mb-4"></i>
                                <p class="text-slate-500">No students found</p>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Pagination/View All Links -->
                        <?php if (!empty($students) || !empty($teachers)): ?>
                        <div class="flex justify-between items-center mt-6 pt-6 border-t border-slate-100">
                            <div class="text-sm text-slate-500">
                                Showing 
                                <span id="currentUserCount">1</span> 
                                of 
                                <span id="totalUserCount"><?php echo count($students) + count($teachers) + ($admin ? 1 : 0); ?></span> 
                                users
                            </div>
                            <div class="flex gap-2">
                                <?php if (!empty($students)): ?>
                                <a href="./student/index.php?school_id=<?php echo $schoolId; ?>" class="px-4 py-2 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target text-sm">
                                    View All Students (<?php echo count($students); ?>)
                                </a>
                                <?php endif; ?>
                                <?php if (!empty($teachers)): ?>
                                <a href="./teacher/index.php?school_id=<?php echo $schoolId; ?>" class="px-4 py-2 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target text-sm">
                                    View All Teachers (<?php echo count($teachers); ?>)
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Tab Content: School Management -->
                <div id="managementTab" class="max-w-7xl mx-auto space-y-6 tab-content <?php echo $defaultTab === 'management' ? 'active' : ''; ?>">
                    <!-- School Management Header -->
                    <div class="bg-white detail-card p-6">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                            <div>
                                <h3 class="text-xl font-black text-slate-900">School Management</h3>
                                <p class="text-slate-600 mt-1">Manage classes, subjects, academic years, and sections</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="openModal('addClassModal')" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                    <i class="fas fa-plus mr-2"></i>Add Class
                                </button>
                                <button onclick="openModal('addSubjectModal')" class="px-4 py-2 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition touch-target">
                                    <i class="fas fa-book mr-2"></i>Add Subject
                                </button>
                            </div>
                        </div>
                        
                        <!-- Management Cards Grid -->
                        <div class="management-grid">
                            <!-- Classes Card -->
                            <div class="management-card">
                                <div class="management-header">
                                    <div class="management-icon bg-blue-100 text-blue-600">
                                        <i class="fas fa-chalkboard"></i>
                                    </div>
                                    <div class="management-actions">
                                        <button onclick="openModal('addClassModal')" class="management-action-btn text-blue-600" title="Add Class">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button onclick="refreshClasses()" class="management-action-btn text-slate-600" title="Refresh">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <h4 class="font-bold text-slate-900 text-lg mb-1">Classes</h4>
                                <p class="text-slate-600 text-sm mb-4">Manage class rooms and divisions</p>
                                
                                <?php if (!empty($classes)): ?>
                                <div class="space-y-2">
                                    <?php foreach (array_slice($classes, 0, 3) as $class): ?>
                                    <div class="flex items-center justify-between p-2 hover:bg-slate-50 rounded-lg">
                                        <div class="min-w-0">
                                            <p class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($class['name']); ?></p>
                                            <p class="text-xs text-slate-500 truncate">
                                                <?php echo htmlspecialchars($class['code']); ?> 
                                                <?php if (!empty($class['academic_year_name'])): ?>
                                                • <?php echo htmlspecialchars($class['academic_year_name']); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded">
                                                <?php echo $class['student_count'] ?? 0; ?> students
                                            </span>
                                            <button onclick="confirmDelete('class', <?php echo $class['id']; ?>, '<?php echo htmlspecialchars($class['name']); ?>')" class="text-red-600 hover:text-red-700 touch-target p-1" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($classes) > 3): ?>
                                    <div class="text-center pt-2">
                                        <p class="text-sm text-slate-500">+<?php echo count($classes) - 3; ?> more classes</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-chalkboard"></i>
                                    </div>
                                    <p class="empty-state-text">No classes created yet</p>
                                    <button onclick="openModal('addClassModal')" class="px-4 py-2 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition">
                                        Create First Class
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <div class="management-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count($classes); ?></div>
                                        <div class="stat-label">Total Classes</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo array_sum(array_column($classes, 'student_count')); ?></div>
                                        <div class="stat-label">Total Students</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count($sections); ?></div>
                                        <div class="stat-label">Sections</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Subjects Card -->
                            <div class="management-card">
                                <div class="management-header">
                                    <div class="management-icon bg-emerald-100 text-emerald-600">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <div class="management-actions">
                                        <button onclick="openModal('addSubjectModal')" class="management-action-btn text-emerald-600" title="Add Subject">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button onclick="refreshSubjects()" class="management-action-btn text-slate-600" title="Refresh">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <h4 class="font-bold text-slate-900 text-lg mb-1">Subjects</h4>
                                <p class="text-slate-600 text-sm mb-4">Manage academic subjects</p>
                                
                                <?php if (!empty($subjects)): ?>
                                <div class="space-y-2">
                                    <?php foreach (array_slice($subjects, 0, 3) as $subject): ?>
                                    <div class="flex items-center justify-between p-2 hover:bg-slate-50 rounded-lg">
                                        <div class="min-w-0">
                                            <p class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($subject['name']); ?></p>
                                            <p class="text-xs text-slate-500 truncate">
                                                <?php echo htmlspecialchars($subject['code']); ?> 
                                                • <?php echo ucfirst($subject['type']); ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs bg-<?php echo $subject['type'] == 'core' ? 'blue' : ($subject['type'] == 'elective' ? 'purple' : 'amber'); ?>-100 text-<?php echo $subject['type'] == 'core' ? 'blue' : ($subject['type'] == 'elective' ? 'purple' : 'amber'); ?>-700 px-2 py-1 rounded">
                                                <?php echo ucfirst($subject['type']); ?>
                                            </span>
                                            <button onclick="confirmDelete('subject', <?php echo $subject['id']; ?>, '<?php echo htmlspecialchars($subject['name']); ?>')" class="text-red-600 hover:text-red-700 touch-target p-1" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($subjects) > 3): ?>
                                    <div class="text-center pt-2">
                                        <p class="text-sm text-slate-500">+<?php echo count($subjects) - 3; ?> more subjects</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-book"></i>
                                    </div>
                                    <p class="empty-state-text">No subjects created yet</p>
                                    <button onclick="openModal('addSubjectModal')" class="px-4 py-2 bg-emerald-600 text-white font-bold rounded-xl hover:bg-emerald-700 transition">
                                        Create First Subject
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <div class="management-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count($subjects); ?></div>
                                        <div class="stat-label">Total Subjects</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count(array_filter($subjects, fn($s) => $s['type'] == 'core')); ?></div>
                                        <div class="stat-label">Core Subjects</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count(array_filter($subjects, fn($s) => $s['type'] == 'elective')); ?></div>
                                        <div class="stat-label">Electives</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Academic Years Card -->
                            <div class="management-card">
                                <div class="management-header">
                                    <div class="management-icon bg-purple-100 text-purple-600">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <div class="management-actions">
                                        <button onclick="openModal('addAcademicYearModal')" class="management-action-btn text-purple-600" title="Add Academic Year">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button onclick="refreshAcademicYears()" class="management-action-btn text-slate-600" title="Refresh">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <h4 class="font-bold text-slate-900 text-lg mb-1">Academic Years</h4>
                                <p class="text-slate-600 text-sm mb-4">Manage academic sessions</p>
                                
                                <?php if (!empty($academicYears)): ?>
                                <div class="space-y-2">
                                    <?php foreach (array_slice($academicYears, 0, 3) as $year): ?>
                                    <div class="flex items-center justify-between p-2 hover:bg-slate-50 rounded-lg">
                                        <div class="min-w-0">
                                            <p class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($year['name']); ?></p>
                                            <p class="text-xs text-slate-500 truncate">
                                                <?php echo date('M Y', strtotime($year['start_date'])); ?> - <?php echo date('M Y', strtotime($year['end_date'])); ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs bg-<?php echo $year['status'] == 'active' ? 'emerald' : ($year['status'] == 'upcoming' ? 'amber' : 'slate'); ?>-100 text-<?php echo $year['status'] == 'active' ? 'emerald' : ($year['status'] == 'upcoming' ? 'amber' : 'slate'); ?>-700 px-2 py-1 rounded">
                                                <?php echo ucfirst($year['status']); ?>
                                            </span>
                                            <button onclick="confirmDelete('academic_year', <?php echo $year['id']; ?>, '<?php echo htmlspecialchars($year['name']); ?>')" class="text-red-600 hover:text-red-700 touch-target p-1" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($academicYears) > 3): ?>
                                    <div class="text-center pt-2">
                                        <p class="text-sm text-slate-500">+<?php echo count($academicYears) - 3; ?> more years</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-calendar-alt"></i>
                                    </div>
                                    <p class="empty-state-text">No academic years created yet</p>
                                    <button onclick="openModal('addAcademicYearModal')" class="px-4 py-2 bg-purple-600 text-white font-bold rounded-xl hover:bg-purple-700 transition">
                                        Create Academic Year
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <div class="management-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count($academicYears); ?></div>
                                        <div class="stat-label">Total Years</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count(array_filter($academicYears, fn($y) => $y['status'] == 'active')); ?></div>
                                        <div class="stat-label">Active</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count(array_filter($academicYears, fn($y) => $y['status'] == 'upcoming')); ?></div>
                                        <div class="stat-label">Upcoming</div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Sections Card -->
                            <div class="management-card">
                                <div class="management-header">
                                    <div class="management-icon bg-amber-100 text-amber-600">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <div class="management-actions">
                                        <button onclick="openModal('addSectionModal')" class="management-action-btn text-amber-600" title="Add Section">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                        <button onclick="refreshSections()" class="management-action-btn text-slate-600" title="Refresh">
                                            <i class="fas fa-sync-alt"></i>
                                        </button>
                                    </div>
                                </div>
                                <h4 class="font-bold text-slate-900 text-lg mb-1">Sections</h4>
                                <p class="text-slate-600 text-sm mb-4">Manage class sections</p>
                                
                                <?php if (!empty($sections)): ?>
                                <div class="space-y-2">
                                    <?php foreach (array_slice($sections, 0, 3) as $section): ?>
                                    <div class="flex items-center justify-between p-2 hover:bg-slate-50 rounded-lg">
                                        <div class="min-w-0">
                                            <p class="font-medium text-slate-900 truncate"><?php echo htmlspecialchars($section['name']); ?></p>
                                            <p class="text-xs text-slate-500 truncate">
                                                <?php echo htmlspecialchars($section['class_name'] ?? 'Class ' . $section['class_id']); ?> • <?php echo htmlspecialchars($section['code']); ?>
                                            </p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs bg-slate-100 text-slate-700 px-2 py-1 rounded">
                                                <?php echo $section['capacity']; ?> capacity
                                            </span>
                                            <button onclick="confirmDelete('section', <?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['name']); ?>')" class="text-red-600 hover:text-red-700 touch-target p-1" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($sections) > 3): ?>
                                    <div class="text-center pt-2">
                                        <p class="text-sm text-slate-500">+<?php echo count($sections) - 3; ?> more sections</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <p class="empty-state-text">No sections created yet</p>
                                    <button onclick="openModal('addSectionModal')" class="px-4 py-2 bg-amber-600 text-white font-bold rounded-xl hover:bg-amber-700 transition">
                                        Create First Section
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <div class="management-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count($sections); ?></div>
                                        <div class="stat-label">Total Sections</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo array_sum(array_column($sections, 'capacity')); ?></div>
                                        <div class="stat-label">Total Capacity</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo count(array_unique(array_column($sections, 'class_id'))); ?></div>
                                        <div class="stat-label">Classes with Sections</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Add Actions -->
                        <div class="mt-8 pt-8 border-t border-slate-100">
                            <h4 class="font-bold text-slate-900 mb-4">Quick Add</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                                <button onclick="openModal('addClassModal')" class="p-4 border border-slate-200 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition text-left">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-chalkboard text-blue-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900">Add Class</p>
                                            <p class="text-xs text-slate-500">Create new class room</p>
                                        </div>
                                    </div>
                                </button>
                                
                                <button onclick="openModal('addSubjectModal')" class="p-4 border border-slate-200 rounded-xl hover:border-emerald-300 hover:bg-emerald-50 transition text-left">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center">
                                            <i class="fas fa-book text-emerald-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900">Add Subject</p>
                                            <p class="text-xs text-slate-500">Create new subject</p>
                                        </div>
                                    </div>
                                </button>
                                
                                <button onclick="openModal('addAcademicYearModal')" class="p-4 border border-slate-200 rounded-xl hover:border-purple-300 hover:bg-purple-50 transition text-left">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center">
                                            <i class="fas fa-calendar-alt text-purple-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900">Add Academic Year</p>
                                            <p class="text-xs text-slate-500">Create new session</p>
                                        </div>
                                    </div>
                                </button>
                                
                                <button onclick="openModal('addSectionModal')" class="p-4 border border-slate-200 rounded-xl hover:border-amber-300 hover:bg-amber-50 transition text-left">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center">
                                            <i class="fas fa-users text-amber-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900">Add Section</p>
                                            <p class="text-xs text-slate-500">Create new section</p>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tab Content: Analytics -->
                <div id="analyticsTab" class="max-w-7xl mx-auto space-y-4 sm:space-y-6 tab-content <?php echo $defaultTab === 'analytics' ? 'active' : ''; ?>">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6">
                        <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">Usage Trends</h3>
                            <div class="h-48 sm:h-64">
                                <canvas id="usageChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">User Distribution</h3>
                            <div class="h-48 sm:h-64">
                                <canvas id="distributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white detail-card p-4 sm:p-6 hover-lift">
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">Key Performance Indicators</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 sm:gap-6">
                            <div class="text-center">
                                <div class="text-xl sm:text-2xl md:text-3xl font-black text-blue-600 mb-1 sm:mb-2"><?php echo number_format($uptime, 1); ?>%</div>
                                <p class="text-xs sm:text-sm text-slate-600">Uptime (30 days)</p>
                            </div>
                            <div class="text-center">
                                <div class="text-xl sm:text-2xl md:text-3xl font-black text-emerald-600 mb-1 sm:mb-2">1.2s</div>
                                <p class="text-xs sm:text-sm text-slate-600">Avg Response Time</p>
                            </div>
                            <div class="text-center">
                                <div class="text-xl sm:text-2xl md:text-3xl font-black text-amber-600 mb-1 sm:mb-2">0.03%</div>
                                <p class="text-xs sm:text-sm text-slate-600">Error Rate</p>
                            </div>
                            <div class="text-center">
                                <div class="text-xl sm:text-2xl md:text-3xl font-black text-purple-600 mb-1 sm:mb-2">99.1%</div>
                                <p class="text-xs sm:text-sm text-slate-600">Satisfaction Score</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Settings -->
                <div id="settingsTab" class="max-w-7xl mx-auto space-y-4 sm:space-y-6 tab-content <?php echo $defaultTab === 'settings' ? 'active' : ''; ?>">
                    <div class="bg-white detail-card p-4 sm:p-6">
                        <h3 class="text-base sm:text-lg font-bold text-slate-900 mb-4 sm:mb-6">System Configuration</h3>
                        
                        <div class="space-y-4 sm:space-y-6">
                            <div>
                                <h4 class="font-bold text-slate-900 mb-3 sm:mb-4">General Settings</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6">
                                    <div class="form-group">
                                        <label class="form-label">API Rate Limit</label>
                                        <select class="form-input">
                                            <option>100 requests/min</option>
                                            <option selected>500 requests/min</option>
                                            <option>1000 requests/min</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Data Retention</label>
                                        <select class="form-input">
                                            <option>30 days</option>
                                            <option selected>90 days</option>
                                            <option>365 days</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="font-bold text-slate-900 mb-3 sm:mb-4">Security Settings</h4>
                                <div class="space-y-3 sm:space-y-4">
                                    <label class="flex items-center justify-between p-3 sm:p-4 border border-slate-200 rounded-xl">
                                        <div class="min-w-0 mr-3">
                                            <p class="font-medium text-slate-900">Two-Factor Authentication</p>
                                            <p class="text-xs sm:text-sm text-slate-500">Require 2FA for all admin accounts</p>
                                        </div>
                                        <input type="checkbox" class="toggle-switch" checked>
                                    </label>

                                    <label class="flex items-center justify-between p-3 sm:p-4 border border-slate-200 rounded-xl">
                                        <div class="min-w-0 mr-3">
                                            <p class="font-medium text-slate-900">IP Whitelisting</p>
                                            <p class="text-xs sm:text-sm text-slate-500">Restrict access to specific IP ranges</p>
                                        </div>
                                        <input type="checkbox" class="toggle-switch">
                                    </label>
                                </div>
                            </div>

                            <div class="pt-4 sm:pt-6 border-t border-slate-100">
                                <button onclick="saveSettings(<?php echo $schoolId; ?>)" class="w-full sm:w-auto px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                                    Save Configuration
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Logs -->
                <div id="logsTab" class="max-w-7xl mx-auto space-y-4 sm:space-y-6 tab-content <?php echo $defaultTab === 'logs' ? 'active' : ''; ?>">
                    <div class="bg-white detail-card p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3 mb-4 sm:mb-6">
                            <h3 class="text-base sm:text-lg font-bold text-slate-900">Activity Logs</h3>
                            <div class="flex flex-col xs:flex-row gap-2 sm:gap-3 w-full xs:w-auto">
                                <select class="text-sm border border-slate-200 rounded-lg px-3 py-1.5 w-full xs:w-auto">
                                    <option>All Activities</option>
                                    <option>User Actions</option>
                                    <option>System Events</option>
                                    <option>Security Events</option>
                                </select>
                                <button onclick="exportLogs(<?php echo $schoolId; ?>)" class="w-full xs:w-auto px-4 py-2 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                                    <i class="fas fa-download mr-2"></i>Export
                                </button>
                            </div>
                        </div>

                        <div class="space-y-3 sm:space-y-4">
                            <?php if (!empty($recentActivities)): ?>
                                <?php foreach ($recentActivities as $activity): ?>
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between p-3 sm:p-4 border border-slate-100 rounded-xl hover:bg-slate-50 transition">
                                        <div class="flex items-center gap-2 sm:gap-3 mb-2 sm:mb-0">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                                                <?php
                                                $icon = 'fa-history';
                                                $event = $activity['event'] ?? '';
                                                if (stripos($event, 'login') !== false) $icon = 'fa-sign-in-alt';
                                                elseif (stripos($event, 'logout') !== false) $icon = 'fa-sign-out-alt';
                                                elseif (stripos($event, 'create') !== false) $icon = 'fa-plus';
                                                elseif (stripos($event, 'update') !== false) $icon = 'fa-edit';
                                                elseif (stripos($event, 'delete') !== false) $icon = 'fa-trash';
                                                ?>
                                                <i class="fas <?php echo $icon; ?> text-blue-600 text-sm sm:text-base"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="font-medium text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($activity['event'] ?? 'Activity'); ?></p>
                                                <p class="text-xs sm:text-sm text-slate-500 truncate"><?php echo htmlspecialchars($activity['description'] ?? ''); ?></p>
                                            </div>
                                        </div>
                                        <div class="text-left sm:text-right">
                                            <p class="text-xs sm:text-sm font-medium text-slate-900"><?php echo date('h:i A', strtotime($activity['created_at'] ?? 'now')); ?></p>
                                            <p class="text-xs text-slate-500"><?php echo date('M j, Y', strtotime($activity['created_at'] ?? 'now')); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-slate-500 text-center py-4">No activity logs found</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Initialize timestamp
        function updateTimestamp() {
            const now = new Date();
            const options = {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            const timestampElement = document.getElementById('timestamp');
            if (timestampElement) {
                timestampElement.textContent = now.toLocaleDateString('en-US', options);
            }
        }

        updateTimestamp();
        setInterval(updateTimestamp, 1000);

        // Tab switching
        function switchTab(event, tabName) {
            // Update desktop tab buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            if (event && event.target) {
                event.target.classList.add('active');
            }

            // Update mobile navigation
            document.querySelectorAll('.mobile-nav-item').forEach(item => {
                item.classList.remove('active');
            });
            const mobileTab = document.querySelector(`.mobile-nav-item[href="#${tabName}Tab"]`);
            if (mobileTab) {
                mobileTab.classList.add('active');
            }

            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            const tabContent = document.getElementById(`${tabName}Tab`);
            if (tabContent) {
                tabContent.classList.add('active');
            }

            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);

            // Scroll to top of tab content on mobile
            if (window.innerWidth < 768) {
                setTimeout(() => {
                    tabContent?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }, 100);
            }
        }

        // Mobile tab switching
        function switchMobileTab(event, tabName) {
            event.preventDefault();

            // Update mobile navigation
            document.querySelectorAll('.mobile-nav-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.closest('.mobile-nav-item').classList.add('active');

            // Update desktop tab buttons if visible
            const desktopTabButtons = document.querySelectorAll('.tab-button');
            if (desktopTabButtons.length > 0) {
                desktopTabButtons.forEach((btn, index) => {
                    btn.classList.remove('active');
                    if (btn.textContent.toLowerCase().includes(tabName)) {
                        btn.classList.add('active');
                    }
                });
            }

            // Show selected tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            const tabContent = document.getElementById(`${tabName}Tab`);
            if (tabContent) {
                tabContent.classList.add('active');
            }

            // Update URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);

            // Scroll to top of tab content
            setTimeout(() => {
                tabContent?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }, 100);
        }

        // Initialize tabs based on URL parameter
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            if (tabParam) {
                switchTab(null, tabParam);
            }
        });

        // User Type switching
        function switchUserType(userType) {
            // Update tab buttons
            document.querySelectorAll('.user-type-tab').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');

            // Show selected content
            document.querySelectorAll('.user-type-content').forEach(content => {
                content.classList.remove('active');
            });
            const content = document.getElementById(`${userType}Tab`);
            if (content) {
                content.classList.add('active');
            }

            // Update user count
            updateUserCount(userType);
        }

        function updateUserCount(userType) {
            let count = 0;
            if (userType === 'admins') {
                count = <?php echo $admin ? 1 : 0; ?>;
            } else if (userType === 'teachers') {
                count = <?php echo count($teachers); ?>;
            } else if (userType === 'students') {
                count = <?php echo count($students); ?>;
            }

            const currentCount = document.getElementById('currentUserCount');
            const totalCount = document.getElementById('totalUserCount');
            if (currentCount) {
                currentCount.textContent = count;
            }
            if (totalCount) {
                totalCount.textContent = <?php echo count($students) + count($teachers) + ($admin ? 1 : 0); ?>;
            }
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        }

        // Confirm delete function
        function confirmDelete(type, id, name) {
            const deleteModal = document.getElementById('deleteModal');
            const deleteMessage = document.getElementById('deleteMessage');
            const deleteAction = document.getElementById('deleteAction');
            const deleteId = document.getElementById('deleteId');
            
            const typeNames = {
                'class': 'Class',
                'subject': 'Subject',
                'academic_year': 'Academic Year',
                'section': 'Section'
            };
            
            deleteMessage.textContent = `Are you sure you want to delete the ${typeNames[type]} "${name}"? This action cannot be undone.`;
            deleteAction.value = `delete_${type}`;
            deleteId.value = id;
            
            openModal('deleteModal');
        }

        // Handle form submissions
        document.addEventListener('DOMContentLoaded', function() {
            // Let forms submit normally - they will redirect after submission
            // All validation is handled server-side
        });

        async function performAction(action, schoolId) {
            const actions = {
                backup: {
                    message: 'Creating backup...',
                    endpoint: 'backup_school.php'
                },
                restart: {
                    message: 'Restarting services...',
                    endpoint: 'restart_school.php'
                },
                suspend: {
                    message: 'Suspending school...',
                    endpoint: 'suspend_school.php'
                },
                terminate: {
                    message: 'Terminating school...',
                    endpoint: 'terminate_school.php'
                }
            };

            const actionInfo = actions[action];
            if (!actionInfo) return;

            showNotification(actionInfo.message, 'info');
            closeModal('actionsModal');

            try {
                const response = await fetch(actionInfo.endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        school_id: schoolId,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showNotification(`${action} completed successfully`, 'success');

                    // Reload page for suspend/terminate actions
                    if (action === 'suspend' || action === 'terminate') {
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    showNotification(result.message || `Failed to ${action}`, 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        // View functions for different user types
        function viewAdmin(adminId) {
            showNotification(`Viewing admin details for ID: ${adminId}`, 'info');
            window.open(`./admin/index.php?school_id=<?php echo $schoolId; ?>&admin_id=${adminId}`, '_blank');
        }

        function viewTeacher(schoolId, teacherId) {
            showNotification(`Viewing teacher details for ID: ${teacherId}`, 'info');
            window.location.href = `./teacher/index.php?school_id=${schoolId}&teacher_id=${teacherId}`;
        }

        function viewStudent(schoolId, studentId) {
            showNotification(`Viewing student details for ID: ${studentId}`, 'info');
            window.location.href = `./student/index.php?school_id=${schoolId}&student_id=${studentId}`;
        }

        function editTeacher(schoolId, teacherId) {
            showNotification(`Editing teacher ID: ${teacherId}`, 'info');
            window.open(`./teacher/edit.php?school_id=${schoolId}&teacher_id=${teacherId}`, '_blank');
        }

        function editStudent(schoolId, studentId) {
            showNotification(`Editing student ID: ${studentId}`, 'info');
            window.open(`./student/edit.php?school_id=${schoolId}&student_id=${studentId}`, '_blank');
        }

        function addUser(schoolId, userType = 'admin') {
            // Determine which user type to add based on current tab
            const activeTab = document.querySelector('.user-type-tab.active');
            if (activeTab) {
                if (activeTab.textContent.includes('Teachers')) {
                    userType = 'teacher';
                } else if (activeTab.textContent.includes('Students')) {
                    userType = 'student';
                }
            }

            const url = `add_user.php?school_id=${schoolId}&user_type=${userType}`;
            window.open(url, '_blank');
        }

        function showNotification(message, type) {
            // Remove existing notifications
            document.querySelectorAll('[data-notification]').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 left-4 sm:left-auto px-6 py-3 rounded-xl shadow-lg z-[1001] ${
                type === 'success' ? 'bg-emerald-500 text-white' :
                type === 'error' ? 'bg-red-500 text-white' :
                'bg-blue-500 text-white'
            }`;
            notification.setAttribute('data-notification', 'true');
            notification.innerHTML = `
                <div class="flex items-center gap-3">
                    <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(notification);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Chart initialization
        function initCharts() {
            // Performance Chart
            const perfCanvas = document.getElementById('performanceChart');
            if (perfCanvas) {
                const perfCtx = perfCanvas.getContext('2d');
                new Chart(perfCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($chartData['labels']); ?>,
                        datasets: [{
                            label: 'Active Users',
                            data: <?php echo json_encode($chartData['users']); ?>,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: window.innerWidth < 640 ? 2 : 4,
                            borderWidth: window.innerWidth < 640 ? 1.5 : 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    drawBorder: false
                                },
                                ticks: {
                                    font: {
                                        size: window.innerWidth < 640 ? 10 : 12
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: window.innerWidth < 640 ? 10 : 12
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Usage Chart
            const usageCanvas = document.getElementById('usageChart');
            if (usageCanvas) {
                const usageCtx = usageCanvas.getContext('2d');
                new Chart(usageCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($chartData['labels']); ?>,
                        datasets: [{
                            label: 'API Calls',
                            data: <?php echo json_encode($chartData['apiCalls']); ?>,
                            backgroundColor: '#3b82f6'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                ticks: {
                                    font: {
                                        size: window.innerWidth < 640 ? 10 : 12
                                    },
                                    callback: function(value) {
                                        if (value >= 1000) {
                                            return (value / 1000) + 'k';
                                        }
                                        return value;
                                    }
                                }
                            },
                            x: {
                                ticks: {
                                    font: {
                                        size: window.innerWidth < 640 ? 10 : 12
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Distribution Chart
            const distCanvas = document.getElementById('distributionChart');
            if (distCanvas) {
                const distCtx = distCanvas.getContext('2d');
                new Chart(distCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo json_encode($userDistribution['labels']); ?>,
                        datasets: [{
                            data: <?php echo json_encode($userDistribution['data']); ?>,
                            backgroundColor: [
                                '#3b82f6',
                                '#10b981',
                                '#8b5cf6',
                                '#f59e0b'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: {
                                        size: window.innerWidth < 640 ? 10 : 12
                                    },
                                    padding: window.innerWidth < 640 ? 10 : 20
                                }
                            }
                        }
                    }
                });
            }
        }

        // Generate report
        async function generateReport(schoolId) {
            showNotification('Generating report...', 'info');

            try {
                const response = await fetch('generate_report.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        school_id: schoolId,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    })
                });

                const result = await response.json();

                if (result.success && result.download_url) {
                    showNotification('Report generated successfully', 'success');

                    // Download report
                    const link = document.createElement('a');
                    link.href = result.download_url;
                    link.download = result.filename || 'school_report.pdf';
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    showNotification(result.message || 'Failed to generate report', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        // Sidebar functionality
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('active');
            }
        }

        function toggleDropdown(id) {
            const dropdown = document.getElementById(id);
            if (dropdown) {
                dropdown.classList.toggle('dropdown-open');
            }
        }

        function mobileSidebarToggle() {
            toggleSidebar();
        }

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();

            // Initialize user count for default tab (admins)
            updateUserCount('admins');

            // Handle window resize
            let resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() {
                    // Reinitialize charts on resize
                    document.querySelectorAll('canvas').forEach(canvas => {
                        const chart = Chart.getChart(canvas);
                        if (chart) {
                            chart.destroy();
                        }
                    });
                    initCharts();
                }, 250);
            });
        });

        // Utility functions for quick actions
        function sendMessage(schoolId) {
            showNotification(`Opening message interface for school ${schoolId}...`, 'info');
        }

        function scheduleCall(schoolId) {
            showNotification('Schedule call feature coming soon', 'info');
        }

        function viewBilling(schoolId) {
            window.open(`billing.php?school_id=${schoolId}`, '_blank');
        }

        async function saveSettings(schoolId) {
            showNotification('Saving settings...', 'info');
            
            // Simulate API call
            setTimeout(() => {
                showNotification('Settings saved successfully', 'success');
            }, 1000);
        }

        async function exportLogs(schoolId) {
            showNotification('Exporting activity logs...', 'info');

            try {
                const response = await fetch('export_logs.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        school_id: schoolId,
                        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
                    })
                });

                const result = await response.json();

                if (result.success && result.download_url) {
                    showNotification('Logs exported successfully', 'success');

                    // Download logs
                    const link = document.createElement('a');
                    link.href = result.download_url;
                    link.download = result.filename || 'activity_logs.csv';
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } else {
                    showNotification(result.message || 'Failed to export logs', 'error');
                }
            } catch (error) {
                showNotification('Network error: ' + error.message, 'error');
            }
        }

        // Refresh functions to reload page
        function refreshClasses() {
            showNotification('Refreshing classes...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        function refreshSubjects() {
            showNotification('Refreshing subjects...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        function refreshAcademicYears() {
            showNotification('Refreshing academic years...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        function refreshSections() {
            showNotification('Refreshing sections...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (window.innerWidth < 1024 &&
                sidebar &&
                !sidebar.contains(e.target) &&
                !e.target.closest('[onclick*="mobileSidebarToggle"]')) {
                sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');
            }
        });

        // Handle escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                if (sidebar) sidebar.classList.add('-translate-x-full');
                if (overlay) overlay.classList.remove('active');

                // Close modals
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = 'auto';
            }
        });
    </script>
</body>
</html>