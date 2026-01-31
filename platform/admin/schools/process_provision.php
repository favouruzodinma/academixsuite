<?php

/**
 * Process School Provisioning - Complete Backend Processing
 * Creates separate database for each school with complete schema
 * Enhanced with proper transaction handling and Tenant integration
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/provisioning_errors.log');
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

// Initialize response
$response = [
    'success' => false,
    'message' => '',
    'school_slug' => '',
    'admin_email' => '',
    'school_id' => '',
    'school_url' => '',
    'admin_credentials' => [],
    'trial_info' => [],
    'debug' => []
];

try {
    error_log("=== SCHOOL PROVISIONING STARTED ===");
    error_log("Timestamp: " . date('Y-m-d H:i:s'));

    // Load required files
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("System files not found. Please check installation.");
    }

    require_once $autoloadPath;
    error_log("System files loaded");

    // Check authentication
    $auth = new Auth();
    if (!$auth->isLoggedIn('super_admin')) {
        throw new Exception("Unauthorized access. Please log in as super administrator.");
    }

    $superAdmin = $_SESSION['super_admin'];
    error_log("Super Admin: " . ($superAdmin['name'] ?? 'Unknown'));

    // Validate CSRF token
    if (!isset($_POST['csrf_token'])) {
        throw new Exception("Security validation failed. CSRF token missing.");
    }

    // Simple CSRF validation
    if (!function_exists('validateCsrfToken')) {
        function validateCsrfToken($token)
        {
            return isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] === $token;
        }
    }

    if (!validateCsrfToken($_POST['csrf_token'])) {
        throw new Exception("Security validation failed. Please refresh the page and try again.");
    }

    // Validate required fields
    $requiredFields = [
        'name' => 'School name',
        'email' => 'School email',
        'admin_first_name' => 'Admin first name',
        'admin_last_name' => 'Admin last name',
        'admin_email' => 'Admin email',
        'admin_password' => 'Admin password',
        'admin_role' => 'Admin role',
        'country' => 'Country',
        'state' => 'State',
        'city' => 'City',
        'address' => 'Address',
        'phone' => 'Phone number',
        'school_type' => 'School type',
        'curriculum' => 'Curriculum',
        'campus_type' => 'Campus type'
    ];

    $missing = [];
    foreach ($requiredFields as $field => $label) {
        if (empty($_POST[$field])) {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        throw new Exception("Missing required fields: " . implode(', ', $missing));
    }

    // Validate email formats
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid school email format.");
    }

    if (!filter_var($_POST['admin_email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid administrator email format.");
    }

    // Get database connection
    $db = Database::getPlatformConnection();
    error_log("Platform database connected");

    // Check if school email already exists
    $stmt = $db->prepare("SELECT id FROM schools WHERE email = ?");
    $stmt->execute([$_POST['email']]);
    if ($stmt->fetch()) {
        throw new Exception("A school with this email already exists.");
    }

    // Check if admin email already exists in any school
    $stmt = $db->prepare("SELECT school_id FROM school_admins WHERE email = ?");
    $stmt->execute([$_POST['admin_email']]);
    if ($stmt->fetch()) {
        throw new Exception("An administrator with this email already exists in another school.");
    }

    // Generate unique slug
    if (!function_exists('generateSlug')) {
        function generateSlug($string, $table = '', $column = 'slug')
        {
            $slug = strtolower(trim($string));
            $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
            $slug = preg_replace('/-+/', '-', $slug);
            $slug = trim($slug, '-');
            return $slug;
        }
    }

    function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    $slug = generateSlug($_POST['name']);
    $stmt = $db->prepare("SELECT id FROM schools WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        $slug = $slug . '-' . time();
    }

    error_log("Generated slug: " . $slug);

    // Get selected plan
    $planId = intval($_POST['plan_id'] ?? 2);
    $stmt = $db->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();

    if (!$plan) {
        throw new Exception("Selected plan not found or is inactive.");
    }

    error_log("Selected plan: " . $plan['name']);

    // Calculate trial period
    $trialPeriod = intval($_POST['trial_period'] ?? 7);
    $trialEndsAt = date('Y-m-d H:i:s', strtotime("+{$trialPeriod} days"));

    // Generate campus code if not provided
    $campusCode = $_POST['campus_code'] ?? '';
    if (empty($campusCode)) {
        $campusType = $_POST['campus_type'] ?? 'main';
        $prefix = strtoupper(substr($campusType, 0, 3));
        $campusCode = $prefix . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }

    // Prepare admin name
    $adminFirstName = trim($_POST['admin_first_name']);
    $adminLastName = trim($_POST['admin_last_name']);
    $adminName = $adminFirstName . ' ' . $adminLastName;
    $adminPhone = $_POST['admin_phone'] ?? '';

    // Prepare school data
    $schoolData = [
        'parent_school_id' => null,
        'uuid' => (function_exists('generateUuid') ? generateUuid() : uniqid()),
        'name' => trim($_POST['name']),
        'description' => $_POST['description'] ?? null,
        'mission_statement' => $_POST['mission_statement'] ?? null,
        'vision_statement' => $_POST['vision_statement'] ?? null,
        'principal_name' => $_POST['principal_name'] ?? null,
        'principal_message' => $_POST['principal_message'] ?? null,
        'slug' => $slug,
        'school_type' => $_POST['school_type'] ?? 'secondary',
        'curriculum' => $_POST['curriculum'] ?? 'Nigerian',
        'student_count' => intval($_POST['student_count'] ?? 0),
        'teacher_count' => intval($_POST['teacher_count'] ?? 0),
        'class_count' => intval($_POST['class_count'] ?? 0),
        'email' => trim($_POST['email']),
        'phone' => trim($_POST['phone']),
        'address' => trim($_POST['address']),
        'city' => trim($_POST['city']),
        'postal_code' => $_POST['postal_code'] ?? '',
        'state' => trim($_POST['state']),
        'country' => trim($_POST['country'] ?? 'Nigeria'),
        'establishment_year' => !empty($_POST['establishment_year']) ? intval($_POST['establishment_year']) : null,
        'avg_rating' => 0.00,
        'total_reviews' => 0,
        'fee_range_from' => 0.00,
        'fee_range_to' => 0.00,
        'facilities' => null,
        'gallery_images' => null,
        'admission_status' => 'open',
        'accreditation' => null,
        'accreditations' => null,
        'affiliations' => null,
        'extracurricular_activities' => null,
        'sports_facilities' => null,
        'transportation_available' => 0,
        'boarding_available' => 0,
        'meal_provided' => 0,
        'teacher_student_ratio' => null,
        'average_class_size' => null,
        'school_hours' => null,
        'admission_process' => null,
        'admission_deadline' => null,
        'entrance_exam_required' => 0,
        'interview_required' => 0,
        'social_links' => null,
        'logo_path' => null,
        'primary_color' => '#3B82F6',
        'secondary_color' => '#10B981',
        'database_name' => null, // Will be updated after database creation
        'database_host' => defined('DB_HOST') ? DB_HOST : 'localhost',
        'database_port' => defined('DB_PORT') ? DB_PORT : 3306,
        'plan_id' => $planId,
        'status' => 'trial',
        'trial_ends_at' => $trialEndsAt,
        'subscription_ends_at' => null,
        'settings' => json_encode([
            'timezone' => 'Africa/Lagos',
            'currency' => 'NGN',
            'language' => 'en',
            'attendance_method' => 'daily',
            'grading_system' => 'percentage'
        ]),
        'timezone' => 'Africa/Lagos',
        'currency' => 'NGN',
        'language' => 'en',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'suspended_at' => null,
        'campus_type' => $_POST['campus_type'] ?? 'main',
        'campus_code' => $campusCode,
        'storage_used' => 0,
        'request_count' => 0,
        'last_request_at' => null,
        'last_backup_at' => null,
        'last_optimized_at' => null
    ];

    error_log("Prepared school data");

    // ============================================================
    // MAIN TRANSACTION BLOCK - All or nothing
    // ============================================================
    $transactionStarted = false;
    $schoolId = null;

    try {
        // Begin transaction for platform database
        // First check if auto-commit is on
        $autoCommit = $db->getAttribute(PDO::ATTR_AUTOCOMMIT);
        error_log("Auto-commit status: " . ($autoCommit ? 'ON' : 'OFF'));
        
        // Turn off auto-commit explicitly
        $db->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
        
        // Begin transaction
        $db->beginTransaction();
        $transactionStarted = true;
        error_log("Platform transaction started successfully");
        
        // Double-check transaction is active
        if (!$db->inTransaction()) {
            throw new Exception("Failed to start transaction. Check PDO configuration.");
        }

        // Insert school record
        $columns = implode(', ', array_keys($schoolData));
        $placeholders = ':' . implode(', :', array_keys($schoolData));
        $sql = "INSERT INTO schools ($columns) VALUES ($placeholders)";

        $stmt = $db->prepare($sql);
        foreach ($schoolData as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();

        $schoolId = $db->lastInsertId();
        if (!$schoolId) {
            throw new Exception("Failed to create school record.");
        }

        error_log("School inserted with ID: " . $schoolId);

        // Handle logo upload (optional)
        $logoPath = null;
        if (isset($_FILES['logo_path']) && $_FILES['logo_path']['error'] === UPLOAD_ERR_OK) {
            error_log("Processing logo upload");

            $uploadDir = realpath(__DIR__ . '/../../../../') . '/assets/uploads/schools/' . $schoolId . '/';

            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            $fileType = mime_content_type($_FILES['logo_path']['tmp_name']);

            if (in_array($fileType, $allowedTypes) && $_FILES['logo_path']['size'] <= 5 * 1024 * 1024) {
                $fileExt = strtolower(pathinfo($_FILES['logo_path']['name'], PATHINFO_EXTENSION));
                $fileName = 'logo-' . $slug . '.' . $fileExt;
                $filePath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['logo_path']['tmp_name'], $filePath)) {
                    $logoPath = 'assets/uploads/schools/' . $schoolId . '/' . $fileName;
                    $updateLogoStmt = $db->prepare("UPDATE schools SET logo_path = ? WHERE id = ?");
                    $updateLogoStmt->execute([$logoPath, $schoolId]);
                    error_log("Logo uploaded: " . $logoPath);
                    $response['debug']['logo_uploaded'] = true;
                }
            }
        }

        // ============================================================
        // CRITICAL SECTION: CREATE SEPARATE SCHOOL DATABASE
        // ============================================================
        if (class_exists('Tenant')) {
            $tenant = new Tenant();
            
            try {
                error_log("Creating separate school database using Tenant class...");
                
                // Prepare data for database creation
                $adminEmail = trim($_POST['admin_email']);
                $adminPassword = $_POST['admin_password'];
                $schoolName = trim($_POST['name']);
                
                // Prepare admin data array matching Tenant.php expectations
                $adminData = [
                    'id' => $schoolId,
                    'name' => $adminName,
                    'admin_name' => $adminName,
                    'admin_email' => $adminEmail,
                    'admin_phone' => $adminPhone,
                    'admin_password' => $adminPassword
                ];
                
                // IMPORTANT: Database creation might auto-commit!
                // Let's check transaction status before and after
                error_log("Transaction status before database creation: " . ($db->inTransaction() ? 'ACTIVE' : 'NOT ACTIVE'));
                
                // Create the complete database with all tables
                $databaseResult = $tenant->createSchoolDatabase($adminData);
                
                error_log("Transaction status after database creation: " . ($db->inTransaction() ? 'ACTIVE' : 'NOT ACTIVE'));
                
                if (!$databaseResult['success']) {
                    throw new Exception("Failed to create school database: " . ($databaseResult['message'] ?? 'Unknown error'));
                }
                
                // Get the database name from result or generate it
                $newDatabaseName = $databaseResult['database_name'] ?? 'school_' . $schoolId;
                
                // Update database name in schools table
                $updateStmt = $db->prepare("UPDATE schools SET database_name = ? WHERE id = ?");
                $updateStmt->execute([$newDatabaseName, $schoolId]);
                
                error_log("Database created: " . $newDatabaseName);
                $response['debug']['database_creation'] = $databaseResult;
                
                // Create school directories
                $tenant->createSchoolDirectories($schoolId);
                error_log("School directories created");
                
                // Ensure school portal exists
                $schoolWithDb = array_merge($schoolData, [
                    'id' => $schoolId,
                    'database_name' => $newDatabaseName
                ]);
                
                $tenant->ensureSchoolPortal($schoolWithDb);
                error_log("School portal ensured");
                
                // Test connection to new database
                try {
                    $testConn = Database::getSchoolConnection($newDatabaseName);
                    $testStmt = $testConn->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = DATABASE()");
                    $tableCount = $testStmt->fetch()['table_count'];
                    error_log("Successfully connected to school database. Table count: " . $tableCount);
                    $response['debug']['database_test'] = ['connected' => true, 'table_count' => $tableCount];
                    
                    // Create admin user in school database using Tenant method
                    $adminUserId = $tenant->createSchoolAdminUser($schoolId, $adminEmail, $adminPassword, $adminName);
                    if ($adminUserId) {
                        error_log("Admin user created in school database with ID: " . $adminUserId);
                    } else {
                        error_log("Warning: Could not create admin user in school database");
                    }
                    
                } catch (Exception $e) {
                    error_log("Warning: Could not test database connection: " . $e->getMessage());
                }
                
            } catch (Exception $e) {
                throw new Exception("School database creation failed: " . $e->getMessage());
            }
        } else {
            throw new Exception("Tenant class not found. Cannot create school database.");
        }
        // ============================================================

        // Check transaction status before admin record creation
        if (!$db->inTransaction()) {
            error_log("WARNING: Transaction is no longer active! Restarting transaction...");
            $db->beginTransaction();
            $transactionStarted = true;
        }

        // Create admin record in platform database (school_admins table)
        error_log("Creating admin record in platform database...");
        
        // Validate and set admin role
        $allowedRoles = ['owner', 'admin', 'accountant', 'principal'];
        $adminRole = trim($_POST['admin_role'] ?? 'owner');
        $adminRole = strtolower($adminRole);

        if (!in_array($adminRole, $allowedRoles)) {
            error_log("Invalid admin role '{$adminRole}' provided. Defaulting to 'owner'.");
            $adminRole = 'owner';
        }

        // Set permissions based on role
        if ($adminRole === 'owner') {
            $adminPermissions = '["*"]';
        } elseif ($adminRole === 'admin') {
            $adminPermissions = '["dashboard.view", "students.*", "teachers.*", "classes.*", "attendance.*", "exams.*", "fees.*", "reports.*", "settings.*"]';
        } elseif ($adminRole === 'accountant') {
            $adminPermissions = '["dashboard.view", "fees.*", "payments.*", "invoices.*", "reports.financial"]';
        } elseif ($adminRole === 'principal') {
            $adminPermissions = '["dashboard.view", "students.view", "teachers.view", "classes.view", "attendance.view", "exams.view", "reports.*"]';
        } else {
            $adminPermissions = '["dashboard.view"]';
        }

        try {
            // Get admin user ID from school database
            $adminUserIdFromSchool = $response['debug']['database_creation']['admin_user_id'] ?? 1;
            
            // Try normal insert first
            $adminStmt = $db->prepare("
                INSERT INTO school_admins 
                (school_id, user_id, email, role, permissions, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, 1, NOW())
            ");
            
            $adminStmt->execute([
                $schoolId,
                $adminUserIdFromSchool,
                trim($_POST['admin_email']),
                $adminRole,
                $adminPermissions
            ]);
            
            $platformAdminId = $db->lastInsertId();
            
        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $adminIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM school_admins");
                $nextAdminId = $adminIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                $adminStmt = $db->prepare("
                    INSERT INTO school_admins 
                    (id, school_id, user_id, email, role, permissions, is_active, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                
                $adminStmt->execute([
                    $nextAdminId,
                    $schoolId,
                    $adminUserIdFromSchool,
                    trim($_POST['admin_email']),
                    $adminRole,
                    $adminPermissions
                ]);
                
                $platformAdminId = $nextAdminId;
            } else {
                throw $e;
            }
        }

        error_log("Admin record created in platform with ID: " . $platformAdminId . ", role: " . $adminRole);

        // Check transaction status before subscription creation
        if (!$db->inTransaction()) {
            error_log("WARNING: Transaction is no longer active! Restarting transaction...");
            $db->beginTransaction();
            $transactionStarted = true;
        }

        // Create subscription record
        error_log("Creating subscription record...");
        
        $billingCycle = $_POST['billing_cycle'] ?? 'yearly';
        $amount = $plan['price_monthly'];

        if ($billingCycle === 'yearly' && isset($plan['price_yearly'])) {
            $amount = $plan['price_yearly'];
        } elseif ($billingCycle === 'yearly') {
            $amount = $plan['price_monthly'] * 12 * 0.85;
        }

        // Check if subscriptions table has trial_ends_at column
        try {
            $checkColumnStmt = $db->query("SHOW COLUMNS FROM subscriptions LIKE 'trial_ends_at'");
            $hasTrialEndsAt = $checkColumnStmt->rowCount() > 0;
            error_log("Subscriptions table has trial_ends_at column: " . ($hasTrialEndsAt ? 'YES' : 'NO'));
        } catch (Exception $e) {
            $hasTrialEndsAt = false;
            error_log("Error checking trial_ends_at column: " . $e->getMessage());
        }

        try {
            if ($hasTrialEndsAt) {
                // Try insert WITH trial_ends_at
                $subStmt = $db->prepare("
                    INSERT INTO subscriptions 
                    (school_id, plan_id, status, billing_cycle, amount, currency, 
                     current_period_start, current_period_end, trial_ends_at, created_at) 
                    VALUES (?, ?, 'trial', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), ?, NOW())
                ");
                
                $subStmt->execute([
                    $schoolId,
                    $planId,
                    $billingCycle,
                    $amount,
                    $trialEndsAt
                ]);
            } else {
                // Try insert WITHOUT trial_ends_at
                $subStmt = $db->prepare("
                    INSERT INTO subscriptions 
                    (school_id, plan_id, status, billing_cycle, amount, currency, 
                     current_period_start, current_period_end, created_at) 
                    VALUES (?, ?, 'trial', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                ");
                
                $subStmt->execute([
                    $schoolId,
                    $planId,
                    $billingCycle,
                    $amount
                ]);
            }
            
            $subscriptionId = $db->lastInsertId();
            
        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $subIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM subscriptions");
                $nextSubId = $subIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                if ($hasTrialEndsAt) {
                    $subStmt = $db->prepare("
                        INSERT INTO subscriptions 
                        (id, school_id, plan_id, status, billing_cycle, amount, currency, 
                         current_period_start, current_period_end, trial_ends_at, created_at) 
                        VALUES (?, ?, ?, 'trial', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), ?, NOW())
                    ");
                    
                    $subStmt->execute([
                        $nextSubId,
                        $schoolId,
                        $planId,
                        $billingCycle,
                        $amount,
                        $trialEndsAt
                    ]);
                } else {
                    $subStmt = $db->prepare("
                        INSERT INTO subscriptions 
                        (id, school_id, plan_id, status, billing_cycle, amount, currency, 
                         current_period_start, current_period_end, created_at) 
                        VALUES (?, ?, ?, 'trial', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                    ");
                    
                    $subStmt->execute([
                        $nextSubId,
                        $schoolId,
                        $planId,
                        $billingCycle,
                        $amount
                    ]);
                }
                
                $subscriptionId = $nextSubId;
            } else {
                throw $e;
            }
        }

        error_log("Subscription created with ID: " . $subscriptionId);

        // Check transaction status before invoice creation
        if (!$db->inTransaction()) {
            error_log("WARNING: Transaction is no longer active! Restarting transaction...");
            $db->beginTransaction();
            $transactionStarted = true;
        }

        // Create initial invoice
        error_log("Creating trial invoice...");
        
        $invoiceNumber = 'INV-' . date('Ymd') . '-' . str_pad($schoolId, 4, '0', STR_PAD_LEFT);
        
        // Check if invoices table has is_trial column
        try {
            $checkInvoiceColumnStmt = $db->query("SHOW COLUMNS FROM invoices LIKE 'is_trial'");
            $hasIsTrial = $checkInvoiceColumnStmt->rowCount() > 0;
            error_log("Invoices table has is_trial column: " . ($hasIsTrial ? 'YES' : 'NO'));
        } catch (Exception $e) {
            $hasIsTrial = false;
            error_log("Error checking is_trial column: " . $e->getMessage());
        }
        
        try {
            if ($hasIsTrial) {
                // Try insert WITH is_trial
                $invoiceStmt = $db->prepare("
                    INSERT INTO invoices 
                    (school_id, subscription_id, invoice_number, description, amount, tax, total_amount, 
                     currency, status, due_date, start_date, end_date, is_trial, created_at) 
                    VALUES (?, ?, ?, ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                           NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), 1, NOW())
                ");
                
                $invoiceStmt->execute([
                    $schoolId,
                    $subscriptionId,
                    $invoiceNumber,
                    "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                    $amount,
                    $amount
                ]);
            } else {
                // Try insert WITHOUT is_trial
                $invoiceStmt = $db->prepare("
                    INSERT INTO invoices 
                    (school_id, subscription_id, invoice_number, description, amount, tax, total_amount, 
                     currency, status, due_date, start_date, end_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                           NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                ");
                
                $invoiceStmt->execute([
                    $schoolId,
                    $subscriptionId,
                    $invoiceNumber,
                    "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                    $amount,
                    $amount
                ]);
            }
            
        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $invIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM invoices");
                $nextInvId = $invIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                if ($hasIsTrial) {
                    $invoiceStmt = $db->prepare("
                        INSERT INTO invoices 
                        (id, school_id, subscription_id, invoice_number, description, amount, tax, total_amount, 
                         currency, status, due_date, start_date, end_date, is_trial, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                               NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), 1, NOW())
                    ");
                    
                    $invoiceStmt->execute([
                        $nextInvId,
                        $schoolId,
                        $subscriptionId,
                        $invoiceNumber,
                        "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                        $amount,
                        $amount
                    ]);
                } else {
                    $invoiceStmt = $db->prepare("
                        INSERT INTO invoices 
                        (id, school_id, subscription_id, invoice_number, description, amount, tax, total_amount, 
                         currency, status, due_date, start_date, end_date, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                               NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                    ");
                    
                    $invoiceStmt->execute([
                        $nextInvId,
                        $schoolId,
                        $subscriptionId,
                        $invoiceNumber,
                        "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                        $amount,
                        $amount
                    ]);
                }
            } else {
                throw $e;
            }
        }

        error_log("Trial invoice created: " . $invoiceNumber);

        // Update school statistics
        $studentCount = intval($_POST['student_count'] ?? 0);
        $teacherCount = intval($_POST['teacher_count'] ?? 0);
        if ($studentCount > 0 && $teacherCount > 0) {
            $ratio = $studentCount . ':' . $teacherCount;
            $updateRatioStmt = $db->prepare("UPDATE schools SET teacher_student_ratio = ? WHERE id = ?");
            $updateRatioStmt->execute([$ratio, $schoolId]);
        }

        $classCount = intval($_POST['class_count'] ?? 0);
        if ($classCount > 0 && $studentCount > 0) {
            $avgClassSize = ceil($studentCount / $classCount);
            $updateClassStmt = $db->prepare("UPDATE schools SET average_class_size = ? WHERE id = ?");
            $updateClassStmt->execute([$avgClassSize, $schoolId]);
        }

        // Final check before commit
        if (!$db->inTransaction()) {
            error_log("WARNING: Transaction is no longer active before commit! Cannot commit.");
            $transactionStarted = false;
        } else {
            // Commit platform database transaction
            $db->commit();
            $transactionStarted = false;
            error_log("Platform transaction committed successfully");
        }

        // Send welcome emails (outside transaction)
        $emailResult = sendProvisioningEmails($schoolId, $slug, $_POST['admin_email'], $_POST['admin_password'], $schoolData['name'], $trialEndsAt, $plan['name']);

        if (!$emailResult) {
            error_log("Warning: Email sending failed, but school was created successfully");
        }

        // Log provisioning activity (outside transaction)
        logProvisioningActivity($schoolId, $superAdmin['id'], $_POST['name']);

        // Prepare success response
        $appUrl = defined('APP_URL') ? APP_URL : 'http://localhost';
        $schoolUrl = $appUrl . "/tenant/" . $slug . "/login.php";

        $response['success'] = true;
        $response['message'] = "School '{$_POST['name']}' has been successfully provisioned with a 7-day free trial!";
        $response['school_slug'] = $slug;
        $response['admin_email'] = $_POST['admin_email'];
        $response['school_id'] = $schoolId;
        $response['school_url'] = $schoolUrl;
        $response['admin_credentials'] = [
            'name' => $adminName,
            'email' => $_POST['admin_email'],
            'password' => $_POST['admin_password'],
            'login_url' => $schoolUrl,
            'role' => $adminRole
        ];
        $response['trial_info'] = [
            'trial_days' => $trialPeriod,
            'trial_ends_at' => $trialEndsAt,
            'plan' => $plan['name'],
            'amount' => $amount,
            'currency' => 'NGN',
            'billing_cycle' => $billingCycle
        ];
        
        $response['database_info'] = [
            'database_name' => $newDatabaseName ?? 'school_' . $schoolId,
            'database_host' => defined('DB_HOST') ? DB_HOST : 'localhost',
            'tables_created' => $response['debug']['database_test']['table_count'] ?? 'unknown',
            'database_mode' => 'separate-database',
            'admin_user_id' => $adminUserId ?? 'unknown'
        ];

        error_log("=== PROVISIONING COMPLETED SUCCESSFULLY ===");
        
    } catch (Exception $e) {
        // Rollback transaction on error - safely
        if ($transactionStarted && isset($db)) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                    error_log("Transaction rolled back due to error: " . $e->getMessage());
                } else {
                    error_log("No active transaction to rollback (but transactionStarted was true): " . $e->getMessage());
                }
            } catch (Exception $rollbackEx) {
                error_log("Warning: Rollback failed: " . $rollbackEx->getMessage());
            }
        } else {
            error_log("No transaction to rollback (transactionStarted = false): " . $e->getMessage());
        }
        
        // Restore auto-commit setting
        if (isset($db) && isset($autoCommit)) {
            $db->setAttribute(PDO::ATTR_AUTOCOMMIT, $autoCommit);
        }
        
        error_log("Error trace: " . $e->getTraceAsString());
        throw $e;
    } finally {
        // Always restore auto-commit setting
        if (isset($db) && isset($autoCommit)) {
            $db->setAttribute(PDO::ATTR_AUTOCOMMIT, $autoCommit);
        }
    }

} catch (Exception $e) {
    // Log error
    error_log("=== PROVISIONING FAILED ===");
    error_log("Error: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());

    $response['success'] = false;
    $response['message'] = $e->getMessage();
    $response['debug']['error_details'] = [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];

    http_response_code(500);
}

// Clean output and send JSON response
while (ob_get_level() > 0) {
    ob_end_clean();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;

/**
 * Send provisioning emails to super admin and school admin
 */
function sendProvisioningEmails($schoolId, $schoolSlug, $adminEmail, $adminPassword, $schoolName, $trialEndsAt = null, $planName = 'Starter')
{
    try {
        $db = Database::getPlatformConnection();

        // Get school details
        $stmt = $db->prepare("SELECT name, email, database_name FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();

        if (!$school) {
            error_log("School not found for email sending");
            return false;
        }

        // Calculate trial days
        if (!$trialEndsAt) {
            $trialEndsAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        }
        $trialDays = ceil((strtotime($trialEndsAt) - time()) / (60 * 60 * 24));
        $trialDays = max(0, $trialDays);
        $trialEndDate = date('F j, Y', strtotime($trialEndsAt));

        // Format trial info message
        $trialMessage = "";
        if ($trialDays > 0) {
            $escapedPlanName = htmlspecialchars($planName, ENT_QUOTES, 'UTF-8');
            $trialMessage = "
            <div style='background: linear-gradient(135deg, #3B82F6, #1D4ED8); color: white; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <h3 style='color: white; margin-top: 0;'>🎉 7-Day Free Trial Activated!</h3>
                <div style='display: flex; align-items: center; gap: 20px; margin-bottom: 15px;'>
                    <div style='flex: 1; background: rgba(255,255,255,0.2); padding: 15px; border-radius: 6px;'>
                        <div style='font-size: 12px; opacity: 0.9;'>TRIAL DAYS LEFT</div>
                        <div style='font-size: 32px; font-weight: bold;'>{$trialDays}</div>
                    </div>
                    <div style='flex: 2;'>
                        <p style='margin: 0 0 5px 0;'><strong>Plan:</strong> {$escapedPlanName} Plan</p>
                        <p style='margin: 0 0 5px 0;'><strong>Trial Ends:</strong> {$trialEndDate}</p>
                        <p style='margin: 0; font-size: 14px;'>Enjoy full access to all features during your trial period</p>
                    </div>
                </div>
                <div style='background: rgba(255,255,255,0.1); padding: 10px 15px; border-radius: 6px; font-size: 14px;'>
                    <strong>⚠️ Important:</strong> No payment required during trial. You'll be notified before trial ends.
                </div>
            </div>
            ";
        }

        // Add database info
        $databaseInfo = "";
        if (!empty($school['database_name'])) {
            $escapedDbName = htmlspecialchars($school['database_name'], ENT_QUOTES, 'UTF-8');
            $databaseInfo = "
            <div style='background: #f0f9ff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin: 15px 0;'>
                <h4 style='color: #1e293b; margin-top: 0; margin-bottom: 10px;'>📊 Database Information</h4>
                <p style='margin: 5px 0;'><strong>Database Name:</strong> {$escapedDbName}</p>
                <p style='margin: 5px 0;'><strong>Database Type:</strong> Separate isolated database</p>
                <p style='margin: 5px 0; font-size: 14px; color: #64748b;'>Your school data is stored in a completely separate, secure database.</p>
            </div>
            ";
        }

        // Get super admin email
        $superAdminStmt = $db->prepare("SELECT email FROM platform_users WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
        $superAdminStmt->execute();
        $superAdmin = $superAdminStmt->fetch();

        // Email content for school admin
        $appUrl = defined('APP_URL') ? APP_URL : 'http://localhost';
        $adminSubject = "Welcome to AcademixSuite! 🎓 - Your School is Ready with 7-Day Free Trial";

        // Escape variables
        $escapedSchoolName = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
        $escapedAdminEmail = htmlspecialchars($adminEmail, ENT_QUOTES, 'UTF-8');
        $escapedAdminPassword = htmlspecialchars($adminPassword, ENT_QUOTES, 'UTF-8');
        $escapedSchoolSlug = htmlspecialchars($schoolSlug, ENT_QUOTES, 'UTF-8');
        $escapedAppUrl = htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8');
        $escapedTrialEndDate = htmlspecialchars($trialEndDate, ENT_QUOTES, 'UTF-8');

        $adminMessage = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Welcome to AcademixSuite</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
                body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #334155; margin: 0; padding: 0; background-color: #f8fafc; }
                .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
                .header { background: linear-gradient(135deg, #3B82F6, #1D4ED8); padding: 30px; text-align: center; color: white; }
                .logo { font-size: 28px; font-weight: 700; margin-bottom: 10px; }
                .tagline { opacity: 0.9; font-size: 14px; }
                .content { padding: 30px; }
                .welcome-text { font-size: 18px; color: #1e293b; margin-bottom: 25px; }
                .school-name { color: #3B82F6; font-weight: 600; }
                .credentials-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 25px; margin: 25px 0; }
                .credential-item { margin-bottom: 15px; }
                .credential-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
                .credential-value { font-size: 16px; color: #1e293b; font-weight: 500; }
                .login-btn { display: inline-block; background: #3B82F6; color: white; text-decoration: none; padding: 12px 30px; border-radius: 8px; font-weight: 600; margin: 20px 0; transition: background 0.3s; }
                .login-btn:hover { background: #2563eb; }
                .features-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin: 25px 0; }
                .feature { background: #f0f9ff; padding: 15px; border-radius: 8px; text-align: center; }
                .feature-icon { font-size: 24px; margin-bottom: 10px; }
                .feature-text { font-size: 14px; color: #0c4a6e; }
                .footer { background: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
                .important-note { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 0 8px 8px 0; }
                .security-note { background: #f0f9ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #3B82F6; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <div class='logo'>🎓 AcademixSuite</div>
                    <div class='tagline'>School Management Simplified</div>
                </div>
                
                <div class='content'>
                    <h1 style='color: #1e293b; margin-top: 0;'>Welcome to AcademixSuite!</h1>
                    
                    <div class='welcome-text'>
                        Your school <span class='school-name'>{$escapedSchoolName}</span> has been successfully provisioned and is ready to use.
                    </div>
                    
                    {$trialMessage}
                    {$databaseInfo}
                    
                    <div class='credentials-box'>
                        <h3 style='color: #1e293b; margin-top: 0; margin-bottom: 20px;'>Your Login Credentials</h3>
                        
                        <div class='credential-item'>
                            <div class='credential-label'>Email Address</div>
                            <div class='credential-value'>{$escapedAdminEmail}</div>
                        </div>
                        
                        <div class='credential-item'>
                            <div class='credential-label'>Password</div>
                            <div class='credential-value'>{$escapedAdminPassword}</div>
                        </div>
                        
                        <div class='credential-item'>
                            <div class='credential-label'>School URL</div>
                            <div class='credential-value'>{$escapedAppUrl}/tenant/{$escapedSchoolSlug}/login.php</div>
                        </div>
                        
                        <div style='text-align: center; margin-top: 25px;'>
                            <a href='{$escapedAppUrl}/tenant/{$escapedSchoolSlug}/login.php' class='login-btn'>
                                🚀 Launch Your School Portal
                            </a>
                        </div>
                    </div>
                    
                    <div class='features-grid'>
                        <div class='feature'>
                            <div class='feature-icon'>📊</div>
                            <div class='feature-text'>Complete Database</div>
                        </div>
                        <div class='feature'>
                            <div class='feature-icon'>👨‍🎓</div>
                            <div class='feature-text'>Student Management</div>
                        </div>
                        <div class='feature'>
                            <div class='feature-icon'>📚</div>
                            <div class='feature-text'>Class & Attendance</div>
                        </div>
                        <div class='feature'>
                            <div class='feature-icon'>💰</div>
                            <div class='feature-text'>Fee Management</div>
                        </div>
                    </div>
                    
                    <div class='security-note'>
                        <strong>🔒 Security First:</strong> Your school has a separate, isolated database for maximum security and privacy.
                    </div>
                    
                    <div class='important-note'>
                        <strong>💡 Getting Started Tips:</strong>
                        <ul style='margin: 10px 0 0 0; padding-left: 20px;'>
                            <li>Explore all features during your trial period</li>
                            <li>Add your school staff and teachers</li>
                            <li>Import or add student data</li>
                            <li>Set up your academic calendar</li>
                            <li>Customize your school settings</li>
                        </ul>
                    </div>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <div style='font-size: 14px; color: #64748b; margin-bottom: 10px;'>Need help getting started?</div>
                        <a href='mailto:support@academixsuite.com' style='color: #3B82F6; text-decoration: none; font-weight: 500;'>
                            📧 Contact Support
                        </a>
                    </div>
                </div>
                
                <div class='footer'>
                    <p>This is an automated message from AcademixSuite.</p>
                    <p>Please do not reply to this email.</p>
                    <p style='font-size: 11px; margin-top: 10px; color: #94a3b8;'>
                        © " . date('Y') . " AcademixSuite. All rights reserved.<br>
                        If you did not request this account, please contact us immediately.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";

        // Log emails to database
        logEmail($escapedAdminEmail, $adminSubject, $adminMessage, 'welcome');

        if ($superAdmin) {
            $superAdminEmail = htmlspecialchars($superAdmin['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $superAdminSubject = "📚 New School Provisioned: {$escapedSchoolName}";
            logEmail($superAdminEmail, $superAdminSubject, $adminMessage, 'provisioning');
        }

        error_log("Provisioning emails logged to database - Trial: {$trialDays} days remaining");
        return true;
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log email to database
 */
function logEmail($to, $subject, $message, $template)
{
    try {
        $db = Database::getPlatformConnection();
        
        try {
            // Try normal insert
            $stmt = $db->prepare("
                INSERT INTO email_logs 
                (to_email, subject, template, status, created_at) 
                VALUES (?, ?, ?, 'sent', NOW())
            ");
            $stmt->execute([$to, $subject, $template]);
        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $logIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM email_logs");
                $nextLogId = $logIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                $stmt = $db->prepare("
                    INSERT INTO email_logs 
                    (id, to_email, subject, template, status, created_at) 
                    VALUES (?, ?, ?, ?, 'sent', NOW())
                ");
                $stmt->execute([$nextLogId, $to, $subject, $template]);
            } else {
                throw $e;
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to log email: " . $e->getMessage());
        return false;
    }
}

/**
 * Log provisioning activity
 */
function logProvisioningActivity($schoolId, $adminId, $schoolName)
{
    try {
        $db = Database::getPlatformConnection();

        // Log to audit_logs
        try {
            $auditStmt = $db->prepare("
                INSERT INTO audit_logs 
                (school_id, user_id, user_type, event, auditable_type, auditable_id, 
                 new_values, url, ip_address, user_agent, created_at) 
                VALUES (?, ?, 'super_admin', 'school_created', 'schools', ?, 
                       ?, ?, ?, ?, NOW())
            ");

            $newValues = json_encode([
                'name' => $schoolName, 
                'status' => 'trial',
                'database_created' => true,
                'database_name' => 'school_' . $schoolId
            ]);
            $auditStmt->execute([
                $schoolId,
                $adminId,
                $schoolId,
                $newValues,
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $auditIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM audit_logs");
                $nextAuditId = $auditIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];
                
                $auditStmt = $db->prepare("
                    INSERT INTO audit_logs 
                    (id, school_id, user_id, user_type, event, auditable_type, auditable_id, 
                     new_values, url, ip_address, user_agent, created_at) 
                    VALUES (?, ?, ?, 'super_admin', 'school_created', 'schools', ?, 
                           ?, ?, ?, ?, NOW())
                ");
                
                $auditStmt->execute([
                    $nextAuditId,
                    $schoolId,
                    $adminId,
                    $schoolId,
                    $newValues,
                    $_SERVER['REQUEST_URI'] ?? '',
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    $_SERVER['HTTP_USER_AGENT'] ?? ''
                ]);
            } else {
                throw $e;
            }
        }

        return true;
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
        return false;
    }
}