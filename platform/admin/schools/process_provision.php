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
    'email_notifications' => [
        'welcome_email_sent' => false,
        'invoice_email_sent' => false,
        'platform_notification_sent' => false,
        'email_queue_ids' => [],
        'errors' => []
    ],
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

    $requestedSlug = trim((string)($_POST['school_slug'] ?? ''));
    $slug = generateSlug($requestedSlug !== '' ? $requestedSlug : $_POST['name']);
    if ($slug === '') {
        $slug = 'school-' . time();
    }

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

            // SECURITY: pin extension to verified MIME; never to user-supplied filename.
            $mimeToExt = [
                'image/jpeg' => 'jpg',
                'image/jpg'  => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];
            $fileType = mime_content_type($_FILES['logo_path']['tmp_name']);

            if (isset($mimeToExt[$fileType]) && $_FILES['logo_path']['size'] <= 5 * 1024 * 1024) {
                $fileExt = $mimeToExt[$fileType];
                $fileName = 'logo-' . preg_replace('/[^a-z0-9_-]/i', '', $slug) . '.' . $fileExt;
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

                // Prepare data for database creation – now includes address fields for campus
                $adminEmail = trim($_POST['admin_email']);
                $adminPassword = $_POST['admin_password'];
                $schoolName = trim($_POST['name']);

                $adminData = [
                    'id' => $schoolId,
                    'name' => $adminName,
                    'admin_name' => $adminName,
                    'admin_email' => $adminEmail,
                    'admin_phone' => $adminPhone,
                    'admin_password' => $adminPassword,
                    // Campus data (used by Tenant to create default campus)
                    'school_name' => $_POST['name'],
                    'address'    => $_POST['address'] ?? '',
                    'city'       => $_POST['city'] ?? '',
                    'state'      => $_POST['state'] ?? '',
                    'country'    => $_POST['country'] ?? 'Nigeria',
                    'phone'      => $_POST['phone'] ?? '',
                    'email'      => $_POST['email'] ?? ''
                ];

                // Create the complete database with all tables, default campus, and admin user
                $databaseResult = $tenant->createSchoolDatabase($adminData);

                if (!$databaseResult['success']) {
                    throw new Exception("Failed to create school database: " . ($databaseResult['message'] ?? 'Unknown error'));
                }

                // Get the database name and admin user ID from the result
                $newDatabaseName = $databaseResult['database_name'] ?? 'school_' . $schoolId;
                $adminUserIdFromSchool = $databaseResult['admin_user_id'] ?? null;

                // Update database name in schools table
                $updateStmt = $db->prepare("UPDATE schools SET database_name = ? WHERE id = ?");
                $updateStmt->execute([$newDatabaseName, $schoolId]);

                error_log("Database created: " . $newDatabaseName);
                error_log("Admin user ID in school DB: " . ($adminUserIdFromSchool ?? 'unknown'));
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

                // Test connection to new database (optional, for logging)
                try {
                    $testConn = Database::getSchoolConnection($newDatabaseName);
                    $testStmt = $testConn->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = DATABASE()");
                    $tableCount = $testStmt->fetch()['table_count'];
                    error_log("Successfully connected to school database. Table count: " . $tableCount);
                    $response['debug']['database_test'] = ['connected' => true, 'table_count' => $tableCount];
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
            // Use the admin user ID from the school database (returned by Tenant)
            $adminUserIdFromSchool = $databaseResult['admin_user_id'] ?? 1;

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

            error_log("Admin record created in platform with ID: " . $platformAdminId . ", role: " . $adminRole);

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
        $invoiceAccessToken = function_exists('generate_invoice_access_token') ? generate_invoice_access_token() : bin2hex(random_bytes(32));

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
            $checkAccessTokenColumnStmt = $db->query("SHOW COLUMNS FROM invoices LIKE 'access_token'");
            $hasAccessToken = $checkAccessTokenColumnStmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasAccessToken = false;
            error_log("Error checking access_token column: " . $e->getMessage());
        }

        try {
            if ($hasIsTrial) {
                // Try insert WITH is_trial
                $invoiceStmt = $db->prepare("
                    INSERT INTO invoices 
                    (school_id, subscription_id, invoice_number" . ($hasAccessToken ? ", access_token" : "") . ", description, amount, tax, total_amount, 
                     currency, status, due_date, start_date, end_date, is_trial, created_at) 
                    VALUES (?, ?, ?" . ($hasAccessToken ? ", ?" : "") . ", ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                           NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), 1, NOW())
                ");

                $params = [
                    $schoolId,
                    $subscriptionId,
                    $invoiceNumber
                ];
                if ($hasAccessToken) {
                    $params[] = $invoiceAccessToken;
                }
                $invoiceStmt->execute(array_merge($params, [
                    "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                    $amount,
                    $amount
                ]));
            } else {
                // Try insert WITHOUT is_trial
                $invoiceStmt = $db->prepare("
                    INSERT INTO invoices 
                    (school_id, subscription_id, invoice_number" . ($hasAccessToken ? ", access_token" : "") . ", description, amount, tax, total_amount, 
                     currency, status, due_date, start_date, end_date, created_at) 
                    VALUES (?, ?, ?" . ($hasAccessToken ? ", ?" : "") . ", ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                           NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                ");

                $params = [
                    $schoolId,
                    $subscriptionId,
                    $invoiceNumber
                ];
                if ($hasAccessToken) {
                    $params[] = $invoiceAccessToken;
                }
                $invoiceStmt->execute(array_merge($params, [
                    "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                    $amount,
                    $amount
                ]));
            }

        } catch (Exception $e) {
            // If AUTO_INCREMENT fails, insert with manual ID
            if (strpos($e->getMessage(), "doesn't have a default value") !== false) {
                $invIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM invoices");
                $nextInvId = $invIdStmt->fetch(PDO::FETCH_ASSOC)['next_id'];

                if ($hasIsTrial) {
                    $invoiceStmt = $db->prepare("
                        INSERT INTO invoices 
                        (id, school_id, subscription_id, invoice_number" . ($hasAccessToken ? ", access_token" : "") . ", description, amount, tax, total_amount, 
                         currency, status, due_date, start_date, end_date, is_trial, created_at) 
                        VALUES (?, ?, ?, ?" . ($hasAccessToken ? ", ?" : "") . ", ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                               NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), 1, NOW())
                    ");

                    $params = [
                        $nextInvId,
                        $schoolId,
                        $subscriptionId,
                        $invoiceNumber
                    ];
                    if ($hasAccessToken) {
                        $params[] = $invoiceAccessToken;
                    }
                    $invoiceStmt->execute(array_merge($params, [
                        "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                        $amount,
                        $amount
                    ]));
                } else {
                    $invoiceStmt = $db->prepare("
                        INSERT INTO invoices 
                        (id, school_id, subscription_id, invoice_number" . ($hasAccessToken ? ", access_token" : "") . ", description, amount, tax, total_amount, 
                         currency, status, due_date, start_date, end_date, created_at) 
                        VALUES (?, ?, ?, ?" . ($hasAccessToken ? ", ?" : "") . ", ?, ?, 0, ?, 'NGN', 'trial', DATE_ADD(NOW(), INTERVAL 30 DAY), 
                               NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), NOW())
                    ");

                    $params = [
                        $nextInvId,
                        $schoolId,
                        $subscriptionId,
                        $invoiceNumber
                    ];
                    if ($hasAccessToken) {
                        $params[] = $invoiceAccessToken;
                    }
                    $invoiceStmt->execute(array_merge($params, [
                        "Trial subscription for " . $plan['name'] . " plan (" . $billingCycle . ") - Free 7-day trial",
                        $amount,
                        $amount
                    ]));
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
        $emailResult = sendProvisioningEmails(
            $schoolId,
            $slug,
            $_POST['admin_email'],
            $_POST['admin_password'],
            $schoolData['name'],
            $adminName,
            $trialEndsAt,
            $plan['name'],
            [
                'database_name' => $newDatabaseName ?? 'school_' . $schoolId,
                'database_host' => defined('DB_HOST') ? DB_HOST : 'localhost',
                'tables_created' => $response['debug']['database_test']['table_count'] ?? 'unknown'
            ],
            $superAdmin['email'] ?? null
        );

        $response['email_notifications'] = $emailResult;

        if (empty($emailResult['success'])) {
            error_log("Warning: Email sending failed, but school was created successfully");
        }

        // Log provisioning activity (outside transaction)
        logProvisioningActivity($schoolId, $superAdmin['id'], $_POST['name']);

        // Prepare success response
        $schoolUrl = function_exists('school_portal_url')
            ? school_portal_url($slug, 'login.php', true)
            : ((defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost') . "/tenant/" . $slug . "/login.php");

        $response['success'] = true;
        $response['message'] = "School '{$_POST['name']}' has been successfully provisioned with a {$trialPeriod}-day free trial!";
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
            'admin_user_id' => $adminUserIdFromSchool ?? 'unknown',
            'campus_code' => $campusCode ?? 'MAIN001'
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
        'line' => $e->getLine()
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
function sendProvisioningEmails($schoolId, $schoolSlug, $adminEmail, $adminPassword, $schoolName, $adminName = null, $trialEndsAt = null, $planName = 'Starter', array $databaseInfo = [], $platformNotifyEmail = null)
{
    $result = [
        'success' => false,
        'welcome_email_sent' => false,
        'invoice_email_sent' => false,
        'platform_notification_sent' => false,
        'email_queue_ids' => [],
        'errors' => []
    ];

    try {
        $db = Database::getPlatformConnection();
        $emailQueue = new EmailQueueManager();
        $emailQueue->setSchoolContext($schoolId);
        $emailTemplate = new EmailTemplate();

        // Get school details
        $stmt = $db->prepare("SELECT name, email, database_name FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();

        if (!$school) {
            $result['errors'][] = 'School not found for email sending';
            return $result;
        }

        // Get invoice details
        $stmt = $db->prepare("SELECT * FROM invoices WHERE school_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$schoolId]);
        $invoice = $stmt->fetch();

        // Calculate trial info
        if (!$trialEndsAt) {
            $trialEndsAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        }
        $trialDays = ceil((strtotime($trialEndsAt) - time()) / (60 * 60 * 24));

        $loginUrl = function_exists('school_portal_url')
            ? school_portal_url($schoolSlug, 'login.php', true)
            : ((defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost') . "/tenant/" . $schoolSlug . "/login.php");

        // 1. Queue Welcome Email
        $welcomeSubject = "Welcome to AcademixSuite - Your School Portal Is Ready";
        $welcomeData = [
            'admin_name' => $adminName ?? strstr($adminEmail, '@', true),
            'school_name' => $schoolName,
            'login_url' => $loginUrl,
            'credentials' => [
                'email' => $adminEmail,
                'password' => $adminPassword
            ],
            'trial_info' => [
                'trial_days' => $trialDays,
                'trial_ends_at' => $trialEndsAt,
                'plan' => $planName
            ]
        ];

        $welcomeHtml = $emailTemplate->getTemplate('welcome', $welcomeData);
        $welcomeQueueResult = $emailQueue->addToQueue($adminEmail, $welcomeSubject, $welcomeHtml, strip_tags($welcomeHtml), 1, 'welcome');

        // Trigger immediate send for welcome email
        if ($welcomeQueueResult['success']) {
            $result['email_queue_ids'][] = $welcomeQueueResult['id'];
            $sendResult = $emailQueue->sendEmailById($welcomeQueueResult['id']);
            $result['welcome_email_sent'] = !empty($sendResult['success']);
            if (empty($sendResult['success'])) {
                $result['errors'][] = 'Welcome email: ' . ($sendResult['error'] ?? 'send failed');
            }
        } else {
            $result['errors'][] = 'Welcome email queue: ' . ($welcomeQueueResult['error'] ?? 'queue failed');
        }

        // 2. Queue Invoice Email (if invoice exists)
        if ($invoice) {
            $invoiceSubject = "Your AcademixSuite Invoice - " . $invoice['invoice_number'];
            $invoiceData = [
                'school_name' => $schoolName,
                'invoice_number' => $invoice['invoice_number'],
                'amount' => number_format($invoice['total_amount'], 2),
                'currency' => $invoice['currency'] ?? 'NGN',
                'due_date' => date('F j, Y', strtotime($invoice['due_date'])),
                'description' => $invoice['description'],
                'status' => $invoice['status']
            ];

            $invoiceHtml = $emailTemplate->getTemplate('invoice', $invoiceData);
            $invoiceQueueResult = $emailQueue->addToQueue($adminEmail, $invoiceSubject, $invoiceHtml, strip_tags($invoiceHtml), 2, 'invoice');

            // Trigger immediate send for invoice
            if ($invoiceQueueResult['success']) {
                $result['email_queue_ids'][] = $invoiceQueueResult['id'];
                $sendResult = $emailQueue->sendEmailById($invoiceQueueResult['id']);
                $result['invoice_email_sent'] = !empty($sendResult['success']);
                if (empty($sendResult['success'])) {
                    $result['errors'][] = 'Invoice email: ' . ($sendResult['error'] ?? 'send failed');
                }
            } else {
                $result['errors'][] = 'Invoice email queue: ' . ($invoiceQueueResult['error'] ?? 'queue failed');
            }
        }

        if (!empty($platformNotifyEmail) && filter_var($platformNotifyEmail, FILTER_VALIDATE_EMAIL)) {
            $platformSubject = "New School Provisioned - " . $schoolName;
            $platformHtml = $emailTemplate->getTemplate('provisioning_notification', [
                'school_name' => $schoolName,
                'admin_email' => $adminEmail,
                'school_id' => $schoolId,
                'database_info' => $databaseInfo
            ]);
            $platformQueueResult = $emailQueue->addToQueue($platformNotifyEmail, $platformSubject, $platformHtml, strip_tags($platformHtml), 2, 'provisioning_notification');
            if ($platformQueueResult['success']) {
                $result['email_queue_ids'][] = $platformQueueResult['id'];
                $sendResult = $emailQueue->sendEmailById($platformQueueResult['id']);
                $result['platform_notification_sent'] = !empty($sendResult['success']);
                if (empty($sendResult['success'])) {
                    $result['errors'][] = 'Platform notification: ' . ($sendResult['error'] ?? 'send failed');
                }
            } else {
                $result['errors'][] = 'Platform notification queue: ' . ($platformQueueResult['error'] ?? 'queue failed');
            }
        }

        error_log("Provisioning emails (Welcome, Invoice) queued successfully for school ID: " . $schoolId);
        $result['success'] = $result['welcome_email_sent'] || $result['invoice_email_sent'] || $result['platform_notification_sent'];
        return $result;
    } catch (Exception $e) {
        error_log("Error queuing provisioning emails: " . $e->getMessage());
        $result['errors'][] = $e->getMessage();
        return $result;
    }
}

/**
 * Log email to database - Legacy function maintained for compatibility
 */
function logEmail($to, $subject, $message, $template)
{
    try {
        $db = Database::getPlatformConnection();
        $stmt = $db->prepare("INSERT INTO email_logs (to_email, subject, template, status, created_at) VALUES (?, ?, ?, 'queued', NOW())");
        $stmt->execute([$to, $subject, $template]);
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
