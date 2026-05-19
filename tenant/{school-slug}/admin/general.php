<?php
/**
 * School Management Hub
 * Comprehensive school management page handling multiple aspects of school operations
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_management.log');

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $message = sprintf(
        "[%s] GENERAL_FATAL: %s in %s on line %s\n",
        date('Y-m-d H:i:s'),
        $error['message'] ?? 'Unknown fatal error',
        $error['file'] ?? 'unknown file',
        $error['line'] ?? 'unknown'
    );

    error_log(trim($message));
    @file_put_contents(__DIR__ . '/../../../logs/general_fatal.log', $message, FILE_APPEND);
});

error_log("=== SCHOOL MANAGEMENT HUB START ===");

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get school slug from GLOBALS (set by router)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug");
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Get school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Verify authentication
if (!isset($_SESSION['school_auth']) || 
    $_SESSION['school_auth']['school_slug'] !== $schoolSlug) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Verify admin access
$schoolAuth = $_SESSION['school_auth'];

$currentPage = basename(__FILE__);
$userId = $schoolAuth['user_id'] ?? 0;
if ($schoolAuth['user_type'] !== 'admin') {
    error_log("ERROR: Non-admin user attempted access");
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. Admin privileges required.');
}

// Load configuration
try {
    require_once __DIR__ . '/../../../includes/autoload.php';
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading autoload.php: " . $e->getMessage());
    http_response_code(500);
    die("System configuration error. Please contact support.");
}

// Try to load SchoolActionManager, create fallback if not exists
$actionManagerPath = __DIR__ . '/../../../includes/SchoolActionManager.php';
if (file_exists($actionManagerPath)) {
    try {
        require_once $actionManagerPath;
        $actionManagerExists = class_exists('SchoolActionManager');
    } catch (Throwable $e) {
        $actionManagerExists = false;
        error_log("SchoolActionManager failed to load: " . $e->getMessage());
    }
} else {
    $actionManagerExists = false;
    error_log("SchoolActionManager.php not found at: " . $actionManagerPath);
}

// Connect to platform database
$platformDb = null;
try {
    $platformDb = Database::getPlatformConnection();
    error_log("Platform database connection successful");
} catch (Exception $e) {
    error_log("ERROR connecting to platform database: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Define fallback SchoolActionManager if it doesn't exist
if (!$actionManagerExists) {
    class SchoolActionManager {
        private $platformDb;
        private $schoolDb;
        private $schoolId;
        private $schoolSlug;
        
        public function __construct($platformDb, $schoolDb, $schoolId, $schoolSlug, $userId = null) {
            $this->platformDb = $platformDb;
            $this->schoolDb = $schoolDb;
            $this->schoolId = $schoolId;
            $this->schoolSlug = $schoolSlug;
        }
        
        public function setAcademicYear($yearData, $userId) {
            try {
                if (!$this->schoolDb) {
                    throw new Exception("School database not connected");
                }
                
                // If this is set as default, remove default from other years
                if (!empty($yearData['is_default']) && $yearData['is_default'] == 1) {
                    $resetStmt = $this->schoolDb->prepare("
                        UPDATE academic_years 
                        SET is_default = 0 
                        WHERE school_id = ?
                    ");
                    $resetStmt->execute([$this->schoolId]);
                }
                
                // Insert new academic year
                $stmt = $this->schoolDb->prepare("
                    INSERT INTO academic_years (
                        school_id, name, start_date, end_date, 
                        is_default, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $this->schoolId,
                    $yearData['name'],
                    $yearData['start_date'],
                    $yearData['end_date'],
                    $yearData['is_default'] ?? 0,
                    $yearData['status'] ?? 'upcoming'
                ]);
                
                $yearId = $this->schoolDb->lastInsertId();
                
                // Create audit log in school database
                $this->createSchoolAuditLog([
                    'user_id' => $userId,
                    'user_type' => 'admin',
                    'action' => 'academic_year_created',
                    'entity_type' => 'academic_years',
                    'entity_id' => $yearId,
                    'new_values' => json_encode($yearData),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Academic year created successfully',
                    'year_id' => $yearId
                ];
                
            } catch (Exception $e) {
                error_log("Error in setAcademicYear: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Failed to create academic year: ' . $e->getMessage()
                ];
            }
        }
        
        public function changePassword($userId, $newPassword, $userType, $changedBy) {
            try {
                if (!$this->schoolDb) {
                    throw new Exception("School database not connected");
                }
                
                // Hash the password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update password
                $stmt = $this->schoolDb->prepare("
                    UPDATE users 
                    SET password = ?, 
                        updated_at = NOW(),
                        reset_token = NULL,
                        reset_token_expires = NULL
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$hashedPassword, $userId, $this->schoolId]);
                
                // Get user details for potential notification
                $userStmt = $this->schoolDb->prepare("
                    SELECT name, email 
                    FROM users 
                    WHERE id = ? AND school_id = ?
                ");
                $userStmt->execute([$userId, $this->schoolId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                
                // Create audit log in school database
                $this->createSchoolAuditLog([
                    'user_id' => $changedBy,
                    'user_type' => $userType,
                    'action' => 'password_changed',
                    'entity_type' => 'users',
                    'entity_id' => $userId,
                    'new_values' => json_encode(['password_updated' => true]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Password changed successfully',
                    'user_email' => $user['email'] ?? null
                ];
                
            } catch (Exception $e) {
                error_log("Error in changePassword: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'Failed to change password: ' . $e->getMessage()
                ];
            }
        }
        
        public function createPlatformAuditLog($data) {
            try {
                if (!$this->platformDb) {
                    return;
                }
                
                $stmt = $this->platformDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, event, auditable_type,
                        auditable_id, new_values, ip_address, user_agent, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $this->schoolId,
                    $_SESSION['school_auth']['user_id'] ?? null,
                    $_SESSION['school_auth']['user_type'] ?? 'admin',
                    $data['event'],
                    $data['auditable_type'],
                    $data['auditable_id'],
                    $data['new_values'],
                    $data['ip_address'] ?? null,
                    $data['user_agent'] ?? null
                ]);
                
            } catch (Exception $e) {
                error_log("Failed to create platform audit log: " . $e->getMessage());
            }
        }
        
        public function createSchoolAuditLog($data) {
            try {
                if (!$this->schoolDb) {
                    return;
                }
                
                $stmt = $this->schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $this->schoolId,
                    $data['user_id'],
                    $data['user_type'],
                    $data['action'],
                    $data['entity_type'],
                    $data['entity_id'],
                    $data['new_values'],
                    $data['ip_address'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);
                
            } catch (Exception $e) {
                error_log("Failed to create school audit log: " . $e->getMessage());
            }
        }
    }
}

// Initialize action manager
try {
    $managerReflection = new ReflectionClass('SchoolActionManager');
    $constructor = $managerReflection->getConstructor();
    if ($constructor && $constructor->getNumberOfParameters() >= 5) {
        $actionManager = new SchoolActionManager($platformDb, $schoolDb, $school['id'], $schoolSlug, $userId);
    } else {
        $actionManager = new SchoolActionManager($platformDb, $schoolDb, $school['id'], $schoolSlug);
    }
} catch (Throwable $e) {
    error_log("Failed to initialize SchoolActionManager: " . $e->getMessage());
    $actionManager = null;
}

// Initialize variables
$schoolDetails = [];
$schoolSettings = [];
$academicYears = [];
$academicTerms = [];
$recentAnnouncements = [];
$recentActivities = [];
$subscriptionInfo = [];
$storageUsage = [];
$countries = [
    'Nigeria', 'Ghana', 'Kenya', 'South Africa', 'Egypt', 
    'Morocco', 'Tunisia', 'Rwanda', 'Uganda', 'Tanzania',
    'United States', 'United Kingdom', 'Canada', 'Australia'
];
$currencies = ['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'GBP', 'EUR'];
$timezones = [
    'Africa/Lagos', 'Africa/Accra', 'Africa/Nairobi', 'Africa/Johannesburg',
    'Africa/Cairo', 'Europe/London', 'America/New_York', 'America/Chicago',
    'America/Denver', 'America/Los_Angeles', 'Asia/Dubai'
];
$languages = ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic', 'sw' => 'Swahili'];

$adminUser = [
    'name' => 'Administrator',
    'role_name' => 'Admin',
    'profile_photo' => ''
];
$message = '';
$error = '';
$success = false;
$activeTab = $_GET['tab'] ?? 'general';

// Fetch all necessary data
try {
    // Fetch school details from platform database
    $stmt = $platformDb->prepare("
        SELECT s.*, p.name as plan_name, p.price_monthly, p.features as plan_features
        FROM schools s
        LEFT JOIN plans p ON s.plan_id = p.id
        WHERE s.id = ?
    ");
    $stmt->execute([$school['id']]);
    $schoolDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$schoolDetails) {
        $schoolDetails = $school;
    }
    
    // Parse JSON fields safely
    if (!empty($schoolDetails['facilities'])) {
        $schoolDetails['facilities'] = json_decode($schoolDetails['facilities'], true) ?: [];
    }
    if (!empty($schoolDetails['social_links'])) {
        $schoolDetails['social_links'] = json_decode($schoolDetails['social_links'], true) ?: [];
    }
    if (!empty($schoolDetails['plan_features'])) {
        $schoolDetails['plan_features'] = json_decode($schoolDetails['plan_features'], true) ?: [];
    }
    if (!empty($schoolDetails['landing_programs'])) {
        $schoolDetails['landing_programs'] = json_decode($schoolDetails['landing_programs'], true) ?: [];
    }
    if (!empty($schoolDetails['landing_testimonials'])) {
        $schoolDetails['landing_testimonials'] = json_decode($schoolDetails['landing_testimonials'], true) ?: [];
    }
    
    // Fetch subscription info
    $subStmt = $platformDb->prepare("
        SELECT * FROM subscriptions 
        WHERE school_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $subStmt->execute([$school['id']]);
    $subscriptionInfo = $subStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    error_log("School details fetched successfully");
} catch (Exception $e) {
    error_log("Error fetching school details: " . $e->getMessage());
    $schoolDetails = $school;
}

// Fetch school-specific data
if ($schoolDb) {
    try {
        // Check if tables exist before querying
        $tablesExist = true;
        
        // Get settings
        try {
            $settingsStmt = $schoolDb->prepare("SELECT * FROM settings WHERE school_id = ?");
            $settingsStmt->execute([$school['id']]);
            $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($settingsRows as $row) {
                $schoolSettings[$row['key']] = $row['value'];
            }
        } catch (Exception $e) {
            error_log("Error fetching settings: " . $e->getMessage());
        }
        
        // Get admin user info
        try {
            $userStmt = $schoolDb->prepare("
                SELECT u.*, r.name as role_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ? AND u.school_id = ?
            ");
            $userStmt->execute([$userId, $school['id']]);
            $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($adminUserData) {
                $adminUser = [
                    'name' => $adminUserData['name'] ?? 'Admin User',
                    'role_name' => $adminUserData['role_name'] ?? 'Admin',
                    'profile_photo' => $adminUserData['profile_photo'] ?? '',
                    'email' => $adminUserData['email'] ?? '',
                    'phone' => $adminUserData['phone'] ?? ''
                ];
            }
        } catch (Exception $e) {
            error_log("Error fetching user info: " . $e->getMessage());
        }
        
        // Get academic years
        try {
            $yearStmt = $schoolDb->prepare("
                SELECT * FROM academic_years 
                WHERE school_id = ? 
                ORDER BY start_date DESC
            ");
            $yearStmt->execute([$school['id']]);
            $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Error fetching academic years: " . $e->getMessage());
            $academicYears = [];
        }
        
        // Get academic terms for current/default year
        if (!empty($academicYears)) {
            try {
                $defaultYear = array_filter($academicYears, function($year) {
                    return isset($year['is_default']) && $year['is_default'] == 1;
                });
                $currentYear = !empty($defaultYear) ? reset($defaultYear) : $academicYears[0];
                
                if (!empty($currentYear['id'])) {
                    $termStmt = $schoolDb->prepare("
                        SELECT * FROM academic_terms 
                        WHERE school_id = ? AND academic_year_id = ?
                        ORDER BY start_date
                    ");
                    $termStmt->execute([$school['id'], $currentYear['id']]);
                    $academicTerms = $termStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (Exception $e) {
                error_log("Error fetching academic terms: " . $e->getMessage());
                $academicTerms = [];
            }
        }
        
        // Get recent announcements
        try {
            $announceStmt = $schoolDb->prepare("
                SELECT a.*, u.name as created_by_name 
                FROM announcements a
                LEFT JOIN users u ON a.created_by = u.id
                WHERE a.school_id = ? AND a.is_published = 1
                ORDER BY a.created_at DESC
                LIMIT 5
            ");
            $announceStmt->execute([$school['id']]);
            $recentAnnouncements = $announceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Error fetching announcements: " . $e->getMessage());
            $recentAnnouncements = [];
        }
        
        // Get recent activities (audit logs)
        try {
            $auditStmt = $schoolDb->prepare("
                SELECT * FROM audit_logs 
                WHERE school_id = ? 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $auditStmt->execute([$school['id']]);
            $recentActivities = $auditStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Error fetching activities: " . $e->getMessage());
            $recentActivities = [];
        }
        
        // Get storage usage
        try {
            $storageStmt = $schoolDb->prepare("
                SELECT * FROM storage_usage 
                WHERE school_id = ?
            ");
            $storageStmt->execute([$school['id']]);
            $storageUsage = $storageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("Error fetching storage usage: " . $e->getMessage());
            $storageUsage = [];
        }
        
    } catch (Exception $e) {
        error_log("Error fetching school data: " . $e->getMessage());
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_general':
                // Handle general settings update
                $result = handleGeneralSettingsUpdate($_POST, $_FILES, $platformDb, $schoolDb, $school, $userId, $actionManager);
                break;
                
            case 'create_academic_year':
                // Handle academic year creation
                if (!$actionManager) {
                    throw new Exception("Action manager not initialized");
                }
                
                $yearData = [
                    'name' => $_POST['year_name'] ?? '',
                    'start_date' => $_POST['start_date'] ?? '',
                    'end_date' => $_POST['end_date'] ?? '',
                    'is_default' => isset($_POST['is_default']) ? 1 : 0,
                    'status' => $_POST['status'] ?? 'upcoming'
                ];
                
                // Validate required fields
                if (empty($yearData['name']) || empty($yearData['start_date']) || empty($yearData['end_date'])) {
                    throw new Exception("Year name, start date, and end date are required");
                }
                
                if (method_exists($actionManager, 'setAcademicYear')) {
                    $result = $actionManager->setAcademicYear($yearData, $userId);
                } elseif (method_exists($actionManager, 'createAcademicYear')) {
                    $result = $actionManager->createAcademicYear($yearData);
                } else {
                    throw new Exception("Academic year creation is not available");
                }
                break;
                
            case 'create_announcement':
                // Handle announcement creation
                if (!$schoolDb) {
                    throw new Exception("School database not connected");
                }
                $result = createAnnouncement($_POST, $schoolDb, $school['id'], $userId, $actionManager);
                break;
                
            case 'update_subscription':
                // Handle subscription update
                if (!$platformDb || !$actionManager) {
                    throw new Exception("Database connection required");
                }
                $result = updateSubscription($_POST, $platformDb, $school['id'], $userId, $actionManager);
                break;
                
            case 'create_backup':
                // Handle backup creation
                if (!$schoolDb || !$platformDb || !$actionManager) {
                    throw new Exception("Database connections required");
                }
                $result = createBackup($schoolDb, $platformDb, $school['id'], $userId, $actionManager);
                break;
                
            case 'change_password':
                // Handle password change
                if (!$actionManager) {
                    throw new Exception("Action manager not initialized");
                }
                
                // Validate password match
                if (empty($_POST['new_password']) || empty($_POST['confirm_password'])) {
                    throw new Exception("All password fields are required");
                }
                
                if ($_POST['new_password'] !== $_POST['confirm_password']) {
                    throw new Exception("New passwords do not match");
                }
                
                // Validate password strength
                if (strlen($_POST['new_password']) < 8) {
                    throw new Exception("Password must be at least 8 characters long");
                }
                
                if (method_exists($actionManager, 'changePassword')) {
                    $result = $actionManager->changePassword(
                        $userId,
                        $_POST['new_password'],
                        'admin',
                        $userId
                    );
                } else {
                    $result = changeAdminPasswordDirectly($schoolDb, $school['id'], $userId, $_POST['new_password']);
                }
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Unknown action'];
        }
        
        if (isset($result)) {
            if ($result['success']) {
                $success = true;
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
        }
        
        // Refresh data after update
        if ($success && $schoolDb) {
            try {
                if (in_array($action, ['create_academic_year', 'update_academic_year'])) {
                    // Refresh academic years
                    if (isset($yearStmt)) {
                        $yearStmt->execute([$school['id']]);
                        $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                } elseif ($action === 'create_announcement') {
                    // Refresh announcements
                    if (isset($announceStmt)) {
                        $announceStmt->execute([$school['id']]);
                        $recentAnnouncements = $announceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }
                }
            } catch (Exception $e) {
                error_log("Error refreshing data: " . $e->getMessage());
            }
        }
        
    } catch (Throwable $e) {
        $error = "Error processing request: " . $e->getMessage();
        error_log("Management hub error: " . $e->getMessage());
    }
}

error_log("=== SCHOOL MANAGEMENT HUB END ===");

// Helper functions
function handleGeneralSettingsUpdate($post, $files, $platformDb, $schoolDb, $school, $userId, $actionManager) {
    try {
        if (!$platformDb) {
            throw new Exception("Platform database not connected");
        }
        
        $platformDb->beginTransaction();
        
        // Collect and validate form data
        $updateData = [
            'name' => $post['school_name'] ?? $school['name'] ?? '',
            'email' => $post['school_email'] ?? $school['email'] ?? '',
            'phone' => $post['school_phone'] ?? $school['phone'] ?? '',
            'address' => $post['address'] ?? $school['address'] ?? '',
            'city' => $post['city'] ?? $school['city'] ?? '',
            'state' => $post['state'] ?? $school['state'] ?? '',
            'country' => $post['country'] ?? $school['country'] ?? 'Nigeria',
            'postal_code' => $post['postal_code'] ?? $school['postal_code'] ?? '',
            'website' => $post['website'] ?? '',
            'establishment_year' => $post['establishment_year'] ?? $school['establishment_year'] ?? null,
            'school_type' => $post['school_type'] ?? $school['school_type'] ?? 'secondary',
            'curriculum' => $post['curriculum'] ?? $school['curriculum'] ?? 'Nigerian',
            'principal_name' => $post['principal_name'] ?? $school['principal_name'] ?? '',
            'principal_message' => $post['principal_message'] ?? $school['principal_message'] ?? '',
            'mission_statement' => $post['mission_statement'] ?? $school['mission_statement'] ?? '',
            'vision_statement' => $post['vision_statement'] ?? $school['vision_statement'] ?? '',
            'description' => $post['description'] ?? $school['description'] ?? '',
            'timezone' => $post['timezone'] ?? $school['timezone'] ?? 'Africa/Lagos',
            'currency' => $post['currency'] ?? $school['currency'] ?? 'NGN',
            'language' => $post['language'] ?? $school['language'] ?? 'en'
        ];

        $landingPrograms = parseLandingRows($post['landing_programs'] ?? '', ['title', 'description']);
        $landingTestimonials = parseLandingRows($post['landing_testimonials'] ?? '', ['name', 'role', 'quote']);

        $landingData = [
            'landing_badge_text' => $post['landing_badge_text'] ?? $school['landing_badge_text'] ?? '',
            'landing_headline' => $post['landing_headline'] ?? $school['landing_headline'] ?? '',
            'landing_subheadline' => $post['landing_subheadline'] ?? $school['landing_subheadline'] ?? '',
            'landing_primary_cta_text' => $post['landing_primary_cta_text'] ?? $school['landing_primary_cta_text'] ?? '',
            'landing_secondary_cta_text' => $post['landing_secondary_cta_text'] ?? $school['landing_secondary_cta_text'] ?? '',
            'landing_intro_title' => $post['landing_intro_title'] ?? $school['landing_intro_title'] ?? '',
            'landing_intro_text' => $post['landing_intro_text'] ?? $school['landing_intro_text'] ?? '',
            'landing_highlight_title' => $post['landing_highlight_title'] ?? $school['landing_highlight_title'] ?? '',
            'landing_highlight_text' => $post['landing_highlight_text'] ?? $school['landing_highlight_text'] ?? '',
            'landing_cta_title' => $post['landing_cta_title'] ?? $school['landing_cta_title'] ?? '',
            'landing_cta_text' => $post['landing_cta_text'] ?? $school['landing_cta_text'] ?? '',
            'landing_programs' => json_encode($landingPrograms),
            'landing_testimonials' => json_encode($landingTestimonials),
            'landing_updated_at' => date('Y-m-d H:i:s')
        ];

        $updateData = array_merge($updateData, $landingData);
        
        // Handle social links
        $socialLinks = [
            'facebook' => $post['facebook'] ?? '',
            'twitter' => $post['twitter'] ?? '',
            'instagram' => $post['instagram'] ?? '',
            'linkedin' => $post['linkedin'] ?? '',
            'youtube' => $post['youtube'] ?? ''
        ];
        
        // Handle file uploads
        $uploadResult = handleFileUploads($files, $school['id']);
        if (!empty($uploadResult['logo'])) {
            $updateData['logo_path'] = $uploadResult['logo'];
        }
        if (!empty($uploadResult['favicon'])) {
            $updateData['favicon_path'] = $uploadResult['favicon'];
        }
        if (!empty($uploadResult['landing_hero_image'])) {
            $updateData['landing_hero_image'] = $uploadResult['landing_hero_image'];
        }
        if (!empty($uploadResult['landing_feature_image'])) {
            $updateData['landing_feature_image'] = $uploadResult['landing_feature_image'];
        }

        $schoolColumns = getPlatformTableColumns($platformDb, 'schools');
        $allowEmptyFields = [
            'landing_badge_text',
            'landing_headline',
            'landing_subheadline',
            'landing_primary_cta_text',
            'landing_secondary_cta_text',
            'landing_intro_title',
            'landing_intro_text',
            'landing_highlight_title',
            'landing_highlight_text',
            'landing_cta_title',
            'landing_cta_text'
        ];
        
        // Build update query
        $updateFields = [];
        $updateParams = [];
        foreach ($updateData as $field => $value) {
            if (in_array($field, $schoolColumns, true) && $value !== null && ($value !== '' || in_array($field, $allowEmptyFields, true))) {
                $updateFields[] = "`$field` = ?";
                $updateParams[] = $value;
            }
        }
        
        // Add social links
        if (in_array('social_links', $schoolColumns, true)) {
            $updateFields[] = "social_links = ?";
            $updateParams[] = json_encode($socialLinks);
        }
        
        // Add updated_at
        if (in_array('updated_at', $schoolColumns, true)) {
            $updateFields[] = "updated_at = NOW()";
        }
        
        // Add school ID at the end
        $updateParams[] = $school['id'];
        
        if (!empty($updateFields)) {
            // Execute update
            $updateStmt = $platformDb->prepare("
                UPDATE schools 
                SET " . implode(', ', $updateFields) . "
                WHERE id = ?
            ");
            $updateStmt->execute($updateParams);
        }
        
        // Update school database settings
        if ($schoolDb) {
            updateSchoolSettings($schoolDb, $school['id'], $updateData);
        }
        
        // Create audit log
        if ($actionManager && method_exists($actionManager, 'createPlatformAuditLog')) {
            $actionManager->createPlatformAuditLog([
                'event' => 'settings_updated',
                'auditable_type' => 'schools',
                'auditable_id' => $school['id'],
                'new_values' => json_encode(['updated_fields' => array_keys($updateData)]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }
        
        if ($platformDb->inTransaction()) {
            $platformDb->commit();
        }
        
        return [
            'success' => true,
            'message' => 'School settings updated successfully!'
        ];
        
    } catch (Exception $e) {
        if ($platformDb && $platformDb->inTransaction()) {
            $platformDb->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Failed to update settings: ' . $e->getMessage()
        ];
    }
}

function handleFileUploads($files, $schoolId) {
    $result = ['logo' => null, 'favicon' => null, 'landing_hero_image' => null, 'landing_feature_image' => null];
    $uploadBaseDir = __DIR__ . '/../../../assets/uploads/schools/' . $schoolId . '/';
    
    if (!is_dir($uploadBaseDir)) {
        if (!mkdir($uploadBaseDir, 0755, true)) {
            error_log("Failed to create upload directory: " . $uploadBaseDir);
            return $result;
        }
    }
    
    // Handle logo upload
    if (isset($files['logo']) && $files['logo']['error'] === UPLOAD_ERR_OK) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'svg'];
        $fileExtension = strtolower(pathinfo($files['logo']['name'], PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, $allowedExtensions)) {
            $fileName = 'logo_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadBaseDir . $fileName;
            
            if (move_uploaded_file($files['logo']['tmp_name'], $uploadPath)) {
                $result['logo'] = '/assets/uploads/schools/' . $schoolId . '/' . $fileName;
            } else {
                error_log("Failed to move uploaded logo file");
            }
        } else {
            error_log("Invalid file type for logo: " . $fileExtension);
        }
    }
    
    // Handle favicon upload
    if (isset($files['favicon']) && $files['favicon']['error'] === UPLOAD_ERR_OK) {
        $allowedExtensions = ['ico', 'png', 'jpg', 'jpeg', 'svg'];
        $fileExtension = strtolower(pathinfo($files['favicon']['name'], PATHINFO_EXTENSION));
        
        if (in_array($fileExtension, $allowedExtensions)) {
            $fileName = 'favicon_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadBaseDir . $fileName;
            
            if (move_uploaded_file($files['favicon']['tmp_name'], $uploadPath)) {
                $result['favicon'] = '/assets/uploads/schools/' . $schoolId . '/' . $fileName;
            } else {
                error_log("Failed to move uploaded favicon file");
            }
        } else {
            error_log("Invalid file type for favicon: " . $fileExtension);
        }
    }

    foreach ([
        'landing_hero_image' => 'landing_hero_' . time(),
        'landing_feature_image' => 'landing_feature_' . time()
    ] as $field => $prefix) {
        if (isset($files[$field]) && $files[$field]['error'] === UPLOAD_ERR_OK) {
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $fileExtension = strtolower(pathinfo($files[$field]['name'], PATHINFO_EXTENSION));

            if (in_array($fileExtension, $allowedExtensions, true) && ($files[$field]['size'] ?? 0) <= 5 * 1024 * 1024) {
                $fileName = $prefix . '.' . $fileExtension;
                $uploadPath = $uploadBaseDir . $fileName;

                if (move_uploaded_file($files[$field]['tmp_name'], $uploadPath)) {
                    $result[$field] = 'assets/uploads/schools/' . $schoolId . '/' . $fileName;
                } else {
                    error_log("Failed to move uploaded {$field} file");
                }
            } else {
                error_log("Invalid file type or size for {$field}");
            }
        }
    }
    
    return $result;
}

function getPlatformTableColumns($db, $table) {
    try {
        $safeTable = str_replace('`', '', $table);
        return $db->query("SHOW COLUMNS FROM `{$safeTable}`")->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (Exception $e) {
        error_log("Could not read columns for {$table}: " . $e->getMessage());
        return [];
    }
}

function parseLandingRows($text, array $keys) {
    $rows = [];
    $lines = preg_split('/\r\n|\r|\n/', trim((string) $text));

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        $row = [];
        foreach ($keys as $index => $key) {
            $row[$key] = $parts[$index] ?? '';
        }

        if (array_filter($row, static function ($value) {
            return $value !== '';
        })) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function changeAdminPasswordDirectly($schoolDb, $schoolId, $userId, $newPassword) {
    try {
        if (!$schoolDb) {
            throw new Exception("School database not connected");
        }

        $stmt = $schoolDb->prepare("
            UPDATE users
            SET password = ?, updated_at = NOW()
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $userId,
            $schoolId
        ]);

        return [
            'success' => true,
            'message' => 'Password changed successfully'
        ];
    } catch (Throwable $e) {
        error_log("Direct password change failed: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to change password: ' . $e->getMessage()
        ];
    }
}

function updateSchoolSettings($schoolDb, $schoolId, $data) {
    if (!$schoolDb) {
        return;
    }
    
    $settingsToUpdate = [
        'school_name' => $data['name'] ?? '',
        'school_email' => $data['email'] ?? '',
        'school_phone' => $data['phone'] ?? '',
        'address' => $data['address'] ?? '',
        'city' => $data['city'] ?? '',
        'state' => $data['state'] ?? '',
        'country' => $data['country'] ?? '',
        'postal_code' => $data['postal_code'] ?? '',
        'website' => $data['website'] ?? '',
        'timezone' => $data['timezone'] ?? '',
        'currency' => $data['currency'] ?? '',
        'language' => $data['language'] ?? '',
        'principal_name' => $data['principal_name'] ?? '',
        'mission_statement' => $data['mission_statement'] ?? '',
        'vision_statement' => $data['vision_statement'] ?? '',
        'school_description' => $data['description'] ?? ''
    ];
    
    foreach ($settingsToUpdate as $key => $value) {
        if ($value !== '') {
            try {
                $checkStmt = $schoolDb->prepare("SELECT id FROM settings WHERE `key` = ? AND school_id = ?");
                $checkStmt->execute([$key, $schoolId]);
                
                if ($checkStmt->fetch()) {
                    $updateStmt = $schoolDb->prepare("
                        UPDATE settings SET value = ?, updated_at = NOW() 
                        WHERE `key` = ? AND school_id = ?
                    ");
                    $updateStmt->execute([$value, $key, $schoolId]);
                } else {
                    $insertStmt = $schoolDb->prepare("
                        INSERT INTO settings (`key`, value, school_id, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $insertStmt->execute([$key, $value, $schoolId]);
                }
            } catch (Exception $e) {
                error_log("Error updating setting $key: " . $e->getMessage());
            }
        }
    }
}

function createAnnouncement($post, $schoolDb, $schoolId, $userId, $actionManager) {
    try {
        if (!$schoolDb) {
            throw new Exception("School database not connected");
        }
        
        // Validate required fields
        if (empty($post['title']) || empty($post['description'])) {
            throw new Exception("Title and description are required");
        }
        
        $stmt = $schoolDb->prepare("
            INSERT INTO announcements (
                school_id, title, description, target, class_id,
                section_id, start_date, end_date, is_published, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $schoolId,
            $post['title'],
            $post['description'],
            $post['target'] ?? 'all',
            !empty($post['class_id']) ? $post['class_id'] : null,
            !empty($post['section_id']) ? $post['section_id'] : null,
            !empty($post['start_date']) ? $post['start_date'] : null,
            !empty($post['end_date']) ? $post['end_date'] : null,
            1,
            $userId
        ]);
        
        $announcementId = $schoolDb->lastInsertId();
        
        // Create audit log
        if ($actionManager && method_exists($actionManager, 'createSchoolAuditLog')) {
            $actionManager->createSchoolAuditLog([
                'user_id' => $userId,
                'user_type' => 'admin',
                'action' => 'announcement_created',
                'entity_type' => 'announcements',
                'entity_id' => $announcementId,
                'new_values' => json_encode(['title' => $post['title']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
        
        return [
            'success' => true,
            'message' => 'Announcement created successfully!',
            'announcement_id' => $announcementId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to create announcement: ' . $e->getMessage()
        ];
    }
}

function updateSubscription($post, $platformDb, $schoolId, $userId, $actionManager) {
    try {
        if (!$platformDb) {
            throw new Exception("Platform database not connected");
        }
        
        $platformDb->beginTransaction();
        
        // Validate required fields
        if (empty($post['plan_id'])) {
            throw new Exception("Plan ID is required");
        }
        
        $stmt = $platformDb->prepare("
            UPDATE subscriptions 
            SET plan_id = ?,
                billing_cycle = ?,
                amount = ?,
                status = ?,
                updated_at = NOW()
            WHERE school_id = ?
        ");
        
        $stmt->execute([
            $post['plan_id'],
            $post['billing_cycle'] ?? 'monthly',
            $post['amount'] ?? 0,
            $post['status'] ?? 'active',
            $schoolId
        ]);
        
        // Update school plan
        $schoolStmt = $platformDb->prepare("
            UPDATE schools SET plan_id = ? WHERE id = ?
        ");
        $schoolStmt->execute([$post['plan_id'], $schoolId]);
        
        // Create audit log
        if ($actionManager && method_exists($actionManager, 'createPlatformAuditLog')) {
            $actionManager->createPlatformAuditLog([
                'event' => 'subscription_updated',
                'auditable_type' => 'subscriptions',
                'auditable_id' => $schoolId,
                'new_values' => json_encode(['plan_id' => $post['plan_id'], 'status' => $post['status']]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
        
        if ($platformDb->inTransaction()) {
            $platformDb->commit();
        }
        
        return [
            'success' => true,
            'message' => 'Subscription updated successfully!'
        ];
        
    } catch (Exception $e) {
        if ($platformDb && $platformDb->inTransaction()) {
            $platformDb->rollBack();
        }
        return [
            'success' => false,
            'message' => 'Failed to update subscription: ' . $e->getMessage()
        ];
    }
}

function createBackup($schoolDb, $platformDb, $schoolId, $userId, $actionManager) {
    try {
        if (!$schoolDb || !$platformDb) {
            throw new Exception("Database connections required");
        }
        
        $backupFile = 'backup_' . $schoolId . '_' . date('Y-m-d_H-i-s') . '.sql';
        $backupDir = __DIR__ . '/../../../backups/';
        $backupPath = $backupDir . $backupFile;
        
        // Ensure backup directory exists
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new Exception("Failed to create backup directory");
            }
        }
        
        // Get database name from connection
        try {
            $dbName = $schoolDb->query("SELECT DATABASE()")->fetchColumn();
        } catch (Exception $e) {
            $dbName = 'school_' . $schoolId;
            error_log("Failed to get database name: " . $e->getMessage());
        }
        
        // Create backup entry
        $stmt = $platformDb->prepare("
            INSERT INTO database_backups (
                school_id, database_name, filename, file_size, backup_type, created_at
            ) VALUES (?, ?, ?, ?, 'manual', NOW())
        ");
        
        $stmt->execute([
            $schoolId,
            $dbName,
            $backupFile,
            0 // Will update after backup completes
        ]);
        
        $backupId = $platformDb->lastInsertId();
        
        // Create audit log
        if ($actionManager && method_exists($actionManager, 'createPlatformAuditLog')) {
            $actionManager->createPlatformAuditLog([
                'event' => 'backup_created',
                'auditable_type' => 'database_backups',
                'auditable_id' => $backupId,
                'new_values' => json_encode(['filename' => $backupFile]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        }
        
        return [
            'success' => true,
            'message' => 'Backup initiated successfully!',
            'backup_id' => $backupId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to create backup: ' . $e->getMessage()
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Management Hub - Manage all aspects of your school">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Management - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
    
    <!-- Styles -->
    <link rel="icon" type="image/png" href="/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="/tenant/assets/css/style.css">
    
    <style>
        .avatar-preview {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            border: 2px solid #dee2e6;
        }
        .preview-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
        }
        .nav-tabs .nav-link {
            color: #6c757d;
            font-weight: 500;
        }
        .nav-tabs .nav-link.active {
            color: #25A194;
            border-bottom: 2px solid #25A194;
        }
        .form-section-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e9ecef;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .stat-card i {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
        }
        .stat-card .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .activity-feed {
            max-height: 400px;
            overflow-y: auto;
        }
        .activity-item {
            padding: 10px;
            border-left: 3px solid #25A194;
            margin-bottom: 10px;
            background: #f8f9fa;
        }
        .activity-time {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .storage-bar {
            height: 10px;
            border-radius: 5px;
            background: #e9ecef;
            margin: 10px 0;
        }
        .storage-bar-fill {
            height: 100%;
            border-radius: 5px;
            background: #25A194;
            transition: width 0.3s ease;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
    </style>
</head>

<body>
    <!-- Theme Customization Structure Start -->
    <div class="body-overlay"></div>
    <button type="button"
        class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    <div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
        <div class="d-flex align-items-center gap-3 py-16 px-24 justify-content-between border-bottom">
            <div>
                <h6 class="text-sm dark:text-white">Theme Settings</h6>
                <p class="text-xs mb-0 text-neutral-500 dark:text-neutral-200">Customize and preview instantly</p>
            </div>
            <button data-slot="button"
                class="theme-customization-sidebar__close text-neutral-900 bg-transparent text-hover-primary-600 d-flex text-xl">
                <i class="ri-close-fill"></i>
            </button>
        </div>

        <div class="d-flex flex-column gap-48 p-24 overflow-y-auto flex-grow-1">
            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Theme Mode</h6>
                <div class="d-grid grid-cols-3 gap-3 dark-light-mode">
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl active"
                        data-theme="light" aria-label="light">
                        <i class="ri-sun-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="dark" aria-label="dark">
                        <i class="ri-moon-line"></i>
                    </button>
                    <button type="button"
                        class="theme-btn theme-setting-item__btn d-flex align-items-center justify-content-center h-64-px rounded-3 text-xl"
                        data-theme="system" aria-label="system">
                        <i class="ri-computer-line"></i>
                    </button>
                </div>
            </div>

            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Page Direction</h6>
                <div class="d-grid grid-cols-2 gap-3">
                    <button type="button"
                        class="theme-setting-item__btn ltr-mode-btn d-flex align-items-center justify-content-center gap-2 h-56-px rounded-3 text-xl" aria-label="LTR">
                        <span><i class="ri-align-item-left-line"></i></span>
                        <span class="h6 text-sm font-medium mb-0">LTR</span>
                    </button>
                    <button type="button"
                        class="theme-setting-item__btn rtl-mode-btn d-flex align-items-center justify-content-center gap-2 h-56-px rounded-3 text-xl" aria-label="RTL">
                        <span class="h6 text-sm font-medium mb-0">RTL</span>
                        <span><i class="ri-align-item-right-line"></i></span>
                    </button>
                </div>
            </div>

            <div class="theme-setting-item">
                <h6 class="fw-medium text-primary-light text-md mb-3">Color Schema</h6>
                <div class="d-grid grid-cols-3 gap-3">
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="base" aria-label="Base">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #25A194;"></span>
                        <span class="fw-medium mt-1" style="color: #25A194;">Base</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="red" aria-label="Red">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #dc2626;"></span>
                        <span class="fw-medium mt-1" style="color: #dc2626;">Red</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="blue" aria-label="Blue">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #2563eb;"></span>
                        <span class="fw-medium mt-1" style="color: #2563eb;">Blue</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="yellow" aria-label="Yellow">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #ff9f29;"></span>
                        <span class="fw-medium mt-1" style="color: #ff9f29;">Yellow</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="cyan" aria-label="Cyan">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #00b8f2;"></span>
                        <span class="fw-medium mt-1" style="color: #00b8f2;">Cyan</span>
                    </button>
                    <button type="button"
                        class="color-picker-btn d-flex flex-column justify-content-center align-items-center"
                        data-color="violet" aria-label="Violet">
                        <span class="color-picker-btn__box h-40-px w-100 rounded-3"
                            style="background-color: #7c3aed;"></span>
                        <span class="fw-medium mt-1" style="color: #7c3aed;">Violet</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php'); ?>
<main class="dashboard-main">
        
        <?php include_once('includes/header.php'); ?>

         <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">School Management Hub</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light">/ Management</span>
                    </div>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="ri-checkbox-circle-line me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="ri-error-warning-line me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Quick Stats -->
            <div class="row mb-24">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $schoolDetails['student_count'] ?? 0; ?></div>
                                <div class="stat-label">Total Students</div>
                            </div>
                            <i class="ri-group-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $schoolDetails['teacher_count'] ?? 0; ?></div>
                                <div class="stat-label">Total Teachers</div>
                            </div>
                            <i class="ri-user-star-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo $schoolDetails['class_count'] ?? 0; ?></div>
                                <div class="stat-label">Total Classes</div>
                            </div>
                            <i class="ri-school-line"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-value"><?php echo count($academicYears); ?></div>
                                <div class="stat-label">Academic Years</div>
                            </div>
                            <i class="ri-calendar-line"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Management Tabs -->
            <ul class="nav nav-tabs mb-24" id="managementTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'general' ? 'active' : ''; ?>" 
                            id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                        <i class="ri-settings-3-line me-2"></i>General Settings
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'academic' ? 'active' : ''; ?>" 
                            id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic" type="button" role="tab">
                        <i class="ri-graduation-cap-line me-2"></i>Academic Management
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'announcements' ? 'active' : ''; ?>" 
                            id="announcements-tab" data-bs-toggle="tab" data-bs-target="#announcements" type="button" role="tab">
                        <i class="ri-megaphone-line me-2"></i>Announcements
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'subscription' ? 'active' : ''; ?>" 
                            id="subscription-tab" data-bs-toggle="tab" data-bs-target="#subscription" type="button" role="tab">
                        <i class="ri-price-tag-3-line me-2"></i>Subscription & Billing
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'security' ? 'active' : ''; ?>" 
                            id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                        <i class="ri-shield-keyhole-line me-2"></i>Security & Backup
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $activeTab == 'activity' ? 'active' : ''; ?>" 
                            id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                        <i class="ri-history-line me-2"></i>Activity Log
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="managementTabsContent">
                <!-- General Settings Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'general' ? 'show active' : ''; ?>" id="general" role="tabpanel">
                    <?php include 'tabs/general_settings.php'; ?>
                </div>

                <!-- Academic Management Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'academic' ? 'show active' : ''; ?>" id="academic" role="tabpanel">
                    <div class="row">
                        <!-- Academic Years -->
                        <div class="col-md-6">
                            <div class="card mb-24">
                                <div class="card-header">
                                    <h5>Academic Years</h5>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addYearModal">
                                        <i class="ri-add-line"></i> Add Year
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Year Name</th>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                    <th>Status</th>
                                                    <th>Default</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($academicYears as $year): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($year['name']); ?></td>
                                                    <td><?php echo $year['start_date']; ?></td>
                                                    <td><?php echo $year['end_date']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php 
                                                            echo $year['status'] == 'active' ? 'success' : 
                                                                ($year['status'] == 'upcoming' ? 'warning' : 'secondary'); 
                                                        ?>">
                                                            <?php echo ucfirst($year['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($year['is_default']): ?>
                                                            <span class="badge bg-primary">Default</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Terms -->
                        <div class="col-md-6">
                            <div class="card mb-24">
                                <div class="card-header">
                                    <h5>Academic Terms</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table">
                                            <thead>
                                                <tr>
                                                    <th>Term Name</th>
                                                    <th>Start Date</th>
                                                    <th>End Date</th>
                                                    <th>Default</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($academicTerms as $term): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($term['name']); ?></td>
                                                    <td><?php echo $term['start_date']; ?></td>
                                                    <td><?php echo $term['end_date']; ?></td>
                                                    <td>
                                                        <?php if ($term['is_default']): ?>
                                                            <span class="badge bg-primary">Default</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fee Structures -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Fee Structures</h5>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                                        <i class="ri-add-line"></i> Add Fee Structure
                                    </button>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">Fee management interface would go here</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Announcements Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'announcements' ? 'show active' : ''; ?>" id="announcements" role="tabpanel">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Create Announcement</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_announcement">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Title</label>
                                            <input type="text" name="title" class="form-control" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <textarea name="description" class="form-control" rows="3" required></textarea>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Target Audience</label>
                                            <select name="target" class="form-select">
                                                <option value="all">All</option>
                                                <option value="students">Students</option>
                                                <option value="teachers">Teachers</option>
                                                <option value="parents">Parents</option>
                                            </select>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Start Date</label>
                                                <input type="date" name="start_date" class="form-control">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">End Date</label>
                                                <input type="date" name="end_date" class="form-control">
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-primary w-100">Publish Announcement</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Recent Announcements</h5>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($recentAnnouncements as $announcement): ?>
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h6><?php echo htmlspecialchars($announcement['title']); ?></h6>
                                            <p class="text-muted mb-2"><?php echo substr($announcement['description'], 0, 100); ?>...</p>
                                            <div class="d-flex justify-content-between">
                                                <small>By: <?php echo htmlspecialchars($announcement['created_by_name']); ?></small>
                                                <small><?php echo date('M d, Y', strtotime($announcement['created_at'])); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subscription & Billing Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'subscription' ? 'show active' : ''; ?>" id="subscription" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Current Subscription</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($subscriptionInfo): ?>
                                    <table class="table">
                                        <tr>
                                            <th>Plan:</th>
                                            <td><?php echo htmlspecialchars($subscriptionInfo['plan_name'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Status:</th>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $subscriptionInfo['status'] == 'active' ? 'success' : 
                                                        ($subscriptionInfo['status'] == 'pending' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo ucfirst($subscriptionInfo['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <th>Billing Cycle:</th>
                                            <td><?php echo ucfirst($subscriptionInfo['billing_cycle']); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Amount:</th>
                                            <td><?php echo $subscriptionInfo['currency'] . ' ' . number_format($subscriptionInfo['amount'], 2); ?></td>
                                        </tr>
                                        <tr>
                                            <th>Period Start:</th>
                                            <td><?php echo $subscriptionInfo['current_period_start']; ?></td>
                                        </tr>
                                        <tr>
                                            <th>Period End:</th>
                                            <td><?php echo $subscriptionInfo['current_period_end']; ?></td>
                                        </tr>
                                        <?php if ($subscriptionInfo['trial_ends_at']): ?>
                                        <tr>
                                            <th>Trial Ends:</th>
                                            <td><?php echo $subscriptionInfo['trial_ends_at']; ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                    <?php else: ?>
                                    <p class="text-muted">No active subscription found.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5>Plan Features</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($schoolDetails['plan_features'])): ?>
                                    <ul class="list-group">
                                        <?php foreach ($schoolDetails['plan_features'] as $feature): ?>
                                        <li class="list-group-item">
                                            <i class="ri-check-line text-success me-2"></i>
                                            <?php echo htmlspecialchars($feature); ?>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php else: ?>
                                    <p class="text-muted">No features available.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security & Backup Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'security' ? 'show active' : ''; ?>" id="security" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-24">
                                <div class="card-header">
                                    <h5>Change Password</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Current Password</label>
                                            <input type="password" name="current_password" class="form-control" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">New Password</label>
                                            <input type="password" name="new_password" class="form-control" 
                                                   pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" required>
                                            <small class="text-muted">Min 8 chars, with uppercase, lowercase and number</small>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Confirm New Password</label>
                                            <input type="password" name="confirm_password" class="form-control" required>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-warning">Change Password</button>
                                    </form>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-header">
                                    <h5>Two-Factor Authentication</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">Two-factor authentication adds an extra layer of security to your account.</p>
                                    <button class="btn btn-primary" disabled>Enable 2FA (Coming Soon)</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="card mb-24">
                                <div class="card-header">
                                    <h5>Storage Usage</h5>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="createBackup()">
                                        <i class="ri-database-2-line"></i> Create Backup
                                    </button>
                                </div>
                                <div class="card-body">
                                    <?php 
                                    $totalStorage = 0;
                                    $usedStorage = 0;
                                    foreach ($storageUsage as $storage) {
                                        $totalStorage += $storage['limit_bytes'];
                                        $usedStorage += $storage['used_bytes'];
                                    }
                                    $usagePercent = $totalStorage > 0 ? ($usedStorage / $totalStorage) * 100 : 0;
                                    ?>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between">
                                            <span>Used: <?php echo round($usedStorage / 1024 / 1024, 2); ?> MB</span>
                                            <span>Total: <?php echo round($totalStorage / 1024 / 1024, 2); ?> MB</span>
                                        </div>
                                        <div class="storage-bar">
                                            <div class="storage-bar-fill" style="width: <?php echo $usagePercent; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" id="backupForm">
                                        <input type="hidden" name="action" value="create_backup">
                                    </form>
                                </div>
                            </div>
                            
                            <div class="card">
                                <div class="card-header">
                                    <h5>Recent Security Events</h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted">Security monitoring coming soon.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Activity Log Tab -->
                <div class="tab-pane fade <?php echo $activeTab == 'activity' ? 'show active' : ''; ?>" id="activity" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <h5>Recent Activities</h5>
                        </div>
                        <div class="card-body">
                            <div class="activity-feed">
                                <?php foreach ($recentActivities as $activity): ?>
                                <div class="activity-item">
                                    <div class="d-flex justify-content-between">
                                        <strong><?php echo ucfirst(str_replace('_', ' ', $activity['action'])); ?></strong>
                                        <small class="activity-time">
                                            <?php echo date('M d, H:i', strtotime($activity['created_at'])); ?>
                                        </small>
                                    </div>
                                    <div class="mt-2">
                                        <small>
                                            By: <?php echo htmlspecialchars($activity['user_type']); ?> 
                                            (IP: <?php echo $activity['ip_address'] ?? 'N/A'; ?>)
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="d-footer">
            <div class="">
                <p class="mb-0 text-center"> &copy; <span class="current-year"></span> <?php echo htmlspecialchars($school['name']); ?> | Made With ❤️ by AcademixSuite.</p>
            </div>
        </footer>
    </main>

    <!-- Add Academic Year Modal -->
    <div class="modal fade" id="addYearModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Academic Year</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_academic_year">
                        
                        <div class="mb-3">
                            <label class="form-label">Year Name</label>
                            <input type="text" name="year_name" class="form-control" 
                                   placeholder="e.g., 2025-2026" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="upcoming">Upcoming</option>
                                    <option value="active">Active</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Default Year</label>
                                <div class="form-check mt-2">
                                    <input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefault">
                                    <label class="form-check-label" for="isDefault">Set as default</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Year</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="/tenant/assets/js/lib/flatpickr.min.js"></script>
    <script src="/tenant/assets/js/app.js"></script>

    <script>
        // Initialize flatpickr for date inputs
        flatpickr("input[type=date]", {
            dateFormat: "Y-m-d"
        });

        // Image Preview
        function readURL(input, previewElement) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    $('#' + previewElement).css('background-image', 'url(' + e.target.result + ')');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        $("#logo").change(function() {
            readURL(this, 'logoPreview');
        });

        $("#favicon").change(function() {
            readURL(this, 'faviconPreview');
        });

        // Auto-dismiss alerts
        setTimeout(function() {
            $('.alert').fadeOut('slow');
        }, 5000);

        // Tab persistence
        $(document).ready(function() {
            var hash = window.location.hash;
            if (hash) {
                $('#managementTabs a[href="' + hash + '"]').tab('show');
            }

            $('#managementTabs a').on('click', function(e) {
                window.location.hash = $(this).attr('href');
            });
        });

        // Password match validation
        $('input[name="confirm_password"]').on('keyup', function() {
            var password = $('input[name="new_password"]').val();
            var confirm = $(this).val();
            
            if (password !== confirm) {
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        // Create backup function
        function createBackup() {
            if (confirm('Are you sure you want to create a database backup? This may take a few moments.')) {
                $('#backupForm').submit();
            }
        }

        // Initialize data tables
        $(document).ready(function() {
            if ($('.table').length > 0) {
                $('.table').DataTable({
                    pageLength: 10,
                    responsive: true,
                    language: {
                        search: "Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
