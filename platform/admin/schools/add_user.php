<?php
// create_user.php
// Page for super admin to create users in any school

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
$schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : 0;
$userType = isset($_GET['user_type']) ? $_GET['user_type'] : 'student';

// Validate school ID
if ($schoolId <= 0) {
    header("Location: ../index.php?error=invalid_school");
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

// Check if school database exists
if (!Database::schoolDatabaseExists($school['database_name'])) {
    die("School database not accessible. Please contact support.");
}

// Connect to school's database
$schoolDb = Database::getSchoolConnection($school['database_name']);

// Get available classes for student assignment
$classes = [];
if ($userType === 'student') {
    $classStmt = $schoolDb->prepare("
        SELECT c.* 
        FROM classes c 
        WHERE c.is_active = 1 
        ORDER BY c.grade_level, c.name
    ");
    $classStmt->execute();
    $classes = $classStmt->fetchAll();
}

// Get existing parents for linking
$existingParents = [];
if ($userType === 'student' || $userType === 'parent') {
    $parentStmt = $schoolDb->prepare("
        SELECT u.id, u.name, u.email, u.phone
        FROM users u
        WHERE u.user_type = 'parent' AND u.is_active = 1
        ORDER BY u.name
    ");
    $parentStmt->execute();
    $existingParents = $parentStmt->fetchAll();
}

// Get existing students for parent linking
$existingStudents = [];
if ($userType === 'parent') {
    $studentStmt = $schoolDb->prepare("
        SELECT s.id, s.first_name, s.middle_name, s.last_name, s.admission_number, c.name as class_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        WHERE s.status = 'active'
        ORDER BY s.first_name, s.last_name
    ");
    $studentStmt->execute();
    $existingStudents = $studentStmt->fetchAll();
}

// Initialize variables
$errors = [];
$success = false;
$createdUsers = [];
$credentials = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token - IMPORTANT: Use session-based validation
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $errors[] = "Invalid or expired CSRF token. Please refresh the page and try again.";
    }

    if (empty($errors)) {
        try {
            $schoolDb->beginTransaction();

            // Handle student creation
            if ($userType === 'student') {
                $studentData = validateStudentData($_POST);
                $errors = array_merge($errors, $studentData['errors']);

                if (empty($errors)) {
                    // Create student user
                    $studentUser = createUser($schoolDb, $schoolId, $studentData, 'student');
                    $createdUsers[] = $studentUser;

                    // Create student record
                    $studentId = createStudent($schoolDb, $schoolId, $studentUser['id'], $studentData);

                    // Handle parent linking/creation if parent data is provided
                    $parentData = validateParentData($_POST);

                    if (!empty($parentData['email']) || !empty($parentData['phone']) || !empty($parentData['name'])) {
                        // Check if linking to existing parent
                        $parentId = null;
                        $linkToExisting = isset($_POST['link_existing_parent']) ? true : false;

                        if ($linkToExisting && !empty($_POST['existing_parent_id'])) {
                            $parentId = (int)$_POST['existing_parent_id'];
                        } else {
                            // Create new parent user only if we have enough data
                            if (!empty($parentData['name']) && (!empty($parentData['email']) || !empty($parentData['phone']))) {
                                $parentUser = createUser($schoolDb, $schoolId, $parentData, 'parent');
                                $createdUsers[] = $parentUser;
                                $parentId = $parentUser['id'];

                                // Store parent credentials
                                $credentials[] = [
                                    'type' => 'parent',
                                    'name' => $parentData['name'],
                                    'email' => $parentData['email'],
                                    'phone' => $parentData['phone'],
                                    'password' => $parentUser['password']
                                ];
                            }
                        }

                        // Create guardian relationship if we have a parent ID
                        if ($parentId && $studentId) {
                            createGuardian($schoolDb, $schoolId, $parentId, $studentId, $parentData);

                            // Send email to parent if email provided
                            if (!empty($parentData['email']) && !empty($parentData['name'])) {
                                sendParentWelcomeEmail(
                                    $parentData['email'],
                                    $parentData['name'],
                                    $studentData['first_name'] . ' ' . $studentData['last_name'],
                                    isset($parentUser) ? $parentUser['password'] : ''
                                );
                            }
                        }
                    }

                    // Store student credentials
                    $credentials[] = [
                        'type' => 'student',
                        'name' => $studentData['first_name'] . ' ' . $studentData['last_name'],
                        'email' => $studentData['email'],
                        'phone' => $studentData['phone'],
                        'password' => $studentUser['password']
                    ];
                }
            }
            // Handle teacher creation
            elseif ($userType === 'teacher') {
                $teacherData = validateTeacherData($_POST);
                $errors = array_merge($errors, $teacherData['errors']);

                if (empty($errors)) {
                    $teacherUser = createUser($schoolDb, $schoolId, $teacherData, 'teacher');
                    $createdUsers[] = $teacherUser;

                    $teacherId = createTeacher($schoolDb, $schoolId, $teacherUser['id'], $teacherData);

                    // Assign teacher role
                    assignTeacherRole($schoolDb, $teacherUser['id']);

                    // Store teacher credentials
                    $credentials[] = [
                        'type' => 'teacher',
                        'name' => $teacherData['name'],
                        'email' => $teacherData['email'],
                        'phone' => $teacherData['phone'],
                        'password' => $teacherUser['password']
                    ];
                }
            }
            // Handle parent creation (with student linking)
            elseif ($userType === 'parent') {
                $parentData = validateParentData($_POST);
                $errors = array_merge($errors, $parentData['errors']);

                if (empty($errors)) {
                    $parentUser = createUser($schoolDb, $schoolId, $parentData, 'parent');
                    $createdUsers[] = $parentUser;

                    // Link to selected students
                    if (!empty($_POST['student_ids'])) {
                        foreach ($_POST['student_ids'] as $studentId) {
                            createGuardian($schoolDb, $schoolId, $parentUser['id'], (int)$studentId, $parentData);
                        }
                    }

                    // Store parent credentials
                    $credentials[] = [
                        'type' => 'parent',
                        'name' => $parentData['name'],
                        'email' => $parentData['email'],
                        'phone' => $parentData['phone'],
                        'password' => $parentUser['password']
                    ];

                    // Send welcome email
                    if (!empty($parentData['email'])) {
                        $studentNames = [];
                        if (!empty($_POST['student_ids'])) {
                            $studentStmt = $schoolDb->prepare("
                                SELECT CONCAT(first_name, ' ', last_name) as name 
                                FROM students 
                                WHERE id = ?
                            ");
                            foreach ($_POST['student_ids'] as $studentId) {
                                $studentStmt->execute([$studentId]);
                                $student = $studentStmt->fetch();
                                if ($student) $studentNames[] = $student['name'];
                            }
                        }

                        sendParentWelcomeEmail(
                            $parentData['email'],
                            $parentData['name'],
                            implode(', ', $studentNames),
                            $parentUser['password']
                        );
                    }
                }
            }

            if (empty($errors)) {
                $schoolDb->commit();
                $success = true;

                // Store in session for display
                $_SESSION['created_users'] = $createdUsers;
                $_SESSION['credentials'] = $credentials;
            } else {
                $schoolDb->rollBack();
            }
        } catch (Exception $e) {
            $schoolDb->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
            error_log("User creation error: " . $e->getMessage());
        }
    }
}

// Helper functions
function validateStudentData($post)
{
    $errors = [];
    $data = [
        'name' => trim($post['name'] ?? ''),
        'first_name' => trim($post['first_name'] ?? ''),
        'last_name' => trim($post['last_name'] ?? ''),
        'email' => trim($post['email'] ?? ''),
        'phone' => trim($post['phone'] ?? ''),
        'gender' => $post['gender'] ?? 'male',
        'date_of_birth' => $post['date_of_birth'] ?? '',
        'admission_number' => trim($post['admission_number'] ?? ''),
        'class_id' => (int)($post['class_id'] ?? 0),
        'admission_date' => $post['admission_date'] ?? date('Y-m-d'),
        'address' => trim($post['address'] ?? ''),
        'middle_name' => trim($post['middle_name'] ?? ''),
        'blood_group' => $post['blood_group'] ?? null,
        'religion' => $post['religion'] ?? null
    ];

    if (empty($data['name'])) $errors[] = "Name is required";
    if (empty($data['first_name'])) $errors[] = "First name is required";
    if (empty($data['last_name'])) $errors[] = "Last name is required";
    if (empty($data['admission_number'])) $errors[] = "Admission number is required";
    if (empty($data['date_of_birth'])) $errors[] = "Date of birth is required";
    if ($data['class_id'] <= 0) $errors[] = "Please select a class";

    return ['data' => $data, 'errors' => $errors];
}

function validateParentData($post)
{
    $data = [
        'name' => trim($post['parent_name'] ?? ($post['name'] ?? '')),
        'email' => trim($post['parent_email'] ?? ($post['email'] ?? '')),
        'phone' => trim($post['parent_phone'] ?? ($post['phone'] ?? '')),
        'relationship' => $post['relationship'] ?? 'parent',
        'address' => trim($post['parent_address'] ?? ($post['address'] ?? '')),
        'is_primary' => isset($post['is_primary']) ? 1 : 0,
        'emergency_contact' => isset($post['emergency_contact']) ? 1 : 0,
        'can_pickup' => isset($post['can_pickup']) ? 1 : 0,
        'gender' => $post['gender'] ?? 'male',
        'date_of_birth' => $post['date_of_birth'] ?? null,
        'blood_group' => $post['blood_group'] ?? null
    ];

    $errors = [];
    if (empty($data['name'])) $errors[] = "Name is required";
    if (empty($data['email']) && empty($data['phone'])) {
        $errors[] = "Either email or phone is required";
    }

    return ['data' => $data, 'errors' => $errors];
}

function validateTeacherData($post)
{
    $errors = [];
    $data = [
        'name' => trim($post['name'] ?? ''),
        'email' => trim($post['email'] ?? ''),
        'phone' => trim($post['phone'] ?? ''),
        'gender' => $post['gender'] ?? 'male',
        'employee_id' => trim($post['employee_id'] ?? ''),
        'qualification' => trim($post['qualification'] ?? ''),
        'specialization' => trim($post['specialization'] ?? ''),
        'experience_years' => (int)($post['experience_years'] ?? 0),
        'joining_date' => $post['joining_date'] ?? date('Y-m-d'),
        'address' => trim($post['address'] ?? ''),
        'date_of_birth' => $post['date_of_birth'] ?? null,
        'blood_group' => $post['blood_group'] ?? null
    ];

    if (empty($data['name'])) $errors[] = "Name is required";
    if (empty($data['employee_id'])) $errors[] = "Employee ID is required";
    if (empty($data['email'])) $errors[] = "Email is required";

    return ['data' => $data, 'errors' => $errors];
}

function createUser($db, $schoolId, $data, $userType) {
    // Generate password
    $password = bin2hex(random_bytes(8));
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Prepare user data with null coalescing for safety
    $userData = [
        'school_id' => $schoolId,
        'name' => $data['name'] ?? '',
        'email' => !empty($data['email']) ? $data['email'] : null,
        'phone' => !empty($data['phone']) ? $data['phone'] : null,
        'password' => $hashedPassword,
        'user_type' => $userType,
        'gender' => $data['gender'] ?? 'male',
        'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
        'address' => !empty($data['address']) ? $data['address'] : null,
        'blood_group' => !empty($data['blood_group']) ? $data['blood_group'] : null,
        'religion' => !empty($data['religion']) ? $data['religion'] : null  // ADDED: religion for users table
    ];
    
    // Check for required fields
    if (empty($userData['name'])) {
        throw new Exception("User name is required");
    }
    
    // Insert user - username is optional (NULL)
    // Now includes religion column
    $stmt = $db->prepare("
        INSERT INTO users (
            school_id, name, email, phone, username,
            password, user_type, gender, date_of_birth, 
            address, blood_group, religion, is_active, created_at
        ) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
    ");
    
    $stmt->execute([
        $userData['school_id'],        // 1. school_id
        $userData['name'],             // 2. name
        $userData['email'],            // 3. email
        $userData['phone'],            // 4. phone
        // 5. username is NULL (placeholder in SQL)
        $userData['password'],         // 6. password
        $userData['user_type'],        // 7. user_type
        $userData['gender'],           // 8. gender
        $userData['date_of_birth'],    // 9. date_of_birth
        $userData['address'],          // 10. address
        $userData['blood_group'],      // 11. blood_group
        $userData['religion'],         // 12. religion (NEW)
        // 13. is_active is hardcoded as 1 in SQL
        // created_at is NOW() in SQL
    ]);
    
    $userId = $db->lastInsertId();
    
    // Assign default role based on user type
    $roleMap = [
        'student' => 4,
        'teacher' => 3, 
        'parent' => 5,
        'admin' => 2
    ];
    
    if (isset($roleMap[$userType])) {
        try {
            $roleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $roleStmt->execute([$userId, $roleMap[$userType]]);
        } catch (Exception $e) {
            // Log but continue if role assignment fails
            error_log("Failed to assign role to user $userId: " . $e->getMessage());
        }
    }
    
    return [
        'id' => $userId,
        'password' => $password,
        'email' => $userData['email'] ?? null,
        'phone' => $userData['phone'] ?? null
    ];
}

function assignTeacherRole($db, $userId)
{
    // Get teacher role ID (role ID 3 is for teachers based on your database)
    $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'teacher' AND school_id = 0 LIMIT 1");
    $roleStmt->execute();
    $role = $roleStmt->fetch();

    if ($role) {
        try {
            $userRoleStmt = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
            $userRoleStmt->execute([$userId, $role['id']]);
        } catch (Exception $e) {
            // Log error but continue
            error_log("Failed to assign teacher role: " . $e->getMessage());
        }
    }
}

function createStudent($db, $schoolId, $userId, $data)
{
    $stmt = $db->prepare("
        INSERT INTO students (
            school_id, user_id, admission_number,
            first_name, middle_name, last_name,
            date_of_birth, class_id, admission_date, 
            blood_group, religion, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");

    $stmt->execute([
        $schoolId,
        $userId,
        $data['admission_number'],
        $data['first_name'],
        !empty($data['middle_name']) ? $data['middle_name'] : null,
        $data['last_name'],
        $data['date_of_birth'],
        $data['class_id'],
        $data['admission_date'],
        !empty($data['blood_group']) ? $data['blood_group'] : null,
        !empty($data['religion']) ? $data['religion'] : null
    ]);

    return $db->lastInsertId();
}

function createTeacher($db, $schoolId, $userId, $data)
{
    $stmt = $db->prepare("
        INSERT INTO teachers (
            school_id, user_id, employee_id,
            qualification, specialization,
            experience_years, joining_date,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");

    $stmt->execute([
        $schoolId,
        $userId,
        $data['employee_id'],
        $data['qualification'],
        $data['specialization'],
        $data['experience_years'],
        $data['joining_date']
    ]);

    return $db->lastInsertId();
}

function createGuardian($db, $schoolId, $parentId, $studentId, $data)
{
    // Check if guardian relationship already exists
    $checkStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM guardians 
        WHERE user_id = ? AND student_id = ?
    ");
    $checkStmt->execute([$parentId, $studentId]);
    $result = $checkStmt->fetch();

    if ($result['count'] > 0) {
        // Update existing relationship
        $updateStmt = $db->prepare("
            UPDATE guardians SET 
                relationship = ?, 
                is_primary = ?,
                emergency_contact = ?, 
                can_pickup = ?
            WHERE user_id = ? AND student_id = ?
        ");
        $updateStmt->execute([
            $data['relationship'] ?? 'parent',
            $data['is_primary'] ?? 0,
            $data['emergency_contact'] ?? 0,
            $data['can_pickup'] ?? 0,
            $parentId,
            $studentId
        ]);
        return;
    }

    // Insert new guardian relationship
    $stmt = $db->prepare("
        INSERT INTO guardians (
            school_id, user_id, student_id,
            relationship, is_primary,
            emergency_contact, can_pickup
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $schoolId,
        $parentId,
        $studentId,
        $data['relationship'] ?? 'parent',
        $data['is_primary'] ?? 0,
        $data['emergency_contact'] ?? 0,
        $data['can_pickup'] ?? 0
    ]);
}

function sendParentWelcomeEmail($email, $parentName, $studentName, $password)
{
    global $school;

    $subject = "Welcome to " . ($school['name'] ?? 'School') . " - Parent Portal Access";
    $message = "
    <html>
    <body>
        <h2>Welcome to our School Portal!</h2>
        <p>Dear $parentName,</p>
        <p>You have been registered as a parent for <strong>$studentName</strong> at " . ($school['name'] ?? 'School') . ".</p>
        
        <div style='background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;'>
            <h3>Your Login Credentials:</h3>
            <p><strong>Login Email:</strong> $email</p>
            <p><strong>Password:</strong> $password</p>
            <p><strong>Login URL:</strong> " . (APP_URL . "/tenant/" . $school['slug'] . "/parent/login.php") . "</p>
        </div>
        
        <p>Please login to the parent portal to:</p>
        <ul>
            <li>View your child's attendance and grades</li>
            <li>Pay school fees online</li>
            <li>Receive important announcements</li>
            <li>Communicate with teachers</li>
        </ul>
        
        <p style='color: #666; font-size: 12px;'>
            <strong>Security Note:</strong> For security reasons, please change your password after first login.
        </p>
        
        <p>Best regards,<br>
        School Administration</p>
    </body>
    </html>
    ";

    // Send email (implementation depends on your email setup)
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . ($school['name'] ?? 'School') . " <noreply@" . parse_url(APP_URL, PHP_URL_HOST) . ">" . "\r\n";

    if (APP_DEBUG) {
        error_log("Would send email to $email with subject: $subject");
        return true;
    } else {
        return mail($email, $subject, $message, $headers);
    }
}

// Generate CSRF token for the form
$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <title>Create <?php echo ucfirst($userType); ?> | <?php echo htmlspecialchars($school['name']); ?> | AcademixSuite Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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

        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            background: white;
            transition: all 0.2s ease;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center;
            background-repeat: no-repeat;
            background-size: 1.5em 1.5em;
            padding-right: 2.5rem;
        }

        .form-select:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .toggle-switch {
            width: 44px;
            height: 24px;
            background: #cbd5e1;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        .toggle-switch:checked {
            background: #2563eb;
        }

        .toggle-switch::before {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: all 0.2s ease;
        }

        .toggle-switch:checked::before {
            transform: translateX(20px);
        }

        .touch-target {
            min-height: 44px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

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

        .alert {
            padding: 14px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fee2e2;
            color: #991b1b;
        }

        .alert-success {
            background-color: #f0fdf4;
            border: 1px solid #dcfce7;
            color: #166534;
        }

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

        .parent-selector {
            max-height: 200px;
            overflow-y: auto;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px;
        }

        .parent-option {
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .parent-option:hover {
            background-color: #f8fafc;
            border-color: #cbd5e1;
        }

        .parent-option.selected {
            background-color: #eff6ff;
            border-color: #2563eb;
        }

        @media (max-width: 640px) {
            .xs-hidden {
                display: none !important;
            }

            .xs-flex-col {
                flex-direction: column;
            }

            .xs-w-full {
                width: 100%;
            }

            .xs-space-y-2>*+* {
                margin-top: 0.5rem;
            }
        }

        .loading-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1001;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .loading-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #e2e8f0;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .form-checkbox {
            width: 18px;
            height: 18px;
            border: 2px solid #cbd5e1;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .form-checkbox:checked {
            background-color: #2563eb;
            border-color: #2563eb;
        }

        .truncate-mobile {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 200px;
        }

        @media (max-width: 640px) {
            .truncate-mobile {
                max-width: 150px;
            }
        }

        .hidden {
            display: none;
        }

        .border-dashed {
            border-style: dashed;
        }

        .selection\:bg-blue-100 ::selection {
            background-color: rgba(219, 234, 254, 0.5);
        }

        .transition {
            transition: all 0.3s ease;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>

<body class="antialiased overflow-x-hidden selection:bg-blue-100">

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal-overlay">
        <div class="modal-content p-4 sm:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg sm:text-xl font-black text-slate-900">User(s) Created Successfully!</h3>
                <button onclick="closeSuccessModal()" class="text-slate-400 hover:text-slate-600 touch-target xs-p-2">
                    <i class="fas fa-times text-lg sm:text-xl"></i>
                </button>
            </div>

            <div id="credentialsDisplay" class="space-y-4 mb-6">
                <!-- Credentials will be populated by JavaScript -->
            </div>

            <div class="flex flex-col xs:flex-row gap-3 pt-6 border-t border-slate-100">
                <button onclick="copyAllCredentials()" class="flex-1 px-6 py-3 bg-blue-600 text-white font-bold rounded-xl hover:bg-blue-700 transition touch-target">
                    <i class="fas fa-copy mr-2"></i>Copy All Credentials
                </button>
                <button onclick="closeSuccessModal()" class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target">
                    <i class="fas fa-check mr-2"></i>Continue
                </button>
            </div>
        </div>
    </div>

    <div class="flex h-screen overflow-hidden">

        <!-- Sidebar -->
        <?php
        $sidebarPath = __DIR__ . '/../filepath/sidebar.php';
        if (file_exists($sidebarPath)) {
            include $sidebarPath;
        }
        ?>

        <main class="flex-1 flex flex-col min-w-0 overflow-hidden">

            <header class="h-16 glass-header border-b border-slate-200 px-4 lg:px-8 flex items-center justify-between shrink-0 z-40">
                <div class="flex items-center gap-3">
                    <a href="view.php?id=<?php echo $schoolId; ?>"
                        class="text-slate-500 p-2 hover:bg-slate-100 rounded-lg transition touch-target">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <div class="flex items-center gap-2">
                        <h1 class="text-sm font-black text-slate-800 uppercase tracking-widest truncate-mobile">
                            Create <?php echo ucfirst($userType); ?>
                        </h1>
                        <span class="px-2 py-0.5 bg-blue-600 text-[10px] text-white font-black rounded uppercase">
                            <?php echo htmlspecialchars($school['name']); ?>
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2 text-xs text-slate-500">
                        <i class="fas fa-hashtag"></i>
                        <span>ID: <?php echo $schoolId; ?></span>
                    </div>
                </div>
            </header>

            <!-- User Type Tabs -->
            <div class="border-b border-slate-200 bg-white">
                <div class="max-w-7xl mx-auto px-4 lg:px-8">
                    <div class="tabs-container">
                        <div class="flex">
                            <a href="?school_id=<?php echo $schoolId; ?>&user_type=student"
                                class="tab-button <?php echo $userType === 'student' ? 'active' : ''; ?>">
                                <i class="fas fa-graduation-cap mr-2"></i>Student
                            </a>
                            <a href="?school_id=<?php echo $schoolId; ?>&user_type=teacher"
                                class="tab-button <?php echo $userType === 'teacher' ? 'active' : ''; ?>">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>Teacher
                            </a>
                            <a href="?school_id=<?php echo $schoolId; ?>&user_type=parent"
                                class="tab-button <?php echo $userType === 'parent' ? 'active' : ''; ?>">
                                <i class="fas fa-user-friends mr-2"></i>Parent
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto p-4 lg:p-8">
                <div class="max-w-4xl mx-auto">

                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error mb-8">
                            <i class="fas fa-exclamation-circle text-xl"></i>
                            <div>
                                <strong>Please fix the following errors:</strong>
                                <ul class="mt-2 space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li class="text-sm">• <?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Success Message -->
                    <?php if ($success): ?>
                        <div class="alert alert-success mb-8">
                            <i class="fas fa-check-circle text-xl"></i>
                            <div>
                                <strong>User(s) created successfully!</strong>
                                <p class="text-sm mt-1"><?php echo count($createdUsers); ?> user(s) have been added to the school database.</p>
                            </div>
                        </div>

                        <script>
                            setTimeout(() => {
                                showSuccessModal(<?php echo json_encode($credentials); ?>);
                            }, 500);
                        </script>
                    <?php endif; ?>

                    <!-- Creation Form -->
                    <form id="createUserForm" method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <!-- Common Fields -->
                        <div class="detail-card p-6">
                            <h3 class="text-lg font-bold text-slate-900 mb-6 pb-4 border-b border-slate-100">
                                <i class="fas fa-user-circle mr-2"></i>Basic Information
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="form-label">Full Name *</label>
                                    <input type="text"
                                        name="name"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                        required
                                        placeholder="John Doe">
                                </div>

                                <?php if ($userType === 'student'): ?>
                                    <div>
                                        <label class="form-label">First Name *</label>
                                        <input type="text"
                                            name="first_name"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                            required
                                            placeholder="John">
                                    </div>

                                    <div>
                                        <label class="form-label">Last Name *</label>
                                        <input type="text"
                                            name="last_name"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                            required
                                            placeholder="Doe">
                                    </div>

                                    <div>
                                        <label class="form-label">Middle Name</label>
                                        <input type="text"
                                            name="middle_name"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                                            placeholder="Alexander">
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label class="form-label">Email Address <?php echo $userType !== 'parent' ? '*' : ''; ?></label>
                                    <input type="email"
                                        name="email"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                        <?php echo $userType !== 'parent' ? 'required' : ''; ?>
                                        placeholder="john.doe@example.com">
                                </div>

                                <div>
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel"
                                        name="phone"
                                        class="form-input"
                                        value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                        placeholder="+234 800 000 0000">
                                </div>

                                <div>
                                    <label class="form-label">Gender</label>
                                    <select name="gender" class="form-select">
                                        <option value="male" <?php echo ($_POST['gender'] ?? 'male') === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($_POST['gender'] ?? 'male') === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo ($_POST['gender'] ?? 'male') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <?php if ($userType === 'student' || $userType === 'parent'): ?>
                                    <div>
                                        <label class="form-label">Date of Birth <?php echo $userType === 'student' ? '*' : ''; ?></label>
                                        <input type="date"
                                            name="date_of_birth"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                            <?php echo $userType === 'student' ? 'required' : ''; ?>>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label class="form-label">Blood Group</label>
                                    <select name="blood_group" class="form-select">
                                        <option value="">Select Blood Group</option>
                                        <option value="A+" <?php echo ($_POST['blood_group'] ?? '') === 'A+' ? 'selected' : ''; ?>>A+</option>
                                        <option value="A-" <?php echo ($_POST['blood_group'] ?? '') === 'A-' ? 'selected' : ''; ?>>A-</option>
                                        <option value="B+" <?php echo ($_POST['blood_group'] ?? '') === 'B+' ? 'selected' : ''; ?>>B+</option>
                                        <option value="B-" <?php echo ($_POST['blood_group'] ?? '') === 'B-' ? 'selected' : ''; ?>>B-</option>
                                        <option value="AB+" <?php echo ($_POST['blood_group'] ?? '') === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                                        <option value="AB-" <?php echo ($_POST['blood_group'] ?? '') === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                                        <option value="O+" <?php echo ($_POST['blood_group'] ?? '') === 'O+' ? 'selected' : ''; ?>>O+</option>
                                        <option value="O-" <?php echo ($_POST['blood_group'] ?? '') === 'O-' ? 'selected' : ''; ?>>O-</option>
                                    </select>
                                </div>

                                <?php if ($userType === 'student'): ?>
                                    <div>
                                        <label class="form-label">Religion</label>
                                        <select name="religion" class="form-select">
                                            <option value="">Select Religion</option>
                                            <option value="Christianity" <?php echo ($_POST['religion'] ?? '') === 'Christianity' ? 'selected' : ''; ?>>Christianity</option>
                                            <option value="Islam" <?php echo ($_POST['religion'] ?? '') === 'Islam' ? 'selected' : ''; ?>>Islam</option>
                                            <option value="Traditional" <?php echo ($_POST['religion'] ?? '') === 'Traditional' ? 'selected' : ''; ?>>Traditional</option>
                                            <option value="Other" <?php echo ($_POST['religion'] ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                <?php endif; ?>

                                <div class="md:col-span-2">
                                    <label class="form-label">Address</label>
                                    <textarea name="address"
                                        class="form-input"
                                        rows="2"
                                        placeholder="123 Main Street, Lagos, Nigeria"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Student Specific Fields -->
                        <?php if ($userType === 'student'): ?>
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-6 pb-4 border-b border-slate-100">
                                    <i class="fas fa-graduation-cap mr-2"></i>Student Details
                                </h3>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="form-label">Admission Number *</label>
                                        <input type="text"
                                            name="admission_number"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['admission_number'] ?? ''); ?>"
                                            required
                                            placeholder="STU20240001">
                                    </div>

                                    <div>
                                        <label class="form-label">Class *</label>
                                        <select name="class_id" class="form-select" required>
                                            <option value="">Select Class</option>
                                            <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id']; ?>"
                                                    <?php echo ($_POST['class_id'] ?? 0) == $class['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($class['name'] . ($class['grade_level'] ? ' - ' . $class['grade_level'] : '')); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label">Admission Date</label>
                                        <input type="date"
                                            name="admission_date"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['admission_date'] ?? date('Y-m-d')); ?>"
                                            required>
                                    </div>
                                </div>
                            </div>

                            <!-- Parent/Guardian Section -->
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-6 pb-4 border-b border-slate-100">
                                    <i class="fas fa-user-friends mr-2"></i>Parent/Guardian Information (Optional)
                                </h3>

                                <div class="mb-6">
                                    <div class="flex items-center mb-4">
                                        <input type="checkbox"
                                            id="link_existing_parent"
                                            name="link_existing_parent"
                                            class="toggle-switch mr-3"
                                            onchange="toggleParentSelection()"
                                            <?php echo isset($_POST['link_existing_parent']) ? 'checked' : ''; ?>>
                                        <label for="link_existing_parent" class="font-medium text-slate-700">
                                            Link to Existing Parent
                                        </label>
                                    </div>

                                    <div id="existingParentSection" class="<?php echo isset($_POST['link_existing_parent']) ? '' : 'hidden'; ?>">
                                        <label class="form-label mb-3">Select Existing Parent</label>
                                        <div class="parent-selector">
                                            <?php if (empty($existingParents)): ?>
                                                <div class="text-center py-4 text-slate-500">
                                                    <i class="fas fa-users text-2xl mb-2"></i>
                                                    <p>No existing parents found</p>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($existingParents as $parent): ?>
                                                    <label class="parent-option block">
                                                        <div class="flex items-center justify-between">
                                                            <div>
                                                                <span class="font-medium text-slate-900"><?php echo htmlspecialchars($parent['name']); ?></span>
                                                                <div class="text-sm text-slate-500">
                                                                    <?php echo htmlspecialchars($parent['email'] ?? 'No email'); ?>
                                                                </div>
                                                            </div>
                                                            <input type="radio"
                                                                name="existing_parent_id"
                                                                value="<?php echo $parent['id']; ?>"
                                                                class="form-checkbox"
                                                                <?php echo ($_POST['existing_parent_id'] ?? 0) == $parent['id'] ? 'checked' : ''; ?>>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div id="newParentSection" class="<?php echo isset($_POST['link_existing_parent']) ? 'hidden' : ''; ?>">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="form-label">Parent Name</label>
                                            <input type="text"
                                                name="parent_name"
                                                class="form-input"
                                                value="<?php echo htmlspecialchars($_POST['parent_name'] ?? ''); ?>"
                                                placeholder="Parent/Guardian full name">
                                        </div>

                                        <div>
                                            <label class="form-label">Relationship</label>
                                            <select name="relationship" class="form-select">
                                                <option value="father" <?php echo ($_POST['relationship'] ?? 'father') === 'father' ? 'selected' : ''; ?>>Father</option>
                                                <option value="mother" <?php echo ($_POST['relationship'] ?? 'father') === 'mother' ? 'selected' : ''; ?>>Mother</option>
                                                <option value="guardian" <?php echo ($_POST['relationship'] ?? 'father') === 'guardian' ? 'selected' : ''; ?>>Guardian</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="form-label">Parent Email</label>
                                            <input type="email"
                                                name="parent_email"
                                                class="form-input"
                                                value="<?php echo htmlspecialchars($_POST['parent_email'] ?? ''); ?>"
                                                placeholder="parent@example.com">
                                        </div>

                                        <div>
                                            <label class="form-label">Parent Phone</label>
                                            <input type="tel"
                                                name="parent_phone"
                                                class="form-input"
                                                value="<?php echo htmlspecialchars($_POST['parent_phone'] ?? ''); ?>"
                                                placeholder="+234 800 000 0000">
                                        </div>

                                        <div class="md:col-span-2">
                                            <label class="form-label">Parent Address</label>
                                            <textarea name="parent_address"
                                                class="form-input"
                                                rows="2"
                                                placeholder="Parent's address (if different from student)"><?php echo htmlspecialchars($_POST['parent_address'] ?? ''); ?></textarea>
                                        </div>

                                        <div class="md:col-span-2">
                                            <label class="form-label mb-3">Parent Permissions</label>
                                            <div class="flex flex-wrap gap-4">
                                                <label class="flex items-center">
                                                    <input type="checkbox"
                                                        name="is_primary"
                                                        class="toggle-switch mr-2"
                                                        <?php echo isset($_POST['is_primary']) ? 'checked' : 'checked'; ?>>
                                                    <span class="text-sm">Primary Contact</span>
                                                </label>
                                                <label class="flex items-center">
                                                    <input type="checkbox"
                                                        name="emergency_contact"
                                                        class="toggle-switch mr-2"
                                                        <?php echo isset($_POST['emergency_contact']) ? 'checked' : 'checked'; ?>>
                                                    <span class="text-sm">Emergency Contact</span>
                                                </label>
                                                <label class="flex items-center">
                                                    <input type="checkbox"
                                                        name="can_pickup"
                                                        class="toggle-switch mr-2"
                                                        <?php echo isset($_POST['can_pickup']) ? 'checked' : 'checked'; ?>>
                                                    <span class="text-sm">Can Pickup Student</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        <?php elseif ($userType === 'teacher'): ?>
                            <!-- Teacher Specific Fields -->
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-6 pb-4 border-b border-slate-100">
                                    <i class="fas fa-chalkboard-teacher mr-2"></i>Teacher Details
                                </h3>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="form-label">Employee ID *</label>
                                        <input type="text"
                                            name="employee_id"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['employee_id'] ?? ''); ?>"
                                            required
                                            placeholder="TCH20240001">
                                    </div>

                                    <div>
                                        <label class="form-label">Joining Date</label>
                                        <input type="date"
                                            name="joining_date"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')); ?>">
                                    </div>

                                    <div>
                                        <label class="form-label">Qualification</label>
                                        <input type="text"
                                            name="qualification"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['qualification'] ?? ''); ?>"
                                            placeholder="B.Sc. Education, M.Ed, etc.">
                                    </div>

                                    <div>
                                        <label class="form-label">Specialization</label>
                                        <input type="text"
                                            name="specialization"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['specialization'] ?? ''); ?>"
                                            placeholder="Mathematics, Physics, etc.">
                                    </div>

                                    <div>
                                        <label class="form-label">Years of Experience</label>
                                        <input type="number"
                                            name="experience_years"
                                            class="form-input"
                                            value="<?php echo htmlspecialchars($_POST['experience_years'] ?? '0'); ?>"
                                            min="0"
                                            max="50"
                                            placeholder="5">
                                    </div>
                                </div>
                            </div>

                        <?php elseif ($userType === 'parent'): ?>
                            <!-- Parent Specific Fields -->
                            <div class="detail-card p-6">
                                <h3 class="text-lg font-bold text-slate-900 mb-6 pb-4 border-b border-slate-100">
                                    <i class="fas fa-user-friends mr-2"></i>Parent Details
                                </h3>

                                <div class="mb-6">
                                    <label class="form-label">Relationship</label>
                                    <select name="relationship" class="form-select">
                                        <option value="father" <?php echo ($_POST['relationship'] ?? 'father') === 'father' ? 'selected' : ''; ?>>Father</option>
                                        <option value="mother" <?php echo ($_POST['relationship'] ?? 'father') === 'mother' ? 'selected' : ''; ?>>Mother</option>
                                        <option value="guardian" <?php echo ($_POST['relationship'] ?? 'father') === 'guardian' ? 'selected' : ''; ?>>Guardian</option>
                                    </select>
                                </div>

                                <div class="mb-6">
                                    <label class="form-label mb-3">Link to Students (Optional)</label>
                                    <?php if (empty($existingStudents)): ?>
                                        <div class="border-2 border-dashed border-slate-200 rounded-xl p-6 text-center">
                                            <i class="fas fa-exclamation-circle text-3xl text-slate-300 mb-3"></i>
                                            <p class="text-slate-500">No active students found to link</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="max-h-60 overflow-y-auto border border-slate-200 rounded-xl p-4">
                                            <?php foreach ($existingStudents as $student): ?>
                                                <label class="flex items-center p-3 hover:bg-slate-50 rounded-lg cursor-pointer">
                                                    <input type="checkbox"
                                                        name="student_ids[]"
                                                        value="<?php echo $student['id']; ?>"
                                                        class="mr-3 h-5 w-5 text-blue-600 rounded">
                                                    <div class="flex-1">
                                                        <div class="font-medium text-slate-900">
                                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                        </div>
                                                        <div class="text-sm text-slate-500">
                                                            <?php echo htmlspecialchars($student['admission_number']); ?>
                                                            <?php if ($student['class_name']): ?>
                                                                • <?php echo htmlspecialchars($student['class_name']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <p class="text-xs text-slate-500 mt-2">You can also link students later from the parent management page</p>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-wrap gap-4">
                                    <label class="flex items-center">
                                        <input type="checkbox"
                                            name="is_primary"
                                            class="toggle-switch mr-2"
                                            checked>
                                        <span class="text-sm">Primary Contact</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox"
                                            name="emergency_contact"
                                            class="toggle-switch mr-2"
                                            checked>
                                        <span class="text-sm">Emergency Contact</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input type="checkbox"
                                            name="can_pickup"
                                            class="toggle-switch mr-2"
                                            checked>
                                        <span class="text-sm">Can Pickup Student</span>
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Submit Section -->
                        <div class="detail-card p-6">
                            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                                <div class="text-sm text-slate-600">
                                    <i class="fas fa-shield-alt mr-1"></i>
                                    Auto-generated secure password will be created
                                </div>
                                <div class="flex gap-3 w-full sm:w-auto">
                                    <a href="view.php?id=<?php echo $schoolId; ?>"
                                        class="flex-1 sm:flex-none px-6 py-3 border border-slate-300 text-slate-700 font-bold rounded-xl hover:bg-slate-50 transition touch-target text-center">
                                        Cancel
                                    </a>
                                    <button type="submit"
                                        class="flex-1 sm:flex-none px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white font-bold rounded-xl hover:from-blue-700 hover:to-blue-800 transition touch-target">
                                        <i class="fas fa-user-plus mr-2"></i>
                                        Create <?php echo ucfirst($userType); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>


    <script>
        // Toggle parent selection
        function toggleParentSelection() {
            const linkExisting = document.getElementById('link_existing_parent').checked;
            const existingSection = document.getElementById('existingParentSection');
            const newSection = document.getElementById('newParentSection');

            if (existingSection && newSection) {
                existingSection.classList.toggle('hidden', !linkExisting);
                newSection.classList.toggle('hidden', linkExisting);

                // Clear new parent fields when linking to existing
                if (linkExisting) {
                    document.querySelectorAll('#newParentSection input, #newParentSection textarea, #newParentSection select').forEach(input => {
                        if (input.type === 'checkbox') {
                            input.checked = true;
                        } else {
                            input.value = '';
                        }
                    });
                }
            }
        }

        // Handle form submission
        const createUserForm = document.getElementById('createUserForm');
        if (createUserForm) {
            createUserForm.addEventListener('submit', function(e) {
                const userType = '<?php echo $userType; ?>';
                let isValid = true;

                // Show loading overlay
                document.getElementById('loadingOverlay').classList.add('active');

                if (userType === 'student') {
                    // Validate student fields
                    const admissionNumber = document.querySelector('input[name="admission_number"]');
                    const classSelect = document.querySelector('select[name="class_id"]');
                    const firstName = document.querySelector('input[name="first_name"]');
                    const lastName = document.querySelector('input[name="last_name"]');
                    const dob = document.querySelector('input[name="date_of_birth"]');
                    const name = document.querySelector('input[name="name"]');

                    if (!admissionNumber || !admissionNumber.value.trim()) {
                        alert('Admission number is required');
                        admissionNumber.focus();
                        isValid = false;
                    } else if (!classSelect || classSelect.value === '') {
                        alert('Please select a class');
                        classSelect.focus();
                        isValid = false;
                    } else if (!firstName || !firstName.value.trim()) {
                        alert('First name is required');
                        firstName.focus();
                        isValid = false;
                    } else if (!lastName || !lastName.value.trim()) {
                        alert('Last name is required');
                        lastName.focus();
                        isValid = false;
                    } else if (!dob || !dob.value) {
                        alert('Date of birth is required');
                        dob.focus();
                        isValid = false;
                    } else if (!name || !name.value.trim()) {
                        alert('Full name is required');
                        name.focus();
                        isValid = false;
                    }
                }

                if (userType === 'teacher') {
                    const employeeId = document.querySelector('input[name="employee_id"]');
                    const email = document.querySelector('input[name="email"]');
                    const name = document.querySelector('input[name="name"]');

                    if (!employeeId || !employeeId.value.trim()) {
                        alert('Employee ID is required');
                        if (employeeId) employeeId.focus();
                        isValid = false;
                    } else if (!email || !email.value.trim()) {
                        alert('Email is required for teachers');
                        if (email) email.focus();
                        isValid = false;
                    } else if (!name || !name.value.trim()) {
                        alert('Name is required');
                        if (name) name.focus();
                        isValid = false;
                    }
                }

                if (userType === 'parent') {
                    const name = document.querySelector('input[name="name"]');
                    const email = document.querySelector('input[name="email"]');
                    const phone = document.querySelector('input[name="phone"]');

                    if (!name || !name.value.trim()) {
                        alert('Name is required');
                        if (name) name.focus();
                        isValid = false;
                    } else if ((!email || !email.value.trim()) && (!phone || !phone.value.trim())) {
                        alert('Please provide either email or phone number');
                        if (email) email.focus();
                        isValid = false;
                    }
                }

                if (!isValid) {
                    e.preventDefault();
                    document.getElementById('loadingOverlay').classList.remove('active');
                    return;
                }
            });
        }

        // Success modal functions
        function showSuccessModal(credentials) {
            const modal = document.getElementById('successModal');
            const display = document.getElementById('credentialsDisplay');

            if (!modal || !display || !credentials) return;

            let html = '';
            credentials.forEach(cred => {
                html += `
                    <div class="bg-gradient-to-r from-blue-50 to-slate-50 rounded-xl p-4 border border-blue-100">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg ${cred.type === 'student' ? 'bg-blue-100' : cred.type === 'teacher' ? 'bg-emerald-100' : 'bg-amber-100'} flex items-center justify-center">
                                <i class="fas ${cred.type === 'student' ? 'fa-graduation-cap' : cred.type === 'teacher' ? 'fa-chalkboard-teacher' : 'fa-user-friends'} ${cred.type === 'student' ? 'text-blue-600' : cred.type === 'teacher' ? 'text-emerald-600' : 'text-amber-600'}"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-slate-900">${cred.name}</h4>
                                <p class="text-xs text-slate-500 uppercase">${cred.type}</p>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-600">Password:</span>
                                <span class="font-mono font-bold">${cred.password}</span>
                            </div>
                            ${cred.email ? `
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-600">Email:</span>
                                <span class="font-medium">${cred.email}</span>
                            </div>
                            ` : ''}
                            ${cred.phone ? `
                            <div class="flex justify-between items-center">
                                <span class="text-sm text-slate-600">Phone:</span>
                                <span class="font-medium">${cred.phone}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            });

            display.innerHTML = html;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSuccessModal() {
            const modal = document.getElementById('successModal');
            const loading = document.getElementById('loadingOverlay');

            if (modal) modal.classList.remove('active');
            if (loading) loading.classList.remove('active');

            document.body.style.overflow = 'auto';

            // Redirect back to school details
            setTimeout(() => {
                window.location.href = 'view.php?id=<?php echo $schoolId; ?>';
            }, 300);
        }

        function copyAllCredentials() {
            const credentials = <?php echo json_encode($credentials ?? []); ?>;
            let text = '';

            if (!credentials || credentials.length === 0) return;

            credentials.forEach(cred => {
                text += `${cred.type.toUpperCase()}: ${cred.name}\n`;
                text += `Password: ${cred.password}\n`;
                if (cred.email) text += `Email: ${cred.email}\n`;
                if (cred.phone) text += `Phone: ${cred.phone}\n`;
                text += '\n';
            });

            navigator.clipboard.writeText(text).then(() => {
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
                button.classList.add('bg-emerald-600');

                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.classList.remove('bg-emerald-600');
                }, 2000);
            });
        }

        // Handle page unload
        window.addEventListener('beforeunload', function(e) {
            const loading = document.getElementById('loadingOverlay');
            if (loading) loading.classList.remove('active');
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize parent selection toggle if it exists
            const toggleSwitch = document.getElementById('link_existing_parent');
            if (toggleSwitch) {
                toggleParentSelection();
            }
        });
    </script>
</body>

</html>