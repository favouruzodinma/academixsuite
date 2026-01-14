<?php
/**
 * Enrollment Service
 * Handles school enrollment and onboarding
 */
namespace AcademixSuite\Services;

class EnrollmentService {
    
    private $db;
    private $paymentService;
    private $mailer;
    
    public function __construct() {
        $this->db = \Database::getPlatformConnection();
        $this->paymentService = new PaymentService();
        $this->mailer = new \AcademixSuite\Helpers\Mailer();
    }
    
    /**
     * Submit enrollment application
     */
    public function submitApplication(array $data): array {
        try {
            // Generate request number
            $requestNumber = $this->generateRequestNumber();
            
            // Validate school data
            $validation = $this->validateApplicationData($data);
            if (!$validation['valid']) {
                throw new \Exception(implode(', ', $validation['errors']));
            }
            
            // Create enrollment request
            $requestId = $this->createEnrollmentRequest($data, $requestNumber);
            
            // Save documents if provided
            if (!empty($data['documents'])) {
                $this->saveEnrollmentDocuments($requestId, $data['documents']);
            }
            
            // Send confirmation email
            $this->sendApplicationConfirmation($data, $requestNumber);
            
            // Notify platform admin
            $this->notifyAdmin($requestId);
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'request_number' => $requestNumber,
                'message' => 'Application submitted successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process enrollment application
     */
    public function processApplication(int $requestId, string $action, array $data = []): array {
        try {
            $request = $this->getEnrollmentRequest($requestId);
            
            if (!$request) {
                throw new \Exception('Enrollment request not found');
            }
            
            switch ($action) {
                case 'review':
                    return $this->markAsReviewing($requestId, $data);
                    
                case 'approve':
                    return $this->approveApplication($requestId, $data);
                    
                case 'reject':
                    return $this->rejectApplication($requestId, $data);
                    
                case 'waitlist':
                    return $this->waitlistApplication($requestId, $data);
                    
                default:
                    throw new \Exception('Invalid action');
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create school from approved application
     */
    public function createSchoolFromApplication(int $requestId, array $config = []): array {
        try {
            $request = $this->getEnrollmentRequest($requestId);
            
            if (!$request || $request['status'] !== 'accepted') {
                throw new \Exception('Application must be accepted before creating school');
            }
            
            // Generate school slug
            $slug = $this->generateSchoolSlug($request['school_name']);
            
            // Generate database name
            $databaseName = $this->generateDatabaseName($slug);
            
            // Create school record
            $schoolId = $this->createSchoolRecord($request, $slug, $databaseName, $config);
            
            // Create school database
            $databaseCreated = $this->createSchoolDatabase($databaseName);
            
            if (!$databaseCreated) {
                // Rollback school creation if database fails
                \Database::delete($this->db, 'schools', 'id = ?', [$schoolId]);
                throw new \Exception('Failed to create school database');
            }
            
            // Create admin user
            $adminId = $this->createSchoolAdmin($schoolId, $request);
            
            // Send school setup email
            $this->sendSchoolSetupEmail($request, $schoolId, $adminId);
            
            // Update enrollment request
            $this->updateRequestStatus($requestId, 'completed', 'School created successfully');
            
            return [
                'success' => true,
                'school_id' => $schoolId,
                'database_name' => $databaseName,
                'admin_id' => $adminId,
                'login_url' => $this->generateLoginUrl($slug),
                'message' => 'School created successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Process enrollment payment
     */
    public function processEnrollmentPayment(int $requestId, array $paymentData): array {
        try {
            $request = $this->getEnrollmentRequest($requestId);
            
            if (!$request) {
                throw new \Exception('Enrollment request not found');
            }
            
            if ($request['status'] !== 'accepted') {
                throw new \Exception('Application must be accepted before payment');
            }
            
            // Calculate fees
            $fees = $this->calculateEnrollmentFees($request);
            
            // Initialize payment
            $paymentResult = $this->paymentService->initializePayment([
                'type' => 'onboarding',
                'school_id' => $this->getSchoolIdFromRequest($requestId),
                'school_email' => $request['school_email'],
                'school_name' => $request['school_name'],
                'amount' => $fees['total'],
                'plan_id' => $paymentData['plan_id'] ?? 1,
                'metadata' => [
                    'request_id' => $requestId,
                    'fee_breakdown' => $fees['breakdown']
                ]
            ]);
            
            if (!$paymentResult['success']) {
                throw new \Exception($paymentResult['error'] ?? 'Payment initialization failed');
            }
            
            // Create enrollment fees record
            $this->createEnrollmentFees($requestId, $fees);
            
            return [
                'success' => true,
                'payment_url' => $paymentResult['payment_url'],
                'reference' => $paymentResult['reference'],
                'fees' => $fees,
                'message' => 'Payment initialized successfully'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get enrollment statistics
     */
    public function getEnrollmentStats(): array {
        $sql = "SELECT 
                COUNT(*) as total_applications,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'reviewing' THEN 1 ELSE 0 END) as reviewing,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'waitlisted' THEN 1 ELSE 0 END) as waitlisted,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                DATE(created_at) as date
            FROM enrollment_requests 
            WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $dailyStats = $stmt->fetchAll();
        
        // Get monthly summary
        $monthlySql = "SELECT 
                COUNT(*) as total,
                status,
                MONTH(created_at) as month,
                YEAR(created_at) as year
            FROM enrollment_requests 
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(created_at), MONTH(created_at), status
            ORDER BY year DESC, month DESC";
        
        $stmt = $this->db->prepare($monthlySql);
        $stmt->execute();
        $monthlyStats = $stmt->fetchAll();
        
        // Get conversion rate
        $conversionSql = "SELECT 
                (SELECT COUNT(*) FROM enrollment_requests WHERE status = 'completed') as completed,
                (SELECT COUNT(*) FROM enrollment_requests) as total";
        
        $stmt = $this->db->prepare($conversionSql);
        $stmt->execute();
        $conversion = $stmt->fetch();
        
        $conversionRate = $conversion['total'] > 0 ? 
            ($conversion['completed'] / $conversion['total']) * 100 : 0;
        
        return [
            'daily' => $dailyStats,
            'monthly' => $monthlyStats,
            'conversion_rate' => round($conversionRate, 2),
            'total_applications' => array_sum(array_column($dailyStats, 'total_applications'))
        ];
    }
    
    /**
     * Send follow-up email
     */
    public function sendFollowUp(int $requestId, string $type = 'reminder'): array {
        $request = $this->getEnrollmentRequest($requestId);
        
        if (!$request) {
            return ['success' => false, 'error' => 'Request not found'];
        }
        
        try {
            $data = [
                'request' => $request,
                'school_name' => $request['school_name'],
                'request_number' => $request['request_number'],
                'status' => $request['status'],
                'next_steps' => $this->getNextSteps($request['status'])
            ];
            
            switch ($type) {
                case 'welcome':
                    $this->mailer->sendTemplate('enrollment-welcome', $request['school_email'], $data);
                    break;
                    
                case 'reminder':
                    $data['days_pending'] = $this->getDaysPending($request['created_at']);
                    $this->mailer->sendTemplate('enrollment-reminder', $request['school_email'], $data);
                    break;
                    
                case 'payment_reminder':
                    $data['fees'] = $this->getEnrollmentFees($requestId);
                    $this->mailer->sendTemplate('payment-reminder', $request['school_email'], $data);
                    break;
                    
                case 'completion':
                    $school = $this->getSchoolFromRequest($requestId);
                    if ($school) {
                        $data['school'] = $school;
                        $data['login_url'] = $this->generateLoginUrl($school['slug']);
                        $this->mailer->sendTemplate('enrollment-completion', $request['school_email'], $data);
                    }
                    break;
            }
            
            // Log the email
            $this->logFollowUp($requestId, $type);
            
            return [
                'success' => true,
                'message' => 'Follow-up email sent successfully',
                'sent_to' => $request['school_email']
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate application data
     */
    private function validateApplicationData(array $data): array {
        $errors = [];
        
        $required = [
            'school_name', 'school_email', 'school_phone',
            'principal_name', 'school_type', 'curriculum'
        ];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[] = "Field '$field' is required";
            }
        }
        
        // Validate email
        if (!empty($data['school_email']) && !filter_var($data['school_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format";
        }
        
        // Check if school with same email already exists
        if (!empty($data['school_email'])) {
            $exists = \Database::select(
                $this->db,
                'schools',
                'COUNT(*) as count',
                'email = ?',
                [$data['school_email']]
            );
            
            if ($exists[0]['count'] > 0) {
                $errors[] = "A school with this email already exists";
            }
        }
        
        // Check if school with same name already exists
        if (!empty($data['school_name'])) {
            $exists = \Database::select(
                $this->db,
                'schools',
                'COUNT(*) as count',
                'name = ?',
                [$data['school_name']]
            );
            
            if ($exists[0]['count'] > 0) {
                $errors[] = "A school with this name already exists";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Generate request number
     */
    private function generateRequestNumber(): string {
        $prefix = 'ENR';
        $year = date('Y');
        $sequence = $this->getNextRequestSequence();
        
        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }
    
    /**
     * Create enrollment request
     */
    private function createEnrollmentRequest(array $data, string $requestNumber): int {
        $requestData = [
            'school_id' => null, // Will be set when school is created
            'request_number' => $requestNumber,
            'school_name' => $data['school_name'],
            'school_email' => $data['school_email'],
            'school_phone' => $data['school_phone'],
            'school_address' => $data['school_address'] ?? '',
            'principal_name' => $data['principal_name'],
            'school_type' => $data['school_type'],
            'curriculum' => $data['curriculum'],
            'student_count' => $data['student_count'] ?? 0,
            'teacher_count' => $data['teacher_count'] ?? 0,
            'mission_statement' => $data['mission_statement'] ?? '',
            'vision_statement' => $data['vision_statement'] ?? '',
            'additional_info' => $data['additional_info'] ?? '',
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'enrollment_requests', $requestData);
    }
    
    /**
     * Save enrollment documents
     */
    private function saveEnrollmentDocuments(int $requestId, array $documents): void {
        foreach ($documents as $document) {
            \Database::insert($this->db, 'enrollment_documents', [
                'enrollment_request_id' => $requestId,
                'document_type' => $document['type'],
                'document_name' => $document['name'],
                'file_path' => $document['path'],
                'file_size' => $document['size'],
                'uploaded_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Get enrollment request
     */
    private function getEnrollmentRequest(int $requestId): ?array {
        $result = \Database::select(
            $this->db,
            'enrollment_requests',
            '*',
            'id = ?',
            [$requestId]
        );
        
        return $result[0] ?? null;
    }
    
    /**
     * Mark application as reviewing
     */
    private function markAsReviewing(int $requestId, array $data): array {
        \Database::update(
            $this->db,
            'enrollment_requests',
            [
                'status' => 'reviewing',
                'reviewed_by' => $data['reviewer_id'] ?? null,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $data['notes'] ?? ''
            ],
            'id = ?',
            [$requestId]
        );
        
        $this->sendStatusUpdateEmail($requestId, 'reviewing');
        
        return [
            'success' => true,
            'message' => 'Application marked as reviewing'
        ];
    }
    
    /**
     * Approve application
     */
    private function approveApplication(int $requestId, array $data): array {
        \Database::update(
            $this->db,
            'enrollment_requests',
            [
                'status' => 'accepted',
                'reviewed_by' => $data['reviewer_id'] ?? null,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $data['notes'] ?? ''
            ],
            'id = ?',
            [$requestId]
        );
        
        $this->sendStatusUpdateEmail($requestId, 'accepted');
        
        return [
            'success' => true,
            'message' => 'Application approved successfully'
        ];
    }
    
    /**
     * Reject application
     */
    private function rejectApplication(int $requestId, array $data): array {
        \Database::update(
            $this->db,
            'enrollment_requests',
            [
                'status' => 'rejected',
                'reviewed_by' => $data['reviewer_id'] ?? null,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $data['notes'] ?? ''
            ],
            'id = ?',
            [$requestId]
        );
        
        $this->sendStatusUpdateEmail($requestId, 'rejected');
        
        return [
            'success' => true,
            'message' => 'Application rejected'
        ];
    }
    
    /**
     * Waitlist application
     */
    private function waitlistApplication(int $requestId, array $data): array {
        \Database::update(
            $this->db,
            'enrollment_requests',
            [
                'status' => 'waitlisted',
                'reviewed_by' => $data['reviewer_id'] ?? null,
                'reviewed_at' => date('Y-m-d H:i:s'),
                'admin_notes' => $data['notes'] ?? ''
            ],
            'id = ?',
            [$requestId]
        );
        
        $this->sendStatusUpdateEmail($requestId, 'waitlisted');
        
        return [
            'success' => true,
            'message' => 'Application added to waitlist'
        ];
    }
    
    /**
     * Generate school slug
     */
    private function generateSchoolSlug(string $schoolName): string {
        $slug = strtolower($schoolName);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Check if slug exists
        $counter = 1;
        $originalSlug = $slug;
        
        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }
    
    /**
     * Generate database name
     */
    private function generateDatabaseName(string $slug): string {
        $prefix = defined('DB_SCHOOL_PREFIX') ? DB_SCHOOL_PREFIX : 'school_';
        return $prefix . preg_replace('/[^a-z0-9_]/', '_', $slug);
    }
    
    /**
     * Create school record
     */
    private function createSchoolRecord(array $request, string $slug, string $databaseName, array $config): int {
        $schoolData = [
            'name' => $request['school_name'],
            'slug' => $slug,
            'email' => $request['school_email'],
            'phone' => $request['school_phone'],
            'address' => $request['school_address'],
            'principal_name' => $request['principal_name'],
            'school_type' => $request['school_type'],
            'curriculum' => $request['curriculum'],
            'mission_statement' => $request['mission_statement'] ?? '',
            'vision_statement' => $request['vision_statement'] ?? '',
            'student_count' => $request['student_count'] ?? 0,
            'teacher_count' => $request['teacher_count'] ?? 0,
            'database_name' => $databaseName,
            'status' => 'pending', // Will be activated after payment
            'plan_id' => $config['plan_id'] ?? 1,
            'settings' => json_encode([
                'timezone' => $config['timezone'] ?? 'Africa/Lagos',
                'currency' => $config['currency'] ?? 'NGN',
                'language' => $config['language'] ?? 'en',
                'academic_year' => date('Y') . '-' . (date('Y') + 1)
            ]),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return \Database::insert($this->db, 'schools', $schoolData);
    }
    
    /**
     * Create school database
     */
    private function createSchoolDatabase(string $databaseName): bool {
        try {
            return \Database::createSchoolDatabase($databaseName);
        } catch (\Exception $e) {
            error_log("Database creation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create school admin
     */
    private function createSchoolAdmin(int $schoolId, array $request): int {
        // Generate temporary password
        $tempPassword = bin2hex(random_bytes(8));
        $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $adminData = [
            'school_id' => $schoolId,
            'name' => $request['principal_name'],
            'email' => $request['school_email'],
            'password' => $hashedPassword,
            'role' => 'owner',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $adminId = \Database::insert($this->db, 'school_admins', $adminData);
        
        // Store temporary password for email
        $_SESSION['temp_passwords'][$adminId] = $tempPassword;
        
        return $adminId;
    }
    
    /**
     * Calculate enrollment fees
     */
    private function calculateEnrollmentFees(array $request): array {
        $planId = $request['plan_id'] ?? 1;
        
        // Get plan details
        $plan = \Database::select($this->db, 'plans', '*', 'id = ?', [$planId]);
        
        if (empty($plan)) {
            throw new \Exception('Selected plan not found');
        }
        
        $plan = $plan[0];
        
        $fees = [
            'breakdown' => [
                [
                    'name' => 'Setup Fee',
                    'amount' => 50000, // N50,000 setup fee
                    'description' => 'One-time setup and configuration'
                ],
                [
                    'name' => 'Platform Subscription (' . $plan['name'] . ')',
                    'amount' => $plan['price_yearly'],
                    'description' => 'Annual subscription for ' . $plan['name'] . ' plan'
                ]
            ],
            'subtotal' => 50000 + $plan['price_yearly'],
            'tax' => 0,
            'total' => 50000 + $plan['price_yearly']
        ];
        
        // Add tax if applicable
        if (defined('TAX_RATE') && TAX_RATE > 0) {
            $fees['tax'] = $fees['subtotal'] * (TAX_RATE / 100);
            $fees['total'] += $fees['tax'];
            
            $fees['breakdown'][] = [
                'name' => 'VAT (' . TAX_RATE . '%)',
                'amount' => $fees['tax'],
                'description' => 'Value Added Tax'
            ];
        }
        
        return $fees;
    }
    
    /**
     * Create enrollment fees record
     */
    private function createEnrollmentFees(int $requestId, array $fees): void {
        foreach ($fees['breakdown'] as $fee) {
            \Database::insert($this->db, 'enrollment_fees', [
                'enrollment_request_id' => $requestId,
                'fee_type' => 'application',
                'description' => $fee['name'],
                'amount' => $fee['amount'],
                'due_date' => date('Y-m-d', strtotime('+7 days')),
                'is_paid' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    /**
     * Get enrollment fees
     */
    private function getEnrollmentFees(int $requestId): array {
        return \Database::select(
            $this->db,
            'enrollment_fees',
            '*',
            'enrollment_request_id = ?',
            [$requestId]
        );
    }
    
    /**
     * Send application confirmation
     */
    private function sendApplicationConfirmation(array $data, string $requestNumber): void {
        $mailData = [
            'school_name' => $data['school_name'],
            'request_number' => $requestNumber,
            'principal_name' => $data['principal_name'],
            'submission_date' => date('Y-m-d'),
            'next_steps' => 'Our team will review your application within 2-3 business days.'
        ];
        
        $this->mailer->sendTemplate('enrollment-confirmation', $data['school_email'], $mailData);
    }
    
    /**
     * Send status update email
     */
    private function sendStatusUpdateEmail(int $requestId, string $status): void {
        $request = $this->getEnrollmentRequest($requestId);
        
        if (!$request) {
            return;
        }
        
        $mailData = [
            'school_name' => $request['school_name'],
            'request_number' => $request['request_number'],
            'status' => ucfirst($status),
            'update_date' => date('Y-m-d'),
            'next_steps' => $this->getNextSteps($status)
        ];
        
        if ($status === 'accepted') {
            $mailData['action_url'] = $this->generatePaymentUrl($requestId);
        }
        
        $this->mailer->sendTemplate('enrollment-status-update', $request['school_email'], $mailData);
    }
    
    /**
     * Send school setup email
     */
    private function sendSchoolSetupEmail(array $request, int $schoolId, int $adminId): void {
        $tempPassword = $_SESSION['temp_passwords'][$adminId] ?? '';
        
        $mailData = [
            'school_name' => $request['school_name'],
            'login_url' => $this->generateLoginUrl($request['slug'] ?? ''),
            'admin_email' => $request['school_email'],
            'temp_password' => $tempPassword,
            'setup_date' => date('Y-m-d'),
            'support_contact' => 'support@academixsuite.com'
        ];
        
        $this->mailer->sendTemplate('school-setup-complete', $request['school_email'], $mailData);
        
        // Clear temporary password from session
        unset($_SESSION['temp_passwords'][$adminId]);
    }
    
    /**
     * Notify platform admin
     */
    private function notifyAdmin(int $requestId): void {
        // Get admin emails
        $admins = \Database::select(
            $this->db,
            'platform_users',
            'email',
            'role = ? AND is_active = 1',
            ['super_admin']
        );
        
        if (empty($admins)) {
            return;
        }
        
        $request = $this->getEnrollmentRequest($requestId);
        
        $mailData = [
            'request_id' => $requestId,
            'school_name' => $request['school_name'],
            'school_email' => $request['school_email'],
            'principal_name' => $request['principal_name'],
            'submission_date' => $request['created_at'],
            'review_url' => $this->generateAdminReviewUrl($requestId)
        ];
        
        foreach ($admins as $admin) {
            $this->mailer->sendTemplate('new-enrollment-notification', $admin['email'], $mailData);
        }
    }
    
    /**
     * Get next steps based on status
     */
    private function getNextSteps(string $status): string {
        $steps = [
            'pending' => 'Your application is being processed. We will contact you soon.',
            'reviewing' => 'Your application is under review. This may take 2-3 business days.',
            'accepted' => 'Congratulations! Please proceed to payment to activate your school.',
            'rejected' => 'We regret to inform you that your application was not accepted.',
            'waitlisted' => 'Your application has been added to our waitlist.',
            'completed' => 'Your school setup is complete. You can now log in.'
        ];
        
        return $steps[$status] ?? 'We will contact you with next steps.';
    }
    
    /**
     * Get days pending
     */
    private function getDaysPending(string $createdAt): int {
        $created = new \DateTime($createdAt);
        $now = new \DateTime();
        $interval = $created->diff($now);
        return $interval->days;
    }
    
    /**
     * Check if slug exists
     */
    private function slugExists(string $slug): bool {
        $result = \Database::select(
            $this->db,
            'schools',
            'COUNT(*) as count',
            'slug = ?',
            [$slug]
        );
        
        return ($result[0]['count'] ?? 0) > 0;
    }
    
    /**
     * Get next request sequence
     */
    private function getNextRequestSequence(): int {
        $year = date('Y');
        
        $sql = "SELECT COUNT(*) as count FROM enrollment_requests WHERE YEAR(created_at) = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$year]);
        $result = $stmt->fetch();
        
        return (int) ($result['count'] ?? 0) + 1;
    }
    
    /**
     * Update request status
     */
    private function updateRequestStatus(int $requestId, string $status, string $notes = ''): void {
        \Database::update(
            $this->db,
            'enrollment_requests',
            [
                'status' => $status,
                'admin_notes' => $notes,
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$requestId]
        );
    }
    
    /**
     * Get school from request
     */
    private function getSchoolFromRequest(int $requestId): ?array {
        $request = $this->getEnrollmentRequest($requestId);
        
        if (!$request || empty($request['school_id'])) {
            return null;
        }
        
        return \Database::select(
            $this->db,
            'schools',
            '*',
            'id = ?',
            [$request['school_id']]
        )[0] ?? null;
    }
    
    /**
     * Get school ID from request
     */
    private function getSchoolIdFromRequest(int $requestId): ?int {
        $request = $this->getEnrollmentRequest($requestId);
        return $request['school_id'] ?? null;
    }
    
    /**
     * Generate login URL
     */
    private function generateLoginUrl(string $slug): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/tenant/' . $slug . '/login';
    }
    
    /**
     * Generate payment URL
     */
    private function generatePaymentUrl(int $requestId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/enrollment/payment/' . $requestId;
    }
    
    /**
     * Generate admin review URL
     */
    private function generateAdminReviewUrl(int $requestId): string {
        $baseUrl = defined('APP_URL') ? APP_URL : 'https://academixsuite.com';
        return $baseUrl . '/platform/admin/enrollment/review/' . $requestId;
    }
    
    /**
     * Log follow-up
     */
    private function logFollowUp(int $requestId, string $type): void {
        \Database::insert($this->db, 'enrollment_followups', [
            'enrollment_request_id' => $requestId,
            'type' => $type,
            'sent_at' => date('Y-m-d H:i:s')
        ]);
    }
}