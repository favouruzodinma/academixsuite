<?php
/**
 * Guardian Manager Class
 * Handles all guardian/parent-related operations
 * 
 * @package AcademixSuite
 * @version 2.0
 */

class GuardianManager {
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
        
        error_log("GuardianManager initialized for school ID: " . $schoolId);
    }

    /**
     * Generate random password
     * @param int $length
     * @return string
     */
    private function generateRandomPassword($length = 8) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        return substr(str_shuffle($chars), 0, $length);
    }

    /**
     * Generate professional email
     * @param string $name
     * @param string $type
     * @return string
     */
    private function generateProfessionalEmail($name, $type = 'parent') {
        $name = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $name)));
        $domain = $this->schoolData['email_domain'] ?? $this->schoolData['slug'] ?? 'school';
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);
        
        $baseEmail = "{$name}@{$domain}";
        
        // Check if email exists
        $email = $baseEmail;
        $counter = 1;
        
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE school_id = ? AND email = ?");
            while ($counter < 100) {
                $stmt->execute([$this->schoolId, $email]);
                if (!$stmt->fetch()) {
                    break;
                }
                $email = preg_replace('/@/', $counter . '@', $baseEmail, 1);
                $counter++;
            }
        } catch (Exception $e) {
            error_log("Error checking email uniqueness: " . $e->getMessage());
        }
        
        return $email;
    }

    /**
     * Get all active students for dropdown
     * @param string $search Optional search term
     * @return array
     */
    public function getStudents($search = '') {
        try {
            $sql = "
                SELECT 
                    s.id, 
                    s.first_name, 
                    s.middle_name, 
                    s.last_name, 
                    s.admission_number,
                    s.roll_number,
                    c.name as class_name,
                    sc.name as section_name,
                    CONCAT(s.first_name, ' ', s.last_name) as full_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sc ON s.section_id = sc.id
                WHERE s.school_id = ? AND s.status = 'active'
            ";
            
            $params = [$this->schoolId];
            
            if (!empty($search)) {
                $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $sql .= " ORDER BY s.first_name, s.last_name LIMIT 50";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting students: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Search students for AJAX
     * @param string $term
     * @return array
     */
    public function searchStudents($term) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    s.id, 
                    s.first_name, 
                    s.last_name, 
                    s.admission_number,
                    c.name as class_name,
                    sc.name as section_name,
                    CONCAT(s.first_name, ' ', s.last_name) as full_name,
                    CONCAT(s.first_name, ' ', s.last_name, ' (', s.admission_number, ') - ', c.name) as display_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sc ON s.section_id = sc.id
                WHERE s.school_id = ? AND s.status = 'active' 
                  AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_number LIKE ?)
                ORDER BY s.first_name, s.last_name
                LIMIT 10
            ");
            
            $searchTerm = "%{$term}%";
            $stmt->execute([$this->schoolId, $searchTerm, $searchTerm, $searchTerm]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error searching students: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get guardian by user ID
     * @param int $userId
     * @return array|false
     */
    public function getGuardianByUserId($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT g.*, u.name, u.email, u.phone, u.gender, u.address, u.profile_photo
                FROM guardians g
                JOIN users u ON g.user_id = u.id
                WHERE g.user_id = ? AND g.school_id = ?
            ");
            $stmt->execute([$userId, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting guardian: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get guardian by ID
     * @param int $guardianId
     * @return array|false
     */
    public function getGuardianById($guardianId) {
        try {
            $stmt = $this->db->prepare("
                SELECT g.*, u.name, u.email, u.phone, u.gender, u.address, u.profile_photo
                FROM guardians g
                JOIN users u ON g.user_id = u.id
                WHERE g.id = ? AND g.school_id = ?
            ");
            $stmt->execute([$guardianId, $this->schoolId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting guardian: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get students linked to a guardian
     * @param int $userId
     * @return array
     */
    public function getGuardianStudents($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    s.*,
                    c.name as class_name,
                    sc.name as section_name,
                    g.relationship,
                    g.is_primary,
                    g.can_pickup,
                    g.emergency_contact
                FROM students s
                JOIN guardians g ON s.id = g.student_id
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sc ON s.section_id = sc.id
                WHERE g.user_id = ? AND g.school_id = ? AND s.status = 'active'
                ORDER BY g.is_primary DESC, s.first_name, s.last_name
            ");
            $stmt->execute([$userId, $this->schoolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting guardian students: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if user exists by email or phone
     * @param string $email
     * @param string $phone
     * @return array|false
     */
    public function findUserByEmailOrPhone($email, $phone) {
        if (empty($email) && empty($phone)) {
            return false;
        }
        
        try {
            $sql = "SELECT * FROM users WHERE school_id = ? AND (";
            $params = [$this->schoolId];
            $conditions = [];
            
            if (!empty($email)) {
                $conditions[] = "email = ?";
                $params[] = $email;
            }
            
            if (!empty($phone)) {
                $conditions[] = "phone = ?";
                $params[] = $phone;
            }
            
            $sql .= implode(" OR ", $conditions) . ") LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error finding user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create new user
     * @param array $data
     * @return int|false
     */
    private function createUser($data) {
        try {
            // Generate password
            $password = $this->generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Generate username from email
            $username = explode('@', $data['email'])[0];

            $stmt = $this->db->prepare("
                INSERT INTO users (
                    school_id, name, email, phone, username, password, user_type,
                    gender, address, profile_photo, is_active, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'parent', ?, ?, ?, 1, NOW(), NOW())
            ");

            $result = $stmt->execute([
                $this->schoolId,
                $data['guardian_name'],
                $data['email'],
                $data['phone'],
                $username,
                $hashedPassword,
                $data['gender'] ?? null,
                $data['address'],
                $data['guardian_photo'] ?? null
            ]);

            if (!$result) {
                throw new Exception("Failed to insert user");
            }

            $userId = $this->db->lastInsertId();

            // Assign parent role (role_id for parent is 5)
            $roleStmt = $this->db->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, 5, NOW())");
            $roleStmt->execute([$userId]);

            // Store password for email notification
            if (!isset($_SESSION['temp_passwords'])) {
                $_SESSION['temp_passwords'] = [];
            }
            $_SESSION['temp_passwords'][$userId] = $password;

            return $userId;

        } catch (Exception $e) {
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Link guardian to students
     * @param int $userId
     * @param array $studentIds
     * @param array $relationships
     * @return bool
     */
    public function linkGuardianToStudents($userId, $studentIds, $relationships = []) {
        if (empty($studentIds) || !is_array($studentIds)) {
            return true; // Nothing to link
        }
        
        try {
            // Check if transaction is already active
            $inTransaction = $this->db->inTransaction();
            
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }
            
            foreach ($studentIds as $index => $studentId) {
                if (empty($studentId)) continue;
                
                // Check if relationship already exists
                $checkStmt = $this->db->prepare("
                    SELECT id FROM guardians 
                    WHERE school_id = ? AND user_id = ? AND student_id = ?
                ");
                $checkStmt->execute([$this->schoolId, $userId, $studentId]);
                
                if (!$checkStmt->fetch()) {
                    // Get relationship - either from array keyed by student ID or by index
                    $relationship = 'guardian';
                    if (isset($relationships[$studentId])) {
                        $relationship = $relationships[$studentId];
                    } elseif (isset($relationships[$index])) {
                        $relationship = $relationships[$index];
                    }
                    
                    $isPrimary = ($index === 0) ? 1 : 0; // First student is primary by default
                    
                    $stmt = $this->db->prepare("
                        INSERT INTO guardians (
                            school_id, user_id, student_id, relationship, 
                            is_primary, can_pickup, emergency_contact
                        ) VALUES (?, ?, ?, ?, ?, 1, 1)
                    ");
                    
                    $stmt->execute([
                        $this->schoolId,
                        $userId,
                        $studentId,
                        $relationship,
                        $isPrimary
                    ]);
                }
            }
            
            if (!$inTransaction) {
                $this->db->commit();
            }
            
            return true;
            
        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Error linking guardian to students: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update guardian-student relationship
     * @param int $guardianId
     * @param int $studentId
     * @param array $data
     * @return bool
     */
    public function updateRelationship($guardianId, $studentId, $data) {
        try {
            $stmt = $this->db->prepare("
                UPDATE guardians 
                SET relationship = ?, is_primary = ?, can_pickup = ?, emergency_contact = ?
                WHERE id = ? AND school_id = ?
            ");
            
            return $stmt->execute([
                $data['relationship'] ?? 'guardian',
                $data['is_primary'] ?? 0,
                $data['can_pickup'] ?? 1,
                $data['emergency_contact'] ?? 0,
                $guardianId,
                $this->schoolId
            ]);
        } catch (Exception $e) {
            error_log("Error updating relationship: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove guardian-student link
     * @param int $guardianId
     * @param int $studentId
     * @return bool
     */
    public function removeStudentLink($guardianId, $studentId) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM guardians 
                WHERE id = ? AND student_id = ? AND school_id = ?
            ");
            return $stmt->execute([$guardianId, $studentId, $this->schoolId]);
        } catch (Exception $e) {
            error_log("Error removing student link: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add new guardian
     * @param array $data Guardian data including student links
     * @return array [success, message, user_id]
     */
    public function addGuardian($data) {
        error_log("=== ADD GUARDIAN START ===");
        
        try {
            // Validate required fields
            $requiredFields = ['guardian_name', 'guardian_type', 'phone', 'address', 'email'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    throw new Exception("Required field '" . str_replace('_', ' ', $field) . "' is missing");
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }

            error_log("Validation passed");

            // Check if transaction is already active
            $inTransaction = $this->db->inTransaction();
            
            if (!$inTransaction) {
                $this->db->beginTransaction();
                error_log("Transaction started");
            } else {
                error_log("Using existing transaction");
            }

            // Check if user already exists
            $existingUser = $this->findUserByEmailOrPhone($data['email'], $data['phone']);
            if ($existingUser) {
                // Check if already a guardian
                $guardianCheck = $this->db->prepare("
                    SELECT id FROM guardians 
                    WHERE school_id = ? AND user_id = ?
                ");
                $guardianCheck->execute([$this->schoolId, $existingUser['id']]);
                
                if ($guardianCheck->fetch()) {
                    throw new Exception("This person is already registered as a guardian");
                }
                
                $userId = $existingUser['id'];
                error_log("Using existing user ID: " . $userId);
            } else {
                // Create new user
                $userId = $this->createUser($data);
                if (!$userId) {
                    throw new Exception("Failed to create user account");
                }
                error_log("Created new user with ID: " . $userId);
            }

            // Link to students if provided
            if (!empty($data['student_ids']) && is_array($data['student_ids'])) {
                $relationships = $data['relationships'] ?? [];
                $linkResult = $this->linkGuardianToStudents($userId, $data['student_ids'], $relationships);
                
                if (!$linkResult) {
                    throw new Exception("Failed to link guardian to students");
                }
                
                error_log("Linked guardian to " . count($data['student_ids']) . " students");
            }

            // Create audit log
            $auditStmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    school_id, user_id, user_type, action, entity_type, entity_id,
                    new_values, ip_address, user_agent, url, created_at
                ) VALUES (?, ?, ?, 'create', 'guardian', ?, ?, ?, ?, ?, NOW())
            ");
            
            $auditStmt->execute([
                $this->schoolId,
                $this->userId,
                $this->userType,
                $userId,
                json_encode($data),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $_SERVER['REQUEST_URI'] ?? null
            ]);

            // Only commit if we started the transaction
            if (!$inTransaction) {
                $this->db->commit();
                error_log("Transaction committed successfully");
            }

            // Send login email
            $emailSent = $this->sendLoginEmail($userId);

            $message = "Guardian added successfully!";
            if (!$emailSent) {
                $message .= " Login credentials could not be sent via email.";
            }

            return [true, $message, $userId];

        } catch (Exception $e) {
            // Only rollback if we started the transaction and it's still active
            if (isset($inTransaction) && !$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
                error_log("Transaction rolled back");
            } elseif ($this->db->inTransaction()) {
                error_log("Transaction will be handled by calling code");
            }
            
            error_log("ERROR adding guardian: " . $e->getMessage());
            return [false, "Error adding guardian: " . $e->getMessage(), null];
        }
    }

    /**
     * Send login email to guardian
     * @param int $userId
     * @return bool
     */
    private function sendLoginEmail($userId) {
        try {
            error_log("Sending login email for user ID: " . $userId);
            
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || empty($user['email'])) {
                error_log("No email found for user");
                return false;
            }

            if (!isset($_SESSION['temp_passwords'][$userId])) {
                error_log("No password found for user");
                return false;
            }

            $password = $_SESSION['temp_passwords'][$userId];
            $loginUrl = function_exists('school_portal_url')
                ? school_portal_url($this->schoolData['slug'] ?? '', 'login.php', true)
                : ((defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://academixsuite.com') . '/login.php?school_slug=' . ($this->schoolData['slug'] ?? ''));
            
            $subject = "Welcome to " . ($this->schoolData['name'] ?? 'School') . " - Parent Portal Access";
            
            $body = "
            <html>
            <head>
                <title>Welcome to " . ($this->schoolData['name'] ?? 'School') . "</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                    .header { background: #25A194; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; margin: -20px -20px 20px -20px; }
                    .header h2 { margin: 0; }
                    .credentials { background: #f5f5f5; padding: 20px; border-radius: 5px; margin: 20px 0; }
                    .credential-row { display: flex; margin-bottom: 10px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
                    .credential-label { font-weight: bold; width: 100px; }
                    .credential-value { flex: 1; font-family: monospace; background: white; padding: 5px 10px; border-radius: 3px; }
                    .footer { font-size: 12px; color: #666; text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; }
                    .btn { display: inline-block; background: #25A194; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Welcome to " . ($this->schoolData['name'] ?? 'School') . "!</h2>
                    </div>
                    
                    <p>Dear {$user['name']},</p>
                    
                    <p>Your parent portal account has been created successfully. You can now access the parent portal to:</p>
                    
                    <ul>
                        <li>View your children's academic progress</li>
                        <li>Track attendance records</li>
                        <li>Receive important announcements</li>
                        <li>Communicate with teachers</li>
                        <li>View fee details and make payments</li>
                    </ul>
                    
                    <div class='credentials'>
                        <h4 style='margin-top: 0;'>Your Login Credentials</h4>
                        
                        <div class='credential-row'>
                            <div class='credential-label'>Email:</div>
                            <div class='credential-value'>{$user['email']}</div>
                        </div>
                        
                        <div class='credential-row'>
                            <div class='credential-label'>Password:</div>
                            <div class='credential-value'>{$password}</div>
                        </div>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='{$loginUrl}' class='btn'>Login to Parent Portal</a>
                    </div>
                    
                    <p><strong>Important Security Notes:</strong></p>
                    <ul>
                        <li>This is your initial password. Please change it after your first login</li>
                        <li>Never share your login credentials with anyone</li>
                        <li>The school will never ask for your password via email</li>
                    </ul>
                    
                    <div class='footer'>
                        <p>This is an automated message. Please do not reply to this email.</p>
                        <p>&copy; " . date('Y') . " " . ($this->schoolData['name'] ?? 'School') . ". All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: ' . ($this->schoolData['email'] ?? 'noreply@academixsuite.com') . "\r\n";
            $headers .= 'Reply-To: ' . ($this->schoolData['email'] ?? 'noreply@academixsuite.com') . "\r\n";
            $headers .= 'X-Mailer: PHP/' . phpversion();
            
            $result = mail($user['email'], $subject, $body, $headers);
            
            error_log("Email sent: " . ($result ? 'Yes' : 'No'));
            
            // Clear stored password
            unset($_SESSION['temp_passwords'][$userId]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error sending email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get notifications from database
     * @param int $limit
     * @return array
     */
    public function getNotifications($limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM notifications 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->execute([$this->schoolId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching notifications: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unread notification count
     * @return int
     */
    public function getNotificationCount() {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM notifications 
                WHERE school_id = ? AND is_read = 0
            ");
            $stmt->execute([$this->schoolId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("Error fetching notification count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark notification as read
     * @param int $notificationId
     * @return bool
     */
    public function markNotificationRead($notificationId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ? AND school_id = ?
            ");
            return $stmt->execute([$notificationId, $this->schoolId]);
        } catch (Exception $e) {
            error_log("Error marking notification read: " . $e->getMessage());
            return false;
        }
    }
}
