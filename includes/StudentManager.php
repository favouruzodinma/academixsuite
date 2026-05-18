<?php
/**
 * Student Manager Class
 * Handles all student-related operations
 * @version 2.2 (fixed duplicate email and phone handling)
 */

class StudentManager {
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
        
        error_log("StudentManager initialized for school ID: " . $schoolId);
    }

    /**
     * Get default campus ID for the school
     * @return int|null
     */
    private function getDefaultCampusId() {
        try {
            error_log("Getting default campus ID for school: " . $this->schoolId);
            
            // Check if campuses table exists
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'campuses'");
            if ($tableCheck->rowCount() === 0) {
                error_log("Campuses table does not exist, returning null");
                return null;
            }
            
            // Get default campus for the school
            $stmt = $this->db->prepare("
                SELECT id FROM campuses 
                WHERE school_id = ? AND is_default = 1 
                LIMIT 1
            ");
            $stmt->execute([$this->schoolId]);
            $campus = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($campus) {
                error_log("Found default campus ID: " . $campus['id']);
                return $campus['id'];
            } else {
                error_log("No default campus found, returning null");
                return null;
            }
        } catch (Exception $e) {
            error_log("Error getting default campus: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate professional email from name with duplicate handling
     * @param string $firstName
     * @param string $lastName
     * @param string $type student|parent|teacher
     * @return string
     */
    private function generateProfessionalEmail($firstName, $lastName, $type = 'student') {
        error_log("Generating professional email for: $firstName $lastName ($type)");
        
        $firstName = strtolower(trim($firstName));
        $lastName = strtolower(trim($lastName));
        
        // Remove special characters and spaces
        $firstName = preg_replace('/[^a-z0-9]/', '', $firstName);
        $lastName = preg_replace('/[^a-z0-9]/', '', $lastName);
        
        // Get school domain from settings or use default
        $domain = $this->schoolData['email_domain'] ?? $this->schoolData['slug'] ?? 'school';
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);
        
        // Generate base email based on type
        switch ($type) {
            case 'student':
                $baseEmail = "{$firstName}.{$lastName}@student.{$domain}";
                break;
            case 'parent':
                $baseEmail = "parent.{$firstName}.{$lastName}@{$domain}";
                break;
            case 'teacher':
                $baseEmail = "{$firstName}.{$lastName}@{$domain}";
                break;
            default:
                $baseEmail = "{$firstName}.{$lastName}@{$domain}";
        }
        
        error_log("Base email generated: " . $baseEmail);
        
        // Check if email exists and add suffix if needed
        $email = $baseEmail;
        $counter = 1;
        
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
            while ($counter < 100) {
                $stmt->execute([$this->schoolId, $email]);
                if (!$stmt->fetch()) {
                    error_log("Email is unique: " . $email);
                    break;
                }
                // Add number suffix before @
                $email = preg_replace('/@/', $counter . '@', $baseEmail, 1);
                error_log("Email already exists, trying: " . $email);
                $counter++;
            }
            
            if ($counter >= 100) {
                error_log("WARNING: Could not generate unique email after 100 attempts, using timestamp");
                $email = preg_replace('/@/', time() . '@', $baseEmail, 1);
            }
        } catch (Exception $e) {
            error_log("Error checking email uniqueness: " . $e->getMessage());
        }
        
        return $email;
    }

    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    private function generateRandomPassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = substr(str_shuffle($chars), 0, $length);
        error_log("Generated random password of length: " . $length);
        return $password;
    }

    /**
     * Generate admission number
     * @param int $academicYearId
     * @return string
     */
    private function generateAdmissionNumber($academicYearId) {
        error_log("Generating admission number for academic year ID: " . $academicYearId);
        
        try {
            // Get year from academic year
            $stmt = $this->db->prepare("SELECT name, YEAR(start_date) as year FROM academic_years WHERE id = ?");
            $stmt->execute([$academicYearId]);
            $academicYear = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$academicYear) {
                $year = date('Y');
                error_log("Academic year not found, using current year: " . $year);
            } else {
                $year = $academicYear['year'];
                error_log("Using academic year: " . $academicYear['name'] . " (Year: " . $year . ")");
            }
            
            // Generate admission number format: SCH-YEAR-XXXX
            $prefix = strtoupper(substr($this->schoolData['slug'] ?? 'SCH', 0, 3));
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM students WHERE school_id = ? AND YEAR(created_at) = ?");
            $stmt->execute([$this->schoolId, date('Y')]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            
            $admissionNumber = sprintf("%s-%s-%04d", $prefix, $year, $count);
            error_log("Generated admission number: " . $admissionNumber);
            
            return $admissionNumber;
        } catch (Exception $e) {
            error_log("Error generating admission number: " . $e->getMessage());
            // Fallback admission number
            $fallback = strtoupper(substr($this->schoolData['slug'] ?? 'SCH', 0, 3)) . '-' . date('Y') . '-' . rand(1000, 9999);
            error_log("Using fallback admission number: " . $fallback);
            return $fallback;
        }
    }

    /**
     * Find or create parent user – FIXED to handle email conflicts with non‑parent users
     * @param array $parentData Parent information
     * @return int Parent user ID
     */
    public function findOrCreateParent($parentData) {
        error_log("=== FIND OR CREATE PARENT ===");
        error_log("Parent data: " . json_encode($parentData));
        
        try {
            // If email is provided, first check if any user (any type) has it
            if (!empty($parentData['email'])) {
                $stmt = $this->db->prepare("
                    SELECT id, user_type FROM users 
                    WHERE school_id = ? AND email = ?
                ");
                $stmt->execute([$this->schoolId, $parentData['email']]);
                $existingUser = $stmt->fetch();

                if ($existingUser) {
                    // If it's a parent, return the existing ID
                    if ($existingUser['user_type'] === 'parent') {
                        error_log("Found existing parent with ID: " . $existingUser['id']);
                        return $existingUser['id'];
                    }

                    // If it's not a parent, we cannot use this email – generate a unique one
                    error_log("Email already used by a non-parent (" . $existingUser['user_type'] . "), generating a unique email");
                    $baseEmail = $parentData['email'];
                    $counter = 1;
                    while ($counter < 100) {
                        // Add a plus suffix before the @ (common pattern for unique emails)
                        $parts = explode('@', $baseEmail);
                        $newEmail = $parts[0] . '+' . $counter . '@' . $parts[1];
                        $checkStmt = $this->db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
                        $checkStmt->execute([$this->schoolId, $newEmail]);
                        if (!$checkStmt->fetch()) {
                            $parentData['email'] = $newEmail;
                            error_log("Generated unique parent email: " . $newEmail);
                            break;
                        }
                        $counter++;
                    }
                    if ($counter >= 100) {
                        // Fallback: add timestamp
                        $parts = explode('@', $baseEmail);
                        $parentData['email'] = $parts[0] . time() . '@' . $parts[1];
                        error_log("Fallback unique parent email: " . $parentData['email']);
                    }
                }
            }

            // Now check if a parent with this (possibly new) email already exists (in case it was created in a race condition)
            if (!empty($parentData['email'])) {
                $stmt = $this->db->prepare("
                    SELECT id FROM users 
                    WHERE school_id = ? AND user_type = 'parent' AND email = ?
                    LIMIT 1
                ");
                $stmt->execute([$this->schoolId, $parentData['email']]);
                $existingParent = $stmt->fetch();
                if ($existingParent) {
                    error_log("Found existing parent (after email adjustment) with ID: " . $existingParent['id']);
                    return $existingParent['id'];
                }
            }

            // If email is still empty, generate one
            if (empty($parentData['email'])) {
                $nameParts = explode(' ', trim($parentData['name']));
                $firstName = $nameParts[0] ?? '';
                $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';
                $parentData['email'] = $this->generateProfessionalEmail($firstName, $lastName, 'parent');
                error_log("Generated parent email from name: " . $parentData['email']);
            }

            // Generate username from email
            $username = explode('@', $parentData['email'])[0];

            // Generate password for new parent
            $password = $this->generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            error_log("Generated parent password (hashed)");

            // Create new parent user
            $stmt = $this->db->prepare("
                INSERT INTO users (
                    school_id, name, email, phone, username, password, user_type,
                    address, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'parent', ?, 1, NOW(), NOW())
            ");
            
            $result = $stmt->execute([
                $this->schoolId,
                $parentData['name'],
                $parentData['email'],
                // 🔧 FIX: empty phone -> null
                !empty($parentData['phone']) ? $parentData['phone'] : null,
                $username,
                $hashedPassword,
                $parentData['address'] ?? null
            ]);
            
            if (!$result) {
                error_log("Failed to insert parent user");
                throw new Exception("Failed to insert parent user");
            }
            
            $parentUserId = $this->db->lastInsertId();
            error_log("Created new parent with ID: " . $parentUserId);

            // Assign parent role (role_id for parent is 5)
            $roleStmt = $this->db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 5, NOW())");
            $roleResult = $roleStmt->execute([$parentUserId]);
            
            if (!$roleResult) {
                error_log("Failed to assign parent role");
            } else {
                error_log("Assigned parent role to user ID: " . $parentUserId);
            }

            // Store password for email notification
            if (!isset($_SESSION['temp_passwords'])) {
                $_SESSION['temp_passwords'] = [];
            }
            $_SESSION['temp_passwords'][$parentUserId] = $password;
            error_log("Stored temporary password for parent ID: " . $parentUserId);

            return $parentUserId;
            
        } catch (Exception $e) {
            error_log("ERROR in findOrCreateParent: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Get sections from database
     * @param int $classId
     * @return array
     */
    public function getSectionsByClass($classId) {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name, capacity 
                FROM sections 
                WHERE school_id = ? AND class_id = ? AND is_active = 1
                ORDER BY name
            ");
            $stmt->execute([$this->schoolId, $classId]);
            $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Retrieved " . count($sections) . " sections for class ID: " . $classId);
            return $sections;
        } catch (Exception $e) {
            error_log("Error getting sections: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search existing parents
     * @param string $search Term to search (name, email, phone)
     * @return array
     */
    public function searchParents($search) {
        error_log("Searching parents with term: " . $search);
        
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, 
                       COUNT(g.student_id) as linked_students
                FROM users u
                LEFT JOIN guardians g ON u.id = g.user_id AND g.school_id = u.school_id
                WHERE u.school_id = ? AND u.user_type = 'parent'
                AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)
                GROUP BY u.id
                LIMIT 10
            ");
            
            $searchTerm = "%{$search}%";
            $stmt->execute([$this->schoolId, $searchTerm, $searchTerm, $searchTerm]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($results) . " parents matching term");
            return $results;
        } catch (Exception $e) {
            error_log("Error searching parents: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Add new student
     * @param array $data Student data
     * @return array [success, message, student_id]
     */
    public function addStudent($data) {
        error_log("=== ADD STUDENT START ===");
        error_log("School ID: " . $this->schoolId);
        error_log("User ID: " . $this->userId);
        error_log("Data received: " . json_encode(array_keys($data)));
        
        try {
            $this->db->beginTransaction();
            error_log("Transaction started");

            // Validate required fields
            $requiredFields = ['first_name', 'last_name', 'class_id', 'academic_year_id', 'date_of_birth'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    error_log("Validation failed: $field is empty");
                    throw new Exception("Required field '$field' is missing");
                }
            }
            error_log("Validation passed");

            // Ensure date_of_birth is set
            if (empty($data['date_of_birth'])) {
                $data['date_of_birth'] = date('Y-m-d', strtotime('-6 years'));
                error_log("Set default date_of_birth: " . $data['date_of_birth']);
            }

            // Generate admission number
            $admissionNumber = $this->generateAdmissionNumber($data['academic_year_id']);
            error_log("Generated admission number: " . $admissionNumber);

            // Check if admission number already exists
            $checkStmt = $this->db->prepare("SELECT id FROM students WHERE school_id = ? AND admission_number = ?");
            $checkStmt->execute([$this->schoolId, $admissionNumber]);
            if ($checkStmt->fetch()) {
                error_log("Admission number already exists, generating new one");
                $admissionNumber = $admissionNumber . '-' . time();
                error_log("New admission number: " . $admissionNumber);
            }

            // Generate student email if not provided
            if (empty($data['student_email'])) {
                $data['student_email'] = $this->generateProfessionalEmail(
                    $data['first_name'],
                    $data['last_name'],
                    'student'
                );
                error_log("Generated student email: " . $data['student_email']);
            }

            // Check if email already exists
            $checkEmailStmt = $this->db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
            $checkEmailStmt->execute([$this->schoolId, $data['student_email']]);
            if ($checkEmailStmt->fetch()) {
                error_log("Student email already exists, generating with timestamp");
                $data['student_email'] = $this->generateProfessionalEmail(
                    $data['first_name'],
                    $data['last_name'],
                    'student'
                ) . '.' . time();
                error_log("New student email: " . $data['student_email']);
            }

            // Generate student password
            $studentPassword = $this->generateRandomPassword();
            $hashedStudentPassword = password_hash($studentPassword, PASSWORD_DEFAULT);
            error_log("Student password generated (hashed)");

            // Create student user account
            $fullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name']);
            $username = explode('@', $data['student_email'])[0];
            
            error_log("Creating student user account:");
            error_log("- Full name: " . $fullName);
            error_log("- Email: " . $data['student_email']);
            error_log("- Username: " . $username);
            
            $userStmt = $this->db->prepare("
                INSERT INTO users (
                    school_id, name, email, phone, username, password, user_type, 
                    gender, date_of_birth, address, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'student', ?, ?, ?, 1, NOW(), NOW())
            ");
            
            $userResult = $userStmt->execute([
                $this->schoolId,
                $fullName,
                $data['student_email'],
                $data['student_phone'] ?? null, // already handled in form
                $username,
                $hashedStudentPassword,
                $data['gender'] ?? null,
                $data['date_of_birth'],
                $data['current_address'] ?? null,
            ]);
            
            if (!$userResult) {
                error_log("Failed to insert student user");
                throw new Exception("Failed to insert student user");
            }
            
            $studentUserId = $this->db->lastInsertId();
            error_log("Student user created with ID: " . $studentUserId);

            // Assign student role (role_id for student is 4)
            $roleStmt = $this->db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 4, NOW())");
            $roleResult = $roleStmt->execute([$studentUserId]);
            
            if (!$roleResult) {
                error_log("Failed to assign student role");
            } else {
                error_log("Student role assigned to user ID: " . $studentUserId);
            }

            // Get default campus ID
            $campusId = $this->getDefaultCampusId();
            error_log("Campus ID: " . ($campusId ?? 'null'));

            // Insert student record with campus_id
            error_log("Inserting student record...");
            $studentStmt = $this->db->prepare("
                INSERT INTO students (
                    school_id, campus_id, user_id, admission_number, roll_number, 
                    class_id, section_id, admission_date, first_name, middle_name, 
                    last_name, date_of_birth, current_address, permanent_address, 
                    previous_school, previous_class, transfer_certificate_no, 
                    blood_group, allergies, medical_conditions, doctor_name, 
                    doctor_phone, status, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW()
                )
            ");

            $studentParams = [
                $this->schoolId,
                $campusId,
                $studentUserId,
                $admissionNumber,
                $data['roll_number'] ?? null,
                $data['class_id'],
                $data['section_id'] ?? null,
                $data['admission_date'] ?? date('Y-m-d'),
                $data['first_name'],
                $data['middle_name'] ?? null,
                $data['last_name'],
                $data['date_of_birth'],
                $data['current_address'] ?? null,
                $data['permanent_address'] ?? null,
                $data['previous_school'] ?? null,
                $data['previous_class'] ?? null,
                $data['transfer_certificate_no'] ?? null,
                $data['blood_group'] ?? null,
                $data['allergies'] ?? null,
                $data['medical_conditions'] ?? null,
                $data['doctor_name'] ?? null,
                $data['doctor_phone'] ?? null,
            ];

            error_log("Student insert params prepared: " . json_encode(array_slice($studentParams, 0, 12)) . "...");
            
            $studentResult = $studentStmt->execute($studentParams);
            
            if (!$studentResult) {
                error_log("Failed to insert student record");
                throw new Exception("Failed to insert student record");
            }

            $studentId = $this->db->lastInsertId();
            error_log("=== STUDENT RECORD CREATED SUCCESSFULLY ===");
            error_log("Student ID: " . $studentId);
            error_log("Admission Number: " . $admissionNumber);

            // Handle parent/guardian connection
            $parentUserId = null;
            
            if (!empty($data['existing_parent_id'])) {
                // Connect existing parent
                $parentUserId = $data['existing_parent_id'];
                error_log("Using existing parent ID: " . $parentUserId);
            } elseif (!empty($data['guardian_name'])) {
                error_log("Creating new parent from guardian data");
                // Prepare parent data for findOrCreate
                $parentData = [
                    'name'    => $data['guardian_name'],
                    'email'   => $data['guardian_email'] ?? '',
                    // 🔧 FIX: empty phone -> null
                    'phone'   => !empty($data['guardian_phone']) ? $data['guardian_phone'] : null,
                    'address' => $data['guardian_address'] ?? $data['current_address'] ?? ''
                ];
                
                $parentUserId = $this->findOrCreateParent($parentData);
                error_log("Parent user ID from findOrCreate: " . ($parentUserId ?? 'null'));
            }

            // Create guardian relationship if parent exists
            if ($parentUserId) {
                $relationship = !empty($data['guardian_relation']) ? $data['guardian_relation'] : 'guardian';
                error_log("Creating guardian relationship - Parent: $parentUserId, Student: $studentId, Relationship: $relationship");

                // Check if relationship already exists
                $checkGuardianStmt = $this->db->prepare("
                    SELECT id FROM guardians 
                    WHERE school_id = ? AND user_id = ? AND student_id = ?
                ");
                $checkGuardianStmt->execute([$this->schoolId, $parentUserId, $studentId]);
                
                if (!$checkGuardianStmt->fetch()) {
                    $guardianStmt = $this->db->prepare("
                        INSERT INTO guardians (
                            school_id, user_id, student_id, relationship, is_primary, can_pickup, emergency_contact
                        ) VALUES (?, ?, ?, ?, 1, 1, 1)
                    ");
                    $guardianResult = $guardianStmt->execute([$this->schoolId, $parentUserId, $studentId, $relationship]);
                    
                    if ($guardianResult) {
                        error_log("Guardian relationship created successfully");
                    } else {
                        error_log("Failed to create guardian relationship");
                    }
                } else {
                    error_log("Guardian relationship already exists");
                }
            } else {
                error_log("No parent/guardian to link");
            }

            // Store passwords for email notification
            if (!isset($_SESSION['temp_passwords'])) {
                $_SESSION['temp_passwords'] = [];
            }
            $_SESSION['temp_passwords'][$studentUserId] = $studentPassword;
            error_log("Stored temporary password for student ID: " . $studentUserId);

            // Create audit log
            error_log("Creating audit log entry");
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type, entity_id,
                    new_values, ip_address, user_agent, url, created_at
                ) VALUES (?, ?, ?, 'create', 'student', ?, ?, ?, ?, ?, NOW())
            ");
            
            $auditResult = $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $studentId,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);

            if ($auditResult) {
                error_log("Audit log created successfully");
            } else {
                error_log("Failed to create audit log");
            }

            $this->db->commit();
            error_log("Transaction committed successfully");

            // Send emails
            error_log("Attempting to send login emails");
            $emailErrors = $this->sendLoginEmails($studentId, $studentUserId, $parentUserId);

            $message = "Student added successfully! Admission Number: {$admissionNumber}";
            if (!empty($emailErrors)) {
                $message .= " But there were issues sending emails: " . implode(", ", $emailErrors);
                error_log("Email sending issues: " . implode(", ", $emailErrors));
            }

            error_log("=== ADD STUDENT COMPLETED SUCCESSFULLY ===");
            error_log("Return message: " . $message);
            
            return [true, $message, $studentId];

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
                error_log("Transaction rolled back due to error");
            }
            
            error_log("=== ADD STUDENT FAILED ===");
            error_log("Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            
            // Log PDO error info if available
            if ($e instanceof PDOException) {
                error_log("PDO Error Info: " . json_encode($e->errorInfo));
            }
            
            return [false, "Error adding student: " . $e->getMessage(), null];
        }
    }

    /**
 * Update an existing student
 * @param int $studentId Student record ID
 * @param array $data Updated data
 * @return array [success, message, student_id]
 */
public function updateStudent($studentId, $data) {
    error_log("=== UPDATE STUDENT START ===");
    error_log("Student ID: " . $studentId);
    error_log("School ID: " . $this->schoolId);
    error_log("User ID: " . $this->userId);
    error_log("Data received: " . json_encode(array_keys($data)));

    try {
        $this->db->beginTransaction();
        error_log("Transaction started");

        // Validate required fields (same as add)
        $requiredFields = ['first_name', 'last_name', 'class_id', 'date_of_birth'];
        // academic_year_id is NOT required for update (it belongs to the class)
        $missingFields = [];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $missingFields[] = str_replace('_', ' ', $field);
                error_log("Missing required field: " . $field);
            }
        }

        if (!empty($missingFields)) {
            throw new Exception("Required fields missing: " . implode(', ', $missingFields));
        }
        error_log("Validation passed");

        // Get current student data to know linked user_id
        $stmt = $this->db->prepare("SELECT user_id FROM students WHERE id = ? AND school_id = ?");
        $stmt->execute([$studentId, $this->schoolId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new Exception("Student not found");
        }
        $userId = $student['user_id'];

        // 🔧 FIX: removed academic_year_id – it does not exist in students table
        $updateStmt = $this->db->prepare("
            UPDATE students SET
                class_id = ?,
                section_id = ?,
                roll_number = ?,
                admission_number = ?,
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                date_of_birth = ?,
                current_address = ?,
                permanent_address = ?,
                previous_school = ?,
                previous_class = ?,
                transfer_certificate_no = ?,
                blood_group = ?,
                allergies = ?,
                medical_conditions = ?,
                doctor_name = ?,
                doctor_phone = ?,
                updated_at = NOW()
            WHERE id = ? AND school_id = ?
        ");

        $updateResult = $updateStmt->execute([
            $data['class_id'],
            $data['section_id'] ?? null,
            $data['roll_number'] ?? '',
            $data['admission_number'] ?? '',
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
            $data['date_of_birth'],
            $data['current_address'] ?? null,
            $data['permanent_address'] ?? null,
            $data['previous_school'] ?? null,
            $data['previous_class'] ?? null,
            $data['transfer_certificate_no'] ?? null,
            $data['blood_group'] ?? null,
            $data['allergies'] ?? null,
            $data['medical_conditions'] ?? null,
            $data['doctor_name'] ?? null,
            $data['doctor_phone'] ?? null,
            $studentId,
            $this->schoolId
        ]);

        if (!$updateResult) {
            throw new Exception("Failed to update student record");
        }
        error_log("Student record updated");

        // Update linked user if exists (same as before)
        if ($userId) {
            $fullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ? $data['middle_name'] . ' ' : '') . $data['last_name']);

            // Check email uniqueness if changed
            if (!empty($data['student_email'])) {
                $checkEmail = $this->db->prepare("
                    SELECT id FROM users 
                    WHERE school_id = ? AND email = ? AND id != ?
                ");
                $checkEmail->execute([$this->schoolId, $data['student_email'], $userId]);
                if ($checkEmail->fetch()) {
                    throw new Exception("Email already used by another user");
                }
            }

            $updateUserStmt = $this->db->prepare("
                UPDATE users SET
                    name = ?,
                    email = ?,
                    phone = ?,
                    gender = ?,
                    date_of_birth = ?,
                    address = ?,
                    updated_at = NOW()
                WHERE id = ? AND school_id = ?
            ");

            $updateUserResult = $updateUserStmt->execute([
                $fullName,
                $data['student_email'] ?? null,
                !empty($data['student_phone']) ? $data['student_phone'] : null,
                $data['gender'] ?? null,
                $data['date_of_birth'],
                $data['current_address'] ?? null,
                $userId,
                $this->schoolId
            ]);

            if (!$updateUserResult) {
                throw new Exception("Failed to update user record");
            }
            error_log("User record updated for user ID: " . $userId);
        }

        // Update password if provided
        if (!empty($data['password']) && $userId) {
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $updatePassStmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ? AND school_id = ?");
            $updatePassStmt->execute([$hashedPassword, $userId, $this->schoolId]);
            error_log("Password updated for user ID: " . $userId);
        }

        // Create audit log (same as before)
        $auditStmt = $this->db->prepare("
            INSERT INTO audit_logs (
                school_id, user_id, user_type, action, entity_type, entity_id,
                new_values, ip_address, user_agent, url, created_at
            ) VALUES (?, ?, ?, 'update', 'student', ?, ?, ?, ?, ?, NOW())
        ");
        $auditStmt->execute([
            $this->schoolId,
            $this->userId,
            $this->userType,
            $studentId,
            json_encode($data),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null
        ]);
        error_log("Audit log created");

        $this->db->commit();
        error_log("Transaction committed");

        error_log("=== UPDATE STUDENT COMPLETED SUCCESSFULLY ===");
        return [true, "Student updated successfully", $studentId];

    } catch (Exception $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
            error_log("Transaction rolled back due to error");
        }
        error_log("=== UPDATE STUDENT FAILED ===");
        error_log("Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [false, "Error updating student: " . $e->getMessage(), null];
    }
}

    /**
     * Send login credentials emails
     * @param int $studentId
     * @param int $studentUserId
     * @param int|null $parentUserId
     * @return array
     */
    private function sendLoginEmails($studentId, $studentUserId, $parentUserId = null) {
        $emailErrors = [];
        error_log("=== SENDING LOGIN EMAILS ===");

        try {
            // Get student details
            $stmt = $this->db->prepare("
                SELECT u.*, s.first_name, s.last_name 
                FROM users u
                JOIN students s ON u.id = s.user_id
                WHERE u.id = ? AND s.id = ?
            ");
            $stmt->execute([$studentUserId, $studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);

            // Send email to student
            if (!empty($student['email']) && isset($_SESSION['temp_passwords'][$studentUserId])) {
                error_log("Sending email to student: " . $student['email']);
                $subject = "Welcome to " . ($this->schoolData['name'] ?? 'School') . " - Your Login Credentials";
                $body = $this->getEmailTemplate(
                    $student['first_name'] . ' ' . $student['last_name'],
                    $student['email'],
                    $_SESSION['temp_passwords'][$studentUserId],
                    'Student'
                );
                
                if ($this->sendEmail($student['email'], $subject, $body)) {
                    error_log("Student email sent successfully");
                } else {
                    error_log("Failed to send student email");
                    $emailErrors[] = "Failed to send email to student";
                }
                unset($_SESSION['temp_passwords'][$studentUserId]);
            } else {
                error_log("No student email to send or password not found");
            }
        } catch (Exception $e) {
            error_log("Error sending student email: " . $e->getMessage());
            $emailErrors[] = "Error sending student email";
        }

        try {
            // Send email to parent
            if ($parentUserId && isset($_SESSION['temp_passwords'][$parentUserId])) {
                $parentStmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
                $parentStmt->execute([$parentUserId]);
                $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);

                if ($parent && !empty($parent['email'])) {
                    error_log("Sending email to parent: " . $parent['email']);
                    $subject = "Parent Portal Access - " . ($this->schoolData['name'] ?? 'School');
                    $body = $this->getEmailTemplate(
                        $parent['name'],
                        $parent['email'],
                        $_SESSION['temp_passwords'][$parentUserId],
                        'Parent'
                    );
                    
                    if ($this->sendEmail($parent['email'], $subject, $body)) {
                        error_log("Parent email sent successfully");
                    } else {
                        error_log("Failed to send parent email");
                        $emailErrors[] = "Failed to send email to parent";
                    }
                    unset($_SESSION['temp_passwords'][$parentUserId]);
                } else {
                    error_log("No parent email found or password not available");
                }
            } else {
                error_log("No parent to email or parent ID not provided");
            }
        } catch (Exception $e) {
            error_log("Error sending parent email: " . $e->getMessage());
            $emailErrors[] = "Error sending parent email";
        }

        error_log("Email sending completed with " . count($emailErrors) . " errors");
        return $emailErrors;
    }

    /**
     * Send email
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return bool
     */
    private function sendEmail($to, $subject, $body) {
        try {
            error_log("Attempting to send email to: " . $to);
            
            // Headers for HTML email
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: ' . ($this->schoolData['email'] ?? 'noreply@academixsuite.com') . "\r\n";
            
            $result = mail($to, $subject, $body, $headers);
            
            if ($result) {
                error_log("Email sent successfully via mail() function");
            } else {
                error_log("mail() function returned false");
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Error sending email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get email HTML template
     * @param string $name
     * @param string $username
     * @param string $password
     * @param string $userType
     * @return string
     */
    private function getEmailTemplate($name, $username, $password, $userType) {
        $loginUrl = function_exists('school_portal_url')
            ? school_portal_url($this->schoolData['slug'] ?? '', 'login.php', true)
            : ((defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://academixsuite.com') . '/login.php?school_slug=' . ($this->schoolData['slug'] ?? ''));
        
        return "
        <html>
        <head>
            <title>Welcome to " . ($this->schoolData['name'] ?? 'School') . "</title>
        </head>
        <body style='font-family: Arial, sans-serif;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                <h2 style='color: #25A194;'>Welcome to " . ($this->schoolData['name'] ?? 'School') . "!</h2>
                <p>Dear {$name},</p>
                <p>Your account has been created successfully. Here are your login credentials:</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 10px; background: #f5f5f5;'><strong>Username:</strong></td>
                        <td style='padding: 10px;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; background: #f5f5f5;'><strong>Password:</strong></td>
                        <td style='padding: 10px;'>{$password}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; background: #f5f5f5;'><strong>User Type:</strong></td>
                        <td style='padding: 10px;'>{$userType}</td>
                    </tr>
                </table>
                <p>Please login at: <a href='{$loginUrl}'>{$loginUrl}</a></p>
                <p style='color: #666; font-size: 12px;'>For security reasons, please change your password after first login.</p>
                <p>Best regards,<br>" . ($this->schoolData['name'] ?? 'School') . " Team</p>
            </div>
        </body>
        </html>
        ";
    }
}
