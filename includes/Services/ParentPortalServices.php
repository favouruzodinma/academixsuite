<?php
/**
 * Parent Portal Service
 * Handles parent authentication, student access, and fee management
 */
namespace AcademixSuite\Services;

class ParentPortalService {
    
    private $db;
    private $paymentService;
    private $invoiceService;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->paymentService = new PaymentService();
        $this->invoiceService = new InvoiceService();
    }
    
    /**
     * Authenticate parent
     */
    public function authenticate(string $email, string $password): array {
        try {
            // Find parent by email
            $parent = $this->getParentByEmail($email);
            
            if (!$parent) {
                throw new \Exception('Invalid email or password');
            }
            
            // Verify password
            if (!password_verify($password, $parent['password'])) {
                throw new \Exception('Invalid email or password');
            }
            
            // Check if parent is active
            if (!$parent['is_active']) {
                throw new \Exception('Your account has been deactivated. Please contact the school.');
            }
            
            // Get school details
            $school = $this->getSchool($parent['school_id']);
            
            if (!$school || $school['status'] !== 'active') {
                throw new \Exception('School account is not active');
            }
            
            // Get children/wards
            $children = $this->getChildren($parent['id'], $parent['school_id']);
            
            if (empty($children)) {
                throw new \Exception('No students associated with this account');
            }
            
            // Create session
            $sessionData = $this->createParentSession($parent, $school, $children);
            
            // Log login
            $this->logLogin($parent['id']);
            
            return [
                'success' => true,
                'parent' => $sessionData,
                'message' => 'Login successful'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Grant access via token (for email links)
     */
    public function grantAccessViaToken(string $token, string $accessCode = null): array {
        try {
            $access = $this->validateAccessToken($token, $accessCode);
            
            if (!$access) {
                throw new \Exception('Invalid or expired access token');
            }
            
            // Get parent details
            $parent = $this->getParent($access['parent_id']);
            $school = $this->getSchool($access['school_id']);
            $children = [$this->getStudent($access['student_id'])];
            
            // Create session
            $sessionData = $this->createParentSession($parent, $school, $children);
            
            // Update token usage
            $this->updateTokenUsage($access['id']);
            
            return [
                'success' => true,
                'parent' => $sessionData,
                'message' => 'Access granted'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Request parent access
     */
    public function requestAccess(string $parentEmail, int $studentId, int $schoolId): array {
        try {
            // Validate student exists and is active
            $student = $this->getStudent($studentId);
            
            if (!$student) {
                throw new \Exception('Student not found');
            }
            
            // Check if student belongs to the school
            if ($student['school_id'] != $schoolId) {
                throw new \Exception('Student does not belong to this school');
            }
            
            // Get or create parent
            $parent = $this->getOrCreateParent($parentEmail, $schoolId);
            
            // Check if access already exists
            $existingAccess = $this->getExistingAccess($parent['id'], $studentId);
            
            if ($existingAccess) {
                if ($existingAccess['is_active']) {
                    throw new \Exception('Access already granted for this student');
                } else {
                    // Reactivate existing access
                    $this->reactivateAccess($existingAccess['id']);
                    $token = $existingAccess['access_token'];
                }
            } else {
                // Create new access
                $token = $this->createParentAccess($parent['id'], $studentId, $schoolId);
            }
            
            // Send access email
            $this->sendAccessEmail($parentEmail, $token, $student);
            
            return [
                'success' => true,
                'token' => $token,
                'message' => 'Access request submitted. Check your email for login instructions.'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get student dashboard data
     */
    public function getStudentDashboard(int $parentId, int $studentId): array {
        try {
            // Verify parent has access to student
            if (!$this->hasAccessToStudent($parentId, $studentId)) {
                throw new \Exception('You do not have access to this student');
            }
            
            $student = $this->getStudent($studentId);
            $school = $this->getSchool($student['school_id']);
            
            // Get outstanding fees
            $outstandingFees = $this->invoiceService->getOutstandingInvoices($studentId);
            
            // Get recent payments
            $recentPayments = $this->getRecentPayments($studentId);
            
            // Get attendance summary
            $attendance = $this->getAttendanceSummary($studentId);
            
            // Get upcoming events
            $events = $this->getUpcomingEvents($student['school_id']);
            
            // Get academic performance
            $performance = $this->getAcademicPerformance($studentId);
            
            return [
                'success' => true,
                'student' => $student,
                'school' => $school,
                'dashboard' => [
                    'outstanding_fees' => $outstandingFees,
                    'recent_payments' => $recentPayments,
                    'attendance' => $attendance,
                    'upcoming_events' => $events,
                    'academic_performance' => $performance
                ]
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get fee details for student
     */
    public function getStudentFees(int $parentId, int $studentId): array {
        try {
            if (!$this->hasAccessToStudent($parentId, $studentId)) {
                throw new \Exception('You do not have access to this student');
            }
            
            $outstanding = $this->invoiceService->getOutstandingInvoices($studentId);
            
            // Get fee history
            $history = $this->getFeeHistory($studentId);
            
            // Get fee structure
            $structure = $this->getFeeStructure($studentId);
            
            return [
                'success' => true,
                'outstanding' => $outstanding,
                'history' => $history,
                'structure' => $structure,
                'payment_methods' => $this->getAvailablePaymentMethods($studentId)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Initiate fee payment
     */
    public function initiateFeePayment(int $parentId, int $studentId, array $invoiceIds, string $paymentMethod = 'online'): array {
        try {
            if (!$this->hasAccessToStudent($parentId, $studentId)) {
                throw new \Exception('You do not have access to this student');
            }
            
            // Validate invoices
            $invoiceValidation = $this->validateInvoicesForPayment($invoiceIds, $studentId);
            if (!$invoiceValidation['valid']) {
                throw new \Exception($invoiceValidation['error']);
            }
            
            // Get parent details
            $parent = $this->getParent($parentId);
            
            // Calculate total amount
            $totalAmount = $this->calculateTotalAmount($invoiceIds);
            
            if ($paymentMethod === 'online') {
                // Process online payment
                return $this->processOnlinePayment($parentId, $studentId, $invoiceIds, $totalAmount, $parent);
            } else {
                // Record offline payment instruction
                return $this->recordOfflinePayment($parentId, $studentId, $invoiceIds, $totalAmount, $paymentMethod);
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get payment history
     */
    public function getPaymentHistory(int $parentId, int $studentId = null, array $filters = []): array {
        try {
            $where = '1=1';
            $params = [];
            
            if ($studentId) {
                if (!$this->hasAccessToStudent($parentId, $studentId)) {
                    throw new \Exception('You do not have access to this student');
                }
                $where .= ' AND p.student_id = ?';
                $params[] = $studentId;
            } else {
                // Get all students parent has access to
                $studentIds = $this->getAccessibleStudentIds($parentId);
                if (empty($studentIds)) {
                    return ['success' => true, 'payments' => []];
                }
                
                $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
                $where .= " AND p.student_id IN ($placeholders)";
                $params = array_merge($params, $studentIds);
            }
            
            // Apply filters
            if (!empty($filters['date_from'])) {
                $where .= ' AND DATE(p.payment_date) >= ?';
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $where .= ' AND DATE(p.payment_date) <= ?';
                $params[] = $filters['date_to'];
            }
            
            if (!empty($filters['payment_method'])) {
                $where .= ' AND p.payment_method = ?';
                $params[] = $filters['payment_method'];
            }
            
            $sql = "SELECT 
                    p.*,
                    i.invoice_number,
                    s.first_name as student_first_name,
                    s.last_name as student_last_name
                FROM payments p
                JOIN invoices i ON p.invoice_id = i.id
                JOIN students s ON p.student_id = s.id
                WHERE $where
                ORDER BY p.payment_date DESC
                LIMIT 50";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll();
            
            return [
                'success' => true,
                'payments' => $payments,
                'total_count' => count($payments)
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update parent profile
     */
    public function updateProfile(int $parentId, array $data): array {
        try {
            $allowedFields = ['name', 'phone', 'address', 'profile_picture'];
            $updateData = [];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }
            
            if (empty($updateData)) {
                throw new \Exception('No valid fields to update');
            }
            
            \Database::update(
                $this->db,
                'parents',
                $updateData,
                'id = ?',
                [$parentId]
            );
            
            return [
                'success' => true,
                'message' => 'Profile updated successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Change password
     */
    public function changePassword(int $parentId, string $currentPassword, string $newPassword): array {
        try {
            $parent = $this->getParent($parentId);
            
            if (!password_verify($currentPassword, $parent['password'])) {
                throw new \Exception('Current password is incorrect');
            }
            
            if (strlen($newPassword) < 8) {
                throw new \Exception('New password must be at least 8 characters');
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            \Database::update(
                $this->db,
                'parents',
                ['password' => $hashedPassword],
                'id = ?',
                [$parentId]
            );
            
            // Send password change notification
            $this->sendPasswordChangeNotification($parent);
            
            return [
                'success' => true,
                'message' => 'Password changed successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Request password reset
     */
    public function requestPasswordReset(string $email): array {
        try {
            $parent = $this->getParentByEmail($email);
            
            if (!$parent) {
                // Don't reveal that email doesn't exist
                return [
                    'success' => true,
                    'message' => 'If your email exists in our system, you will receive reset instructions'
                ];
            }
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            \Database::update(
                $this->db,
                'parents',
                [
                    'reset_token' => $token,
                    'reset_token_expires' => $expires
                ],
                'id = ?',
                [$parent['id']]
            );
            
            // Send reset email
            $this->sendPasswordResetEmail($parent, $token);
            
            return [
                'success' => true,
                'message' => 'Password reset instructions sent to your email'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Reset password with token
     */
    public function resetPassword(string $token, string $newPassword): array {
        try {
            $parent = $this->getParentByResetToken($token);
            
            if (!$parent) {
                throw new \Exception('Invalid or expired reset token');
            }
            
            if (strtotime($parent['reset_token_expires']) < time()) {
                throw new \Exception('Reset token has expired');
            }
            
            if (strlen($newPassword) < 8) {
                throw new \Exception('Password must be at least 8 characters');
            }
            
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            \Database::update(
                $this->db,
                'parents',
                [
                    'password' => $hashedPassword,
                    'reset_token' => null,
                    'reset_token_expires' => null
                ],
                'id = ?',
                [$parent['id']]
            );
            
            // Send confirmation email
            $this->sendPasswordResetConfirmation($parent);
            
            return [
                'success' => true,
                'message' => 'Password reset successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get parent by email
     */
    private function getParentByEmail(string $email): ?array {
        $result = \Database::select(
            $this->db,
            'parents',
            '*',
            'email = ?',
            [$email]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get parent by ID
     */
    private function getParent(int $parentId): ?array {
        $result = \Database::select(
            $this->db,
            'parents',
            '*',
            'id = ?',
            [$parentId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get school
     */
    private function getSchool(int $schoolId): ?array {
        $result = \Database::select(
            $this->db,
            'schools',
            '*',
            'id = ?',
            [$schoolId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get student
     */
    private function getStudent(int $studentId): ?array {
        $result = \Database::select(
            $this->db,
            'students',
            '*',
            'id = ?',
            [$studentId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Get children/wards for parent
     */
    private function getChildren(int $parentId, int $schoolId): array {
        $sql = "SELECT s.*, c.name as class_name
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                WHERE s.parent_id = ? AND s.school_id = ? AND s.status = 'active'
                ORDER BY s.class_id, s.first_name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$parentId, $schoolId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Create parent session
     */
    private function createParentSession(array $parent, array $school, array $children): array {
        $sessionData = [
            'parent_id' => $parent['id'],
            'name' => $parent['name'],
            'email' => $parent['email'],
            'school_id' => $school['id'],
            'school_name' => $school['name'],
            'school_slug' => $school['slug'],
            'children' => $children,
            'logged_in' => true,
            'login_time' => time()
        ];
        
        $_SESSION['parent'] = $sessionData;
        
        return $sessionData;
    }
    
    /**
     * Log login
     */
    private function logLogin(int $parentId): void {
        \Database::update(
            $this->db,
            'parents',
            [
                'last_login_at' => date('Y-m-d H:i:s'),
                'last_login_ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ],
            'id = ?',
            [$parentId]
        );
    }
    
    /**
     * Validate access token
     */
    private function validateAccessToken(string $token, string $accessCode = null): ?array {
        $where = 'access_token = ? AND is_active = 1 AND expires_at > NOW()';
        $params = [$token];
        
        if ($accessCode) {
            $where .= ' AND access_code = ?';
            $params[] = $accessCode;
        }
        
        $result = \Database::select(
            $this->db,
            'parent_portal_access',
            '*',
            $where,
            $params
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Update token usage
     */
    private function updateTokenUsage(int $accessId): void {
        \Database::update(
            $this->db,
            'parent_portal_access',
            [
                'last_login_at' => date('Y-m-d H:i:s'),
                'login_count' => new \PDOExpr('login_count + 1')
            ],
            'id = ?',
            [$accessId]
        );
    }
    
    /**
     * Get or create parent
     */
    private function getOrCreateParent(string $email, int $schoolId): array {
        $parent = $this->getParentByEmail($email);
        
        if ($parent) {
            return $parent;
        }
        
        // Create new parent
        $tempPassword = bin2hex(random_bytes(8));
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $parentId = \Database::insert($this->db, 'parents', [
            'school_id' => $schoolId,
            'email' => $email,
            'name' => '', // Will be updated later
            'password' => $hashedPassword,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Store temp password for email
        $_SESSION['new_parent_passwords'][$parentId] = $tempPassword;
        
        return $this->getParent($parentId);
    }
    
    /**
     * Get existing access
     */
    private function getExistingAccess(int $parentId, int $studentId): ?array {
        $result = \Database::select(
            $this->db,
            'parent_portal_access',
            '*',
            'parent_id = ? AND student_id = ?',
            [$parentId, $studentId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Reactivate access
     */
    private function reactivateAccess(int $accessId): void {
        \Database::update(
            $this->db,
            'parent_portal_access',
            [
                'is_active' => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
            ],
            'id = ?',
            [$accessId]
        );
    }
    
    /**
     * Create parent access
     */
    private function createParentAccess(int $parentId, int $studentId, int $schoolId): string {
        $token = bin2hex(random_bytes(32));
        $accessCode = strtoupper(substr(md5(uniqid()), 0, 6));
        
        \Database::insert($this->db, 'parent_portal_access', [
            'school_id' => $schoolId,
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'access_token' => $token,
            'access_code' => $accessCode,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        return $token;
    }
    
    /**
     * Send access email
     */
    private function sendAccessEmail(string $email, string $token, array $student): void {
        $mailer = new \AcademixSuite\Helpers\Mailer();
        
        $data = [
            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
            'access_token' => $token,
            'login_url' => $this->generateTokenLoginUrl($token),
            'expiry_date' => date('Y-m-d', strtotime('+30 days'))
        ];
        
        $mailer->sendTemplate('parent-access-granted', $email, $data);
    }
    
    /**
     * Check if parent has access to student
     */
    private function hasAccessToStudent(int $parentId, int $studentId): bool {
        $result = \Database::select(
            $this->db,
            'guardians',
            'COUNT(*) as count',
            'user_id = ? AND student_id = ?',
            [$parentId, $studentId]
        );
        
        return ($result[0]['count'] ?? 0) > 0;
    }
    
    /**
     * Get recent payments
     */
    private function getRecentPayments(int $studentId, int $limit = 5): array {
        return \Database::select(
            $this->db,
            'payments',
            '*',
            'student_id = ?',
            [$studentId],
            'payment_date DESC',
            $limit
        );
    }
    
    /**
     * Get attendance summary
     */
    private function getAttendanceSummary(int $studentId): array {
        $sql = "SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                MONTH(date) as month
            FROM attendance 
            WHERE student_id = ? AND DATE(date) >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
            GROUP BY MONTH(date)
            ORDER BY month DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get upcoming events
     */
    private function getUpcomingEvents(int $schoolId, int $limit = 5): array {
        return \Database::select(
            $this->db,
            'events',
            '*',
            'school_id = ? AND start_date >= CURDATE()',
            [$schoolId],
            'start_date ASC',
            $limit
        );
    }
    
    /**
     * Get academic performance
     */
    private function getAcademicPerformance(int $studentId): array {
        $sql = "SELECT 
                e.name as exam_name,
                AVG(eg.marks_obtained) as average_score,
                s.name as subject_name
            FROM exam_grades eg
            JOIN exams e ON eg.exam_id = e.id
            JOIN subjects s ON eg.subject_id = s.id
            WHERE eg.student_id = ?
            GROUP BY eg.exam_id, eg.subject_id
            ORDER BY e.start_date DESC
            LIMIT 10";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get fee history
     */
    private function getFeeHistory(int $studentId): array {
        $sql = "SELECT 
                i.*,
                SUM(p.amount) as total_paid
            FROM invoices i
            LEFT JOIN payments p ON i.id = p.invoice_id
            WHERE i.student_id = ?
            GROUP BY i.id
            ORDER BY i.issue_date DESC
            LIMIT 20";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$studentId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get fee structure
     */
    private function getFeeStructure(int $studentId): array {
        $student = $this->getStudent($studentId);
        
        if (!$student || !$student['class_id']) {
            return [];
        }
        
        $sql = "SELECT 
                fs.*,
                fc.name as category_name
            FROM fee_structures fs
            JOIN fee_categories fc ON fs.fee_category_id = fc.id
            WHERE fs.class_id = ? AND fs.is_active = 1
            ORDER BY fc.name";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$student['class_id']]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get available payment methods
     */
    private function getAvailablePaymentMethods(int $studentId): array {
        $student = $this->getStudent($studentId);
        $school = $this->getSchool($student['school_id']);
        
        $methods = ['cash', 'bank_transfer', 'cheque'];
        
        // Check if school has online payment enabled
        if ($this->schoolHasOnlinePayment($student['school_id'])) {
            $methods[] = 'online';
        }
        
        return $methods;
    }
    
    /**
     * Validate invoices for payment
     */
    private function validateInvoicesForPayment(array $invoiceIds, int $studentId): array {
        if (empty($invoiceIds)) {
            return ['valid' => false, 'error' => 'No invoices selected'];
        }
        
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $params = array_merge($invoiceIds, [$studentId]);
        
        $sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('pending', 'partial') THEN 1 ELSE 0 END) as payable,
                SUM(balance_amount) as total_balance
            FROM invoices 
            WHERE id IN ($placeholders) AND student_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        
        if ($result['total'] != count($invoiceIds)) {
            return ['valid' => false, 'error' => 'Some invoices do not exist or belong to a different student'];
        }
        
        if ($result['payable'] == 0) {
            return ['valid' => false, 'error' => 'No payable invoices selected'];
        }
        
        return [
            'valid' => true,
            'total_balance' => $result['total_balance']
        ];
    }
    
    /**
     * Calculate total amount
     */
    private function calculateTotalAmount(array $invoiceIds): float {
        if (empty($invoiceIds)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $sql = "SELECT SUM(balance_amount) as total FROM invoices WHERE id IN ($placeholders)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($invoiceIds);
        $result = $stmt->fetch();
        
        return (float) ($result['total'] ?? 0);
    }
    
    /**
     * Process online payment
     */
    private function processOnlinePayment(int $parentId, int $studentId, array $invoiceIds, float $totalAmount, array $parent): array {
        $paymentData = [
            'type' => 'fee_payment',
            'school_id' => $this->getSchoolIdFromStudent($studentId),
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'parent_email' => $parent['email'],
            'parent_name' => $parent['name'],
            'invoice_ids' => $invoiceIds,
            'amount' => $totalAmount
        ];
        
        $result = $this->paymentService->initializePayment($paymentData);
        
        if (!$result['success']) {
            throw new \Exception($result['error'] ?? 'Payment initialization failed');
        }
        
        // Create batch payment record
        $batchId = $this->createBatchPayment($parentId, $studentId, $invoiceIds, $totalAmount, $result['reference']);
        
        return [
            'success' => true,
            'payment_url' => $result['payment_url'],
            'reference' => $result['reference'],
            'batch_id' => $batchId,
            'amount' => $totalAmount,
            'message' => 'Redirecting to payment gateway...'
        ];
    }
    
    /**
     * Record offline payment
     */
    private function recordOfflinePayment(int $parentId, int $studentId, array $invoiceIds, float $totalAmount, string $paymentMethod): array {
        // Create payment instruction
        $instructionId = \Database::insert($this->db, 'payment_instructions', [
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'invoice_ids' => json_encode($invoiceIds),
            'total_amount' => $totalAmount,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Notify school admin
        $this->notifySchoolAdmin($studentId, $instructionId, $totalAmount, $paymentMethod);
        
        return [
            'success' => true,
            'instruction_id' => $instructionId,
            'message' => 'Payment instruction recorded. The school will contact you for payment completion.'
        ];
    }
    
    /**
     * Get accessible student IDs
     */
    private function getAccessibleStudentIds(int $parentId): array {
        $result = \Database::select(
            $this->db,
            'guardians',
            'student_id',
            'user_id = ?',
            [$parentId]
        );
        
        return array_column($result, 'student_id');
    }
    
    /**
     * Get parent by reset token
     */
    private function getParentByResetToken(string $token): ?array {
        $result = \Database::select(
            $this->db,
            'parents',
            '*',
            'reset_token = ? AND reset_token_expires > NOW()',
            [$token]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Check if school has online payment
     */
    private function schoolHasOnlinePayment(int $schoolId): bool {
        $result = \Database::select(
            $this->db,
            'payment_gateways',
            'COUNT(*) as count',
            'school_id = ? AND is_active = 1',
            [$schoolId]
        );
        
        return ($result[0]['count'] ?? 0) > 0;
    }
    
    /**
     * Get school ID from student
     */
    private function getSchoolIdFromStudent(int $studentId): int {
        $student = $this->getStudent($studentId);
        return $student['school_id'] ?? 0;
    }
    
    /**
     * Create batch payment
     */
    private function createBatchPayment(int $parentId, int $studentId, array $invoiceIds, float $totalAmount, string $reference): int {
        return \Database::insert($this->db, 'batch_payments', [
            'school_id' => $this->getSchoolIdFromStudent($studentId),
            'parent_id' => $parentId,
            'student_id' => $studentId,
            'batch_reference' => $reference,
            'total_amount' => $totalAmount,
            'status' => 'pending',
            'metadata' => json_encode(['invoice_ids' => $invoiceIds]),
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Notify school admin
     */
    private function notifySchoolAdmin(int $studentId, int $instructionId, float $amount, string $method): void {
        // Implementation for notifying school admin about offline payment
    }
    
    /**
     * Generate token login URL
     */
    private function generateTokenLoginUrl(string $token): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/parent-portal/access/' . $token;
    }
    
    /**
     * Send password change notification
     */
    private function sendPasswordChangeNotification(array $parent): void {
        $mailer = new \AcademixSuite\Helpers\Mailer();
        
        $data = [
            'parent_name' => $parent['name'],
            'change_time' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ];
        
        $mailer->sendTemplate('password-change-notification', $parent['email'], $data);
    }
    
    /**
     * Send password reset email
     */
    private function sendPasswordResetEmail(array $parent, string $token): void {
        $mailer = new \AcademixSuite\Helpers\Mailer();
        
        $data = [
            'parent_name' => $parent['name'],
            'reset_url' => $this->generateResetUrl($token),
            'expiry_time' => '1 hour'
        ];
        
        $mailer->sendTemplate('password-reset-request', $parent['email'], $data);
    }
    
    /**
     * Send password reset confirmation
     */
    private function sendPasswordResetConfirmation(array $parent): void {
        $mailer = new \AcademixSuite\Helpers\Mailer();
        
        $data = [
            'parent_name' => $parent['name'],
            'reset_time' => date('Y-m-d H:i:s')
        ];
        
        $mailer->sendTemplate('password-reset-confirmation', $parent['email'], $data);
    }
    
    /**
     * Generate reset URL
     */
    private function generateResetUrl(string $token): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/parent-portal/reset-password/' . $token;
    }
}