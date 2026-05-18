<?php
/**
 * Teacher Manager Class
 * Handles all teacher-related operations including CRUD, assignments,
 * and integration with payroll_employees table.
 *
 * @package AcademixSuite
 * @version 2.2 (adapted to school_6 schema)
 */

class TeacherManager {
    private $db;
    private $schoolId;
    private $userId;
    private $userType;
    private $schoolData;

    /**
     * Constructor
     * @param PDO $db Database connection
     * @param int $schoolId School ID
     * @param int $userId Current user ID
     * @param string $userType Current user type
     * @param array $schoolData School information
     */
    public function __construct($db, $schoolId, $userId, $userType, $schoolData) {
        $this->db = $db;
        $this->schoolId = $schoolId;
        $this->userId = $userId;
        $this->userType = $userType;
        $this->schoolData = $schoolData;
        error_log("TeacherManager initialized for school ID: " . $schoolId);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Generate a unique employee ID
     * Format: TCH-YYYY-XXXX (e.g., TCH-2026-0012)
     * @return string
     */
    public function generateEmployeeId() {
        $year = date('Y');
        $stmt = $this->db->prepare("
            SELECT employee_id FROM teachers
            WHERE school_id = ? AND employee_id LIKE ?
            ORDER BY employee_id DESC LIMIT 1
        ");
        $prefix = "TCH-{$year}-";
        $stmt->execute([$this->schoolId, $prefix . '%']);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($last) {
            $num = (int)substr($last['employee_id'], -4);
            $newNum = str_pad($num + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNum = '0001';
        }
        return $prefix . $newNum;
    }

    /**
     * Generate a random password (8-12 characters)
     * @return string
     */
    private function generateRandomPassword() {
        $length = rand(8, 12);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&*?';
        return substr(str_shuffle($chars), 0, $length);
    }

    /**
     * Validate teacher data before insertion
     * @param array $data
     * @throws Exception on validation failure
     */
    private function validateTeacherData($data) {
        $required = ['name', 'email', 'phone', 'date_of_birth'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }
        if (empty($data['assigned_subjects'])) {
            throw new Exception("At least one subject must be assigned.");
        }
    }

    /**
     * Get subject name by its ID
     * @param int $subjectId
     * @return string|null
     */
    private function getSubjectNameById($subjectId) {
        $stmt = $this->db->prepare("SELECT name FROM subjects WHERE id = ? AND school_id = ?");
        $stmt->execute([$subjectId, $this->schoolId]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        return $subject ? $subject['name'] : null;
    }

    // ==================== CORE METHODS ====================

    /**
     * Add new teacher and create payroll employee record
     * @param array $data Teacher data
     * @return array [success, message, teacher_id]
     */
    public function addTeacher($data) {
        try {
            $this->db->beginTransaction();
            error_log("=== ADD TEACHER TRANSACTION STARTED ===");

            // Validate teacher data
            $this->validateTeacherData($data);

            // Generate employee ID if not provided
            if (empty($data['employee_id'])) {
                $data['employee_id'] = $this->generateEmployeeId();
                error_log("Generated employee ID: " . $data['employee_id']);
            } else {
                $checkEmpStmt = $this->db->prepare("
                    SELECT id FROM teachers
                    WHERE school_id = ? AND employee_id = ?
                ");
                $checkEmpStmt->execute([$this->schoolId, $data['employee_id']]);
                if ($checkEmpStmt->fetch()) {
                    throw new Exception("Employee ID already exists");
                }
            }

            // Check if email already exists
            $checkEmailStmt = $this->db->prepare("
                SELECT id FROM users
                WHERE school_id = ? AND email = ?
            ");
            $checkEmailStmt->execute([$this->schoolId, $data['email']]);
            if ($checkEmailStmt->fetch()) {
                throw new Exception("Email already exists in the system");
            }

            // Check if phone already exists
            if (!empty($data['phone'])) {
                $checkPhoneStmt = $this->db->prepare("
                    SELECT id FROM users
                    WHERE school_id = ? AND phone = ?
                ");
                $checkPhoneStmt->execute([$this->schoolId, $data['phone']]);
                if ($checkPhoneStmt->fetch()) {
                    throw new Exception("Phone number already exists in the system");
                }
            }

            // Generate username from email
            $username = explode('@', $data['email'])[0];
            $password = !empty($data['password']) ? $data['password'] : $this->generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert into users table
            $userStmt = $this->db->prepare("
                INSERT INTO users (
                    school_id, name, email, phone, username, password, user_type,
                    gender, date_of_birth, address, profile_photo, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $profilePhoto = $data['profile_photo'] ?? null;
            $userStmt->execute([
                $this->schoolId,
                $data['name'],
                $data['email'],
                $data['phone'],
                $username,
                $hashedPassword,
                $data['gender'] ?? null,
                $data['date_of_birth'],
                $data['current_address'] ?? null,
                $profilePhoto
            ]);
            $teacherUserId = $this->db->lastInsertId();
            error_log("Teacher user created with ID: " . $teacherUserId);

            // Assign teacher role (role_id = 3)
            $roleStmt = $this->db->prepare("
                INSERT INTO user_roles (user_id, role_id, created_at)
                VALUES (?, 3, NOW())
            ");
            $roleStmt->execute([$teacherUserId]);

            // Build specialization string from subjects
            $specialization = '';
            if (!empty($data['assigned_subjects'])) {
                $subjectNames = [];
                foreach ($data['assigned_subjects'] as $subject) {
                    if (is_array($subject) && isset($subject['name'])) {
                        $subjectNames[] = $subject['name'];
                    } elseif (is_numeric($subject)) {
                        $subjectNames[] = $this->getSubjectNameById($subject);
                    }
                }
                $specialization = implode(', ', array_filter($subjectNames));
            }

            // Insert into teachers table
            $teacherStmt = $this->db->prepare("
                INSERT INTO teachers (
                    school_id, user_id, employee_id, qualification, specialization,
                    experience_years, joining_date, salary_grade, bank_name, bank_account,
                    ifsc_code, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $teacherStmt->execute([
                $this->schoolId,
                $teacherUserId,
                $data['employee_id'],
                $data['qualification'] ?? null,
                $specialization,
                $data['experience_years'] ?? null,
                $data['joining_date'] ?? null,
                $data['salary_grade'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null
            ]);
            $teacherRecordId = $this->db->lastInsertId();
            error_log("Teacher record created with ID: " . $teacherRecordId);

            // Insert into payroll_employees if table exists
            $this->insertPayrollEmployee($teacherUserId, $data);

            // Assign classes as class teacher (OPTIONAL)
            if (!empty($data['assigned_classes'])) {
                foreach ($data['assigned_classes'] as $classId) {
                    $updateClassStmt = $this->db->prepare("
                        UPDATE classes
                        SET class_teacher_id = ?
                        WHERE id = ? AND school_id = ?
                    ");
                    $updateClassStmt->execute([$teacherUserId, $classId, $this->schoolId]);
                    error_log("Teacher assigned as class teacher for class ID: " . $classId);
                }
            }

            // Assign subjects to teacher (REQUIRED)
            if (!empty($data['assigned_subjects'])) {
                $subjectCount = 0;
                foreach ($data['assigned_subjects'] as $subject) {
                    $subjectId = is_array($subject) ? ($subject['id'] ?? null) : $subject;
                    if (!$subjectId) continue;

                    $checkSubjectStmt = $this->db->prepare("
                        SELECT cs.id, cs.class_id FROM class_subjects cs
                        WHERE cs.subject_id = ?
                        LIMIT 1
                    ");
                    $checkSubjectStmt->execute([$subjectId]);
                    $classSubject = $checkSubjectStmt->fetch(PDO::FETCH_ASSOC);

                    if ($classSubject) {
                        // Update existing class_subject with teacher_id
                        $updateSubjectStmt = $this->db->prepare("
                            UPDATE class_subjects
                            SET teacher_id = ?
                            WHERE id = ?
                        ");
                        $updateSubjectStmt->execute([$teacherUserId, $classSubject['id']]);
                        $subjectCount++;
                    } else {
                        // If no class_subject exists, create one with a default class
                        $defaultClassStmt = $this->db->prepare("
                            SELECT id FROM classes
                            WHERE school_id = ? AND is_active = 1
                            LIMIT 1
                        ");
                        $defaultClassStmt->execute([$this->schoolId]);
                        $defaultClass = $defaultClassStmt->fetch(PDO::FETCH_ASSOC);

                        if ($defaultClass) {
                            $insertSubjectStmt = $this->db->prepare("
                                INSERT INTO class_subjects (class_id, subject_id, teacher_id, created_at)
                                VALUES (?, ?, ?, NOW())
                            ");
                            $insertSubjectStmt->execute([$defaultClass['id'], $subjectId, $teacherUserId]);
                            $subjectCount++;
                            error_log("Created new class-subject assignment for subject ID: " . $subjectId);
                        } else {
                            error_log("No default class found for subject ID: " . $subjectId);
                        }
                    }
                }
                error_log("Teacher assigned to {$subjectCount} subjects");
            } else {
                throw new Exception("At least one subject must be assigned to the teacher");
            }

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'create', 'teacher', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $teacherRecordId,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            error_log("=== TEACHER ADDED SUCCESSFULLY === ID: " . $teacherRecordId);

            // Store password in session for email notification
            if (!isset($_SESSION['temp_passwords'])) {
                $_SESSION['temp_passwords'] = [];
            }
            $_SESSION['temp_passwords'][$teacherUserId] = $password;

            return [true, "Teacher added successfully! Employee ID: " . $data['employee_id'], $teacherRecordId];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                error_log("Transaction rolled back");
            }
            error_log("Error adding teacher: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [false, "Error adding teacher: " . $e->getMessage(), null];
        }
    }

    /**
     * Insert a record into payroll_employees for a new teacher
     * @param int $userId
     * @param array $data
     */
    private function insertPayrollEmployee($userId, $data) {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'payroll_employees'");
            if ($tableCheck->rowCount() == 0) {
                error_log("payroll_employees table does not exist – skipping payroll insertion");
                return;
            }

            $designation = $data['designation'] ?? 'Teacher';
            $department = $data['department'] ?? null;

            $stmt = $this->db->prepare("
                INSERT INTO payroll_employees (
                    school_id, user_id, employee_number, department, designation,
                    joining_date, bank_name, bank_account, ifsc_code, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $this->schoolId,
                $userId,
                $data['employee_id'],
                $department,
                $designation,
                $data['joining_date'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null
            ]);
            error_log("Payroll employee record created for user ID: " . $userId);
        } catch (Exception $e) {
            // Log but do not fail the whole transaction – payroll is optional
            error_log("Failed to insert into payroll_employees: " . $e->getMessage());
        }
    }

    /**
     * Update teacher information and sync payroll_employees
     * @param int $teacherId
     * @param array $data
     * @return array [success, message]
     */
    public function updateTeacher($teacherId, $data) {
        try {
            $this->db->beginTransaction();

            // Get teacher's user_id
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers
                WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }
            $userId = $teacher['user_id'];

            // Update users table
            $userStmt = $this->db->prepare("
                UPDATE users SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    gender = ?,
                    date_of_birth = ?,
                    address = ?,
                    profile_photo = COALESCE(?, profile_photo),
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");
            $userStmt->execute([
                $data['name'],
                $data['email'],
                $data['phone'],
                $data['gender'] ?? null,
                $data['date_of_birth'],
                $data['current_address'] ?? null,
                $data['profile_photo'] ?? null,
                $userId,
                $this->schoolId
            ]);

            // Build specialization string
            $specialization = '';
            if (!empty($data['assigned_subjects'])) {
                $subjectNames = [];
                foreach ($data['assigned_subjects'] as $subject) {
                    if (is_array($subject) && isset($subject['name'])) {
                        $subjectNames[] = $subject['name'];
                    } elseif (is_numeric($subject)) {
                        $subjectNames[] = $this->getSubjectNameById($subject);
                    }
                }
                $specialization = implode(', ', array_filter($subjectNames));
            }

            // Update teachers table
            $teacherUpdateStmt = $this->db->prepare("
                UPDATE teachers SET
                    qualification = ?,
                    specialization = ?,
                    experience_years = ?,
                    joining_date = ?,
                    bank_name = ?,
                    bank_account = ?,
                    ifsc_code = ?,
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");
            $teacherUpdateStmt->execute([
                $data['qualification'] ?? null,
                $specialization,
                $data['experience_years'] ?? null,
                $data['joining_date'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null,
                $teacherId,
                $this->schoolId
            ]);

            // Update payroll_employees if table exists
            $this->updatePayrollEmployee($userId, $data);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'update', 'teacher', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $teacherId,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, "Teacher updated successfully"];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error updating teacher: " . $e->getMessage());
            return [false, "Error updating teacher: " . $e->getMessage()];
        }
    }

    /**
     * Update payroll_employees record for a teacher
     * @param int $userId
     * @param array $data
     */
    private function updatePayrollEmployee($userId, $data) {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'payroll_employees'");
            if ($tableCheck->rowCount() == 0) return;

            $designation = $data['designation'] ?? 'Teacher';
            $department = $data['department'] ?? null;

            $stmt = $this->db->prepare("
                UPDATE payroll_employees SET
                    employee_number = ?,
                    department = ?,
                    designation = ?,
                    joining_date = ?,
                    bank_name = ?,
                    bank_account = ?,
                    ifsc_code = ?,
                    updated_at = NOW()
                WHERE user_id = ? AND school_id = ?
            ");
            $stmt->execute([
                $data['employee_id'] ?? '',
                $department,
                $designation,
                $data['joining_date'] ?? null,
                $data['bank_name'] ?? null,
                $data['bank_account'] ?? null,
                $data['ifsc_code'] ?? null,
                $userId,
                $this->schoolId
            ]);
            error_log("Payroll employee record updated for user ID: " . $userId);
        } catch (Exception $e) {
            error_log("Failed to update payroll_employees: " . $e->getMessage());
        }
    }

    /**
     * Suspend teacher (deactivate) and sync payroll
     * @param int $teacherId
     * @param string $reason
     * @return array [success, message]
     */
    public function suspendTeacher($teacherId, $reason = '') {
        try {
            $this->db->beginTransaction();

            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers
                WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            // Update teachers table
            $updateTeacherStmt = $this->db->prepare("
                UPDATE teachers SET is_active = 0 WHERE id = ? AND school_id = ?
            ");
            $updateTeacherStmt->execute([$teacherId, $this->schoolId]);

            // Update users table
            $updateUserStmt = $this->db->prepare("
                UPDATE users SET is_active = 0 WHERE id = ? AND school_id = ?
            ");
            $updateUserStmt->execute([$teacher['user_id'], $this->schoolId]);

            // Update payroll_employees if exists
            $this->updatePayrollEmployeeStatus($teacher['user_id'], 0);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'suspend', 'teacher', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $teacherId,
                json_encode(['reason' => $reason]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, "Teacher suspended successfully"];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error suspending teacher: " . $e->getMessage());
            return [false, "Error suspending teacher: " . $e->getMessage()];
        }
    }

    /**
     * Activate teacher and sync payroll
     * @param int $teacherId
     * @return array [success, message]
     */
    public function activateTeacher($teacherId) {
        try {
            $this->db->beginTransaction();

            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers
                WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            // Update teachers table
            $updateTeacherStmt = $this->db->prepare("
                UPDATE teachers SET is_active = 1 WHERE id = ? AND school_id = ?
            ");
            $updateTeacherStmt->execute([$teacherId, $this->schoolId]);

            // Update users table
            $updateUserStmt = $this->db->prepare("
                UPDATE users SET is_active = 1 WHERE id = ? AND school_id = ?
            ");
            $updateUserStmt->execute([$teacher['user_id'], $this->schoolId]);

            // Update payroll_employees if exists
            $this->updatePayrollEmployeeStatus($teacher['user_id'], 1);

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, 'activate', 'teacher', ?, ?, ?, ?, NOW())
            ");
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $teacherId,
                json_encode(['status' => 'active']),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, "Teacher activated successfully"];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error activating teacher: " . $e->getMessage());
            return [false, "Error activating teacher: " . $e->getMessage()];
        }
    }

    /**
     * Update is_active status in payroll_employees
     * @param int $userId
     * @param int $status 1 active, 0 inactive
     */
    private function updatePayrollEmployeeStatus($userId, $status) {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'payroll_employees'");
            if ($tableCheck->rowCount() == 0) return;

            $stmt = $this->db->prepare("
                UPDATE payroll_employees SET is_active = ? WHERE user_id = ? AND school_id = ?
            ");
            $stmt->execute([$status, $userId, $this->schoolId]);
            error_log("Payroll employee status updated for user ID: " . $userId);
        } catch (Exception $e) {
            error_log("Failed to update payroll_employee status: " . $e->getMessage());
        }
    }

    /**
     * Delete teacher and optionally delete payroll record
     * @param int $teacherId
     * @param bool $permanent
     * @return array [success, message]
     */
    public function deleteTeacher($teacherId, $permanent = false) {
        try {
            $this->db->beginTransaction();

            // Get teacher details
            $teacherStmt = $this->db->prepare("
                SELECT t.*, u.id as user_id
                FROM teachers t
                JOIN users u ON t.user_id = u.id
                WHERE t.id = ? AND t.school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            if ($permanent) {
                // Permanent delete - remove all records
                $this->db->prepare("
                    UPDATE class_subjects SET teacher_id = NULL WHERE teacher_id = ?
                ")->execute([$teacher['user_id']]);

                $this->db->prepare("
                    UPDATE classes SET class_teacher_id = NULL
                    WHERE class_teacher_id = ? AND school_id = ?
                ")->execute([$teacher['user_id'], $this->schoolId]);

                $this->deletePayrollEmployee($teacher['user_id']);

                $this->db->prepare("
                    DELETE FROM teachers WHERE id = ? AND school_id = ?
                ")->execute([$teacherId, $this->schoolId]);

                $this->db->prepare("
                    DELETE FROM users WHERE id = ? AND school_id = ?
                ")->execute([$teacher['user_id'], $this->schoolId]);

                $message = "Teacher permanently deleted";
            } else {
                // Soft delete - just mark as inactive
                $this->db->prepare("
                    UPDATE teachers SET is_active = 0 WHERE id = ? AND school_id = ?
                ")->execute([$teacherId, $this->schoolId]);

                $this->db->prepare("
                    UPDATE users SET is_active = 0 WHERE id = ? AND school_id = ?
                ")->execute([$teacher['user_id'], $this->schoolId]);

                $this->updatePayrollEmployeeStatus($teacher['user_id'], 0);
                $message = "Teacher moved to inactive";
            }

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type,
                    entity_id, new_values, ip_address, user_agent, created_at
                ) VALUES (?, ?, ?, ?, 'teacher', ?, ?, ?, ?, NOW())
            ");
            $action = $permanent ? 'delete_permanent' : 'delete_soft';
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $action,
                $teacherId,
                json_encode(['permanent' => $permanent]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $this->db->commit();
            return [true, $message];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error deleting teacher: " . $e->getMessage());
            return [false, "Error deleting teacher: " . $e->getMessage()];
        }
    }

    /**
     * Delete payroll_employees record
     * @param int $userId
     */
    private function deletePayrollEmployee($userId) {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'payroll_employees'");
            if ($tableCheck->rowCount() == 0) return;

            $stmt = $this->db->prepare("
                DELETE FROM payroll_employees WHERE user_id = ? AND school_id = ?
            ");
            $stmt->execute([$userId, $this->schoolId]);
            error_log("Payroll employee record deleted for user ID: " . $userId);
        } catch (Exception $e) {
            error_log("Failed to delete payroll_employee: " . $e->getMessage());
        }
    }

    /**
     * Get all teachers with optional filters
     * @param array $filters
     * @return array
     */
    public function getTeachers($filters = []) {
        try {
            $sql = "
                SELECT
                    t.*,
                    u.name,
                    u.email,
                    u.phone,
                    u.gender,
                    u.profile_photo,
                    u.is_active,
                    GROUP_CONCAT(DISTINCT s.name) as subject_names,
                    GROUP_CONCAT(DISTINCT cl.name) as class_names
                FROM teachers t
                JOIN users u ON t.user_id = u.id AND u.school_id = t.school_id
                LEFT JOIN class_subjects cs ON cs.teacher_id = u.id
                LEFT JOIN subjects s ON cs.subject_id = s.id AND s.school_id = t.school_id
                LEFT JOIN classes cl ON cl.class_teacher_id = u.id AND cl.school_id = t.school_id
                WHERE t.school_id = ?
            ";

            $params = [$this->schoolId];

            if (!empty($filters['status'])) {
                if ($filters['status'] === 'active') {
                    $sql .= " AND t.is_active = 1 AND u.is_active = 1";
                } elseif ($filters['status'] === 'inactive') {
                    $sql .= " AND (t.is_active = 0 OR u.is_active = 0)";
                }
            }

            if (!empty($filters['search'])) {
                $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR t.employee_id LIKE ?)";
                $searchTerm = "%{$filters['search']}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($filters['subject_id'])) {
                $sql .= " AND FIND_IN_SET(?, s.id)";
                $params[] = $filters['subject_id'];
            }

            if (!empty($filters['class_id'])) {
                $sql .= " AND FIND_IN_SET(?, cl.id)";
                $params[] = $filters['class_id'];
            }

            $sql .= " GROUP BY t.id ORDER BY u.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Retrieved " . count($teachers) . " teachers");
            return $teachers;

        } catch (Exception $e) {
            error_log("Error getting teachers: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get teacher's assigned classes
     * @param int $teacherId
     * @return array
     */
    public function getTeacherClasses($teacherId) {
        try {
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT c.*, ay.name as academic_year_name
                FROM classes c
                LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
                WHERE c.class_teacher_id = ? AND c.school_id = ?
                ORDER BY c.name
            ");
            $stmt->execute([$teacher['user_id'], $this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting teacher classes: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get teacher's assigned subjects
     * @param int $teacherId
     * @return array
     */
    public function getTeacherSubjects($teacherId) {
        try {
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                return [];
            }

            $stmt = $this->db->prepare("
                SELECT s.*, c.name as class_name, c.id as class_id
                FROM subjects s
                JOIN class_subjects cs ON s.id = cs.subject_id
                LEFT JOIN classes c ON cs.class_id = c.id
                WHERE cs.teacher_id = ? AND s.school_id = ?
                GROUP BY s.id
                ORDER BY s.name
            ");
            $stmt->execute([$teacher['user_id'], $this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting teacher subjects: " . $e->getMessage());
            return [];
        }
    }
    /**
 * Get a single teacher by teacher ID (primary key of teachers table)
 * @param int $teacherId
 * * @return array|false Teacher details with user info, or false if not found
 */
public function getTeacher($teacherId) {
    try {
        $sql = "
            SELECT
                t.*,
                u.id as user_id,
                u.name,
                u.email,
                u.phone,
                u.gender,
                u.date_of_birth,
                u.profile_photo,
                u.address as current_address,
                u.is_active as user_is_active,
                t.is_active as teacher_is_active
            FROM teachers t
            JOIN users u ON t.user_id = u.id AND u.school_id = t.school_id
            WHERE t.id = ? AND t.school_id = ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$teacherId, $this->schoolId]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$teacher) {
            return false;
        }

        // Get assigned subjects
        $teacher['assigned_subjects'] = $this->getTeacherSubjects($teacherId);
        // Get assigned classes (where this teacher is class teacher)
        $teacher['assigned_classes'] = $this->getTeacherClasses($teacherId);

        return $teacher;
    } catch (Exception $e) {
        error_log("Error getting teacher by ID: " . $e->getMessage());
        return false;
    }
}

    /**
     * Assign teacher to class as class teacher
     * @param int $teacherId
     * @param int $classId
     * @return array [success, message]
     */
    public function assignClassTeacher($teacherId, $classId) {
        try {
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            $stmt = $this->db->prepare("
                UPDATE classes
                SET class_teacher_id = ?
                WHERE id = ? AND school_id = ?
            ");
            $stmt->execute([$teacher['user_id'], $classId, $this->schoolId]);
            return [true, "Teacher assigned as class teacher successfully"];
        } catch (Exception $e) {
            error_log("Error assigning class teacher: " . $e->getMessage());
            return [false, "Error assigning class teacher: " . $e->getMessage()];
        }
    }

    /**
     * Assign teacher to subject
     * @param int $teacherId
     * @param int $subjectId
     * @param int|null $classId
     * @return array [success, message]
     */
    public function assignSubject($teacherId, $subjectId, $classId = null) {
        try {
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            if ($classId) {
                $checkStmt = $this->db->prepare("
                    SELECT id FROM class_subjects
                    WHERE class_id = ? AND subject_id = ?
                ");
                $checkStmt->execute([$classId, $subjectId]);
                if ($checkStmt->fetch()) {
                    $stmt = $this->db->prepare("
                        UPDATE class_subjects
                        SET teacher_id = ?
                        WHERE class_id = ? AND subject_id = ?
                    ");
                    $stmt->execute([$teacher['user_id'], $classId, $subjectId]);
                } else {
                    $stmt = $this->db->prepare("
                        INSERT INTO class_subjects (class_id, subject_id, teacher_id, created_at)
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([$classId, $subjectId, $teacher['user_id']]);
                }
            } else {
                $stmt = $this->db->prepare("
                    UPDATE class_subjects
                    SET teacher_id = ?
                    WHERE subject_id = ? AND teacher_id IS NULL
                    LIMIT 1
                ");
                $stmt->execute([$teacher['user_id'], $subjectId]);
            }
            return [true, "Subject assigned successfully"];
        } catch (Exception $e) {
            error_log("Error assigning subject: " . $e->getMessage());
            return [false, "Error assigning subject: " . $e->getMessage()];
        }
    }

    /**
     * Remove teacher from subject
     * @param int $teacherId
     * @param int $subjectId
     * @param int|null $classId
     * @return array [success, message]
     */
    public function removeSubject($teacherId, $subjectId, $classId = null) {
        try {
            $teacherStmt = $this->db->prepare("
                SELECT user_id FROM teachers WHERE id = ? AND school_id = ?
            ");
            $teacherStmt->execute([$teacherId, $this->schoolId]);
            $teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
            if (!$teacher) {
                throw new Exception("Teacher not found");
            }

            $sql = "UPDATE class_subjects SET teacher_id = NULL WHERE subject_id = ? AND teacher_id = ?";
            $params = [$subjectId, $teacher['user_id']];
            if ($classId) {
                $sql .= " AND class_id = ?";
                $params[] = $classId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return [true, "Subject unassigned successfully"];
        } catch (Exception $e) {
            error_log("Error removing subject: " . $e->getMessage());
            return [false, "Error removing subject: " . $e->getMessage()];
        }
    }

    /**
     * Get teacher statistics
     * @return array
     */
    public function getTeacherStats() {
        try {
            $stats = [];

            // Total teachers
            $totalStmt = $this->db->prepare("SELECT COUNT(*) as total FROM teachers WHERE school_id = ?");
            $totalStmt->execute([$this->schoolId]);
            $stats['total'] = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Active teachers
            $activeStmt = $this->db->prepare("
                SELECT COUNT(*) as active FROM teachers t
                JOIN users u ON t.user_id = u.id
                WHERE t.school_id = ? AND u.is_active = 1 AND t.is_active = 1
            ");
            $activeStmt->execute([$this->schoolId]);
            $stats['active'] = $activeStmt->fetch(PDO::FETCH_ASSOC)['active'];

            $stats['inactive'] = $stats['total'] - $stats['active'];

            // Teachers by gender
            $genderStmt = $this->db->prepare("
                SELECT u.gender, COUNT(*) as count
                FROM teachers t
                JOIN users u ON t.user_id = u.id
                WHERE t.school_id = ? AND u.gender IS NOT NULL
                GROUP BY u.gender
            ");
            $genderStmt->execute([$this->schoolId]);
            $stats['by_gender'] = $genderStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Teachers by subject
            $subjectStmt = $this->db->prepare("
                SELECT s.name, COUNT(DISTINCT cs.teacher_id) as count
                FROM subjects s
                LEFT JOIN class_subjects cs ON s.id = cs.subject_id
                WHERE s.school_id = ?
                GROUP BY s.id
                ORDER BY count DESC
                LIMIT 5
            ");
            $subjectStmt->execute([$this->schoolId]);
            $stats['by_subject'] = $subjectStmt->fetchAll(PDO::FETCH_ASSOC);

            // New this month
            $monthStmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM teachers
                WHERE school_id = ?
                AND MONTH(created_at) = MONTH(CURRENT_DATE())
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ");
            $monthStmt->execute([$this->schoolId]);
            $stats['new_this_month'] = $monthStmt->fetch(PDO::FETCH_ASSOC)['count'];

            return $stats;
        } catch (Exception $e) {
            error_log("Error getting teacher stats: " . $e->getMessage());
            return [];
        }
    }
}