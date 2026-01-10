<?php
/**
 * Process School Provisioning - Backend Processing
 * Simplified version without transactions
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set error log
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/provision_errors.log');

// Buffer output
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
    'debug' => []
];

try {
    error_log("=== PROVISION SCRIPT STARTED ===");
    
    // Load autoload
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("autoload.php not found at: " . $autoloadPath);
    }
    
    require_once $autoloadPath;
    error_log("Autoload loaded");
    
    // Check auth
    $auth = new Auth();
    if (!$auth->isLoggedIn('super_admin')) {
        throw new Exception("Unauthorized: Please log in as super admin.");
    }
    
    // Validate CSRF - temporarily disabled for testing
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        throw new Exception("Invalid CSRF token.");
    }
    
    // Get database
    $db = Database::getPlatformConnection();
    error_log("Database connected");
    
    // Validate required fields
    $required = ['school_name', 'school_email', 'admin_name', 'admin_email', 'admin_password'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $missing[] = str_replace('_', ' ', $field);
        }
    }
    
    if (!empty($missing)) {
        throw new Exception("Missing required fields: " . implode(', ', $missing));
    }
    
    // Generate slug
    $slug = Utils::generateSlug($_POST['school_name'], 'schools', 'slug');
    $stmt = $db->prepare("SELECT id FROM schools WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        $slug = $slug . '-' . time();
    }
    
    error_log("Generated slug: " . $slug);
    
    // Get plan
    $planId = $_POST['plan_id'] ?? 2; // Default to Growth plan
    $stmt = $db->prepare("SELECT id, name, price_monthly FROM plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    
    if (!$plan) {
        throw new Exception("Plan not found.");
    }
    
    // Prepare school data
    $schoolData = [
        'uuid' => Utils::generateUuid(),
        'name' => trim($_POST['school_name']),
        'slug' => $slug,
        'email' => trim($_POST['school_email']),
        'phone' => $_POST['phone'] ?? '',
        'address' => $_POST['address'] ?? '',
        'city' => $_POST['city'] ?? '',
        'state' => $_POST['state'] ?? '',
        'country' => $_POST['country'] ?? 'Nigeria',
        'database_name' => DB_SCHOOL_PREFIX . 'temp_' . uniqid(),
        'plan_id' => $planId,
        'status' => 'trial',
        'trial_ends_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Insert school WITHOUT TRANSACTION
    $columns = implode(', ', array_keys($schoolData));
    $placeholders = implode(', ', array_fill(0, count($schoolData), '?'));
    $sql = "INSERT INTO schools ($columns) VALUES ($placeholders)";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($schoolData));
    $schoolId = $db->lastInsertId();
    
    if (!$schoolId) {
        throw new Exception("Failed to create school record.");
    }
    
    error_log("School inserted with ID: " . $schoolId);
    $response['debug']['school_id'] = $schoolId;
    
    // Update database name with school ID
    $newDatabaseName = DB_SCHOOL_PREFIX . $schoolId;
    $updateStmt = $db->prepare("UPDATE schools SET database_name = ? WHERE id = ?");
    $updateStmt->execute([$newDatabaseName, $schoolId]);
    $response['debug']['database_name'] = $newDatabaseName;
    
    // Handle logo upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        error_log("Processing logo upload for school ID: " . $schoolId);
        
        try {
            // Create directories first
            $tenant = new Tenant();
            $dirsCreated = $tenant->createSchoolDirectories($schoolId);
            
            if ($dirsCreated) {
                // CORRECT PATH: assets/uploads/schools/{school_id}/logo/
                $uploadBase = realpath(__DIR__ . '/../../../../') . '/assets/uploads/schools/' . $schoolId . '/';
                $logoDir = $uploadBase . 'logo/';
                
                $response['debug']['upload_base'] = $uploadBase;
                $response['debug']['logo_dir'] = $logoDir;
                error_log("Logo directory: " . $logoDir);
                
                // Verify directory exists
                if (!file_exists($logoDir)) {
                    error_log("Logo directory doesn't exist, creating...");
                    if (!mkdir($logoDir, 0755, true)) {
                        throw new Exception("Failed to create logo directory");
                    }
                }
                
                if (file_exists($logoDir) && is_writable($logoDir)) {
                    // Generate filename
                    $fileExt = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $fileName = 'logo-' . $slug . '.' . $fileExt;
                    $filePath = $logoDir . $fileName;
                    
                    // Validate file
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                    if (in_array($_FILES['logo']['type'], $allowedTypes) && 
                        $_FILES['logo']['size'] <= 5 * 1024 * 1024) {
                        
                        // Move uploaded file
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $filePath)) {
                            // Store relative path
                            $relativePath = 'assets/uploads/schools/' . $schoolId . '/logo/' . $fileName;
                            $updateLogoStmt = $db->prepare("UPDATE schools SET logo_path = ? WHERE id = ?");
                            $updateLogoStmt->execute([$relativePath, $schoolId]);
                            
                            error_log("Logo uploaded successfully: " . $relativePath);
                            $response['debug']['logo_uploaded'] = true;
                            $response['debug']['logo_path'] = $relativePath;
                        } else {
                            $error = error_get_last();
                            error_log("Failed to move uploaded file: " . ($error['message'] ?? 'Unknown error'));
                        }
                    }
                } else {
                    error_log("Logo directory not writable: " . $logoDir);
                }
            }
        } catch (Exception $e) {
            // Don't fail provisioning because of logo upload error
            error_log("Logo upload error (non-fatal): " . $e->getMessage());
            $response['debug']['logo_upload_error'] = $e->getMessage();
        }
    } else {
        error_log("No logo uploaded or upload error for school ID: " . $schoolId);
        $response['debug']['logo_upload'] = 'No file uploaded';
    }
    
    // Create school database
    $tenant = new Tenant();
    $adminData = [
        'id' => $schoolId,
        'admin_name' => trim($_POST['admin_name']),
        'admin_email' => trim($_POST['admin_email']),
        'admin_phone' => $_POST['admin_phone'] ?? '',
        'admin_password' => $_POST['admin_password']
    ];
    
    error_log("Creating school database...");
    $result = $tenant->createSchoolDatabase($adminData);
    $response['debug']['database_creation'] = $result;
    
    if (!$result['success']) {
        throw new Exception("Database creation failed: " . $result['message']);
    }
    
    error_log("School database created");
    
    // Create admin record
    try {
        $adminStmt = $db->prepare("
            INSERT INTO school_admins 
            (school_id, user_id, email, role, permissions, is_active, created_at) 
            VALUES (?, ?, ?, ?, '[\"*\"]', 1, NOW())
        ");
        $adminStmt->execute([
            $schoolId,
            $result['admin_user_id'] ?? 1,
            trim($_POST['admin_email']),
            $_POST['admin_role'] ?? 'owner'
        ]);
        $response['debug']['admin_created'] = true;
        error_log("Admin record created");
    } catch (Exception $e) {
        error_log("Admin creation error (non-fatal): " . $e->getMessage());
        $response['debug']['admin_creation_error'] = $e->getMessage();
        // Don't fail provisioning because of admin record
    }
    
    // Create subscription
    try {
        $billingCycle = $_POST['billing_cycle'] ?? 'monthly';
        $amount = $plan['price_monthly'] ?? 0.00;
        
        if ($billingCycle === 'yearly') {
            $amount = $amount * 12 * 0.85; // 15% discount
        }
        
        $subStmt = $db->prepare("
            INSERT INTO subscriptions 
            (school_id, plan_id, status, billing_cycle, amount, currency, 
             current_period_start, current_period_end, created_at) 
            VALUES (?, ?, 'pending', ?, ?, 'NGN', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())
        ");
        $subStmt->execute([
            $schoolId,
            $planId,
            $billingCycle,
            $amount
        ]);
        $response['debug']['subscription_created'] = true;
        error_log("Subscription created");
    } catch (Exception $e) {
        error_log("Subscription creation error (non-fatal): " . $e->getMessage());
        $response['debug']['subscription_error'] = $e->getMessage();
        // Don't fail provisioning because of subscription
    }
    
    // Success - NO TRANSACTION TO COMMIT
    $schoolUrl = APP_URL . "/school/" . $slug;
    $response['success'] = true;
    $response['message'] = "School '{$_POST['school_name']}' has been successfully provisioned!";
    $response['school_slug'] = $slug;
    $response['admin_email'] = $_POST['admin_email'];
    $response['school_id'] = $schoolId;
    $response['school_url'] = $schoolUrl;
    
    error_log("=== PROVISIONING SUCCESSFUL ===");
    error_log("School ID: " . $schoolId);
    error_log("School Name: " . $_POST['school_name']);
    error_log("School Slug: " . $slug);
    
} catch (Exception $e) {
    // Log error
    error_log("=== PROVISIONING ERROR ===");
    error_log("Error: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    
    // Clean output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // NO TRANSACTION ROLLBACK NEEDED
    $response['debug']['no_transaction_used'] = true;
    
    $response['success'] = false;
    $response['message'] = 'Failed to provision school: ' . $e->getMessage();
    
    http_response_code(500);
}

// Clean output and send JSON
while (ob_get_level() > 0) {
    ob_end_clean();
}

echo json_encode($response, JSON_PRETTY_PRINT);
exit;