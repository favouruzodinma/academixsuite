<?php
/**
 * Login as Parent Page
 * Allows administrators to impersonate a parent/guardian account
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/login_as_parent.log');

error_log("=== LOGIN AS PARENT PAGE START ===");
error_log("Script: " . __FILE__);

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

/**
 * Initialize session safely
 */
function initializeSession() {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 86400,
                'read_and_close' => false,
                'cookie_secure' => !IS_LOCAL,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax'
            ]);
            error_log("Session started successfully");
        }
        return true;
    } catch (Exception $e) {
        error_log("Session initialization error: " . $e->getMessage());
        return false;
    }
}

// Start session
initializeSession();

/**
 * Get school slug from router
 */
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'login-as-parent.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

error_log("School Slug: " . $schoolSlug);

// Validate school slug
if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(['error' => 'School identifier missing']));
}

/**
 * Get school information
 */
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

// Redirect if school not found
if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

error_log("School ID: " . ($school['id'] ?? 'N/A'));
error_log("School Name: " . ($school['name'] ?? 'N/A'));

/**
 * Verify authentication - Only admins can impersonate
 */
$isAuthenticated = isset($_SESSION['school_auth']) && 
                   is_array($_SESSION['school_auth']) && 
                   ($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug;

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get current user info
$schoolAuth = $_SESSION['school_auth'];
$currentUserId = (int)($schoolAuth['user_id'] ?? 0);
$currentUserType = $schoolAuth['user_type'] ?? '';

// Check if current user is admin
if ($currentUserType !== 'admin') {
    error_log("Non-admin user attempted to impersonate: " . $currentUserType);
    $_SESSION['toast_error'] = "You don't have permission to access this feature.";
    header("Location: guardian-list.php");
    exit;
}

error_log("Current Admin User ID: " . $currentUserId);

/**
 * Load required files
 */
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    error_log("Loading autoload from: " . $autoloadPath);
    
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    require_once $autoloadPath;
    error_log("Autoload loaded successfully");
    
    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
    error_log("Database class found");
    
} catch (Exception $e) {
    error_log("CRITICAL ERROR loading configuration: " . $e->getMessage());
    http_response_code(500);
    die("System configuration loading failed. Please try again later.");
}

/**
 * Connect to school database
 */
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        error_log("Attempting to connect to database: " . $school['database_name']);
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        
        if ($schoolDb) {
            error_log("School database connection successful");
        } else {
            throw new Exception("Database connection returned null");
        }
    } else {
        throw new Exception("No database name configured for school");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
    $_SESSION['toast_error'] = "Database connection failed. Please contact support.";
    header("Location: guardian-list.php");
    exit;
}

/**
 * Get guardian ID from URL
 */
$guardianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
error_log("Guardian ID to impersonate: " . $guardianId);

if ($guardianId <= 0) {
    error_log("Invalid guardian ID");
    $_SESSION['toast_error'] = "Invalid guardian ID.";
    header("Location: guardian-list.php");
    exit;
}

/**
 * Verify guardian exists and is active
 */
try {
    $stmt = $schoolDb->prepare("
        SELECT 
            u.id,
            u.name,
            u.email,
            u.user_type,
            u.is_active,
            u.profile_photo,
            u.last_login,
            (
                SELECT COUNT(*) 
                FROM guardians g 
                WHERE g.user_id = u.id
            ) as student_count
        FROM users u
        WHERE u.id = ? AND u.school_id = ? AND u.user_type = 'parent'
    ");
    $stmt->execute([$guardianId, $school['id']]);
    $guardian = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$guardian) {
        error_log("Guardian not found with ID: " . $guardianId);
        $_SESSION['toast_error'] = "Guardian not found.";
        header("Location: guardian-list.php");
        exit;
    }
    
    if (!$guardian['is_active']) {
        error_log("Guardian account is inactive: " . $guardianId);
        $_SESSION['toast_error'] = "Cannot login as inactive guardian account.";
        header("Location: guardian-list.php");
        exit;
    }
    
    error_log("Guardian found: " . $guardian['name']);
    
} catch (Exception $e) {
    error_log("Error verifying guardian: " . $e->getMessage());
    $_SESSION['toast_error'] = "Error verifying guardian account.";
    header("Location: guardian-list.php");
    exit;
}

/**
 * Check if this is a confirmation to proceed
 */
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    
    // Create impersonation session
    $_SESSION['impersonating'] = true;
    $_SESSION['original_user'] = [
        'id' => $currentUserId,
        'type' => $currentUserType,
        'school_slug' => $schoolSlug
    ];
    
    // Set the impersonated user session
    $_SESSION['school_auth'] = [
        'user_id' => $guardianId,
        'user_type' => 'parent',
        'school_slug' => $schoolSlug,
        'name' => $guardian['name'],
        'email' => $guardian['email'],
        'logged_in_at' => time()
    ];
    
    // Log the impersonation
    try {
        $logStmt = $schoolDb->prepare("
            INSERT INTO audit_logs (
                school_id, user_id, user_type, action, entity_type, entity_id,
                new_values, ip_address, user_agent, url, created_at
            ) VALUES (?, ?, ?, 'impersonate', 'user', ?, ?, ?, ?, ?, NOW())
        ");
        
        $logStmt->execute([
            $school['id'],
            $currentUserId,
            $currentUserType,
            $guardianId,
            json_encode(['impersonated_user' => $guardian['name'], 'original_user' => $currentUserId]),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null
        ]);
        
        error_log("Impersonation logged: Admin {$currentUserId} impersonating Guardian {$guardianId}");
        
    } catch (Exception $e) {
        error_log("Error logging impersonation: " . $e->getMessage());
        // Continue even if logging fails
    }
    
    // Update last login time
    try {
        $updateStmt = $schoolDb->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$guardianId]);
    } catch (Exception $e) {
        error_log("Error updating last login: " . $e->getMessage());
    }
    
    // Redirect to parent dashboard
    $_SESSION['toast_success'] = "You are now logged in as " . $guardian['name'];
    header("Location: ../parent/dashboard.php");
    exit;
}

/**
 * Handle cancellation
 */
if (isset($_GET['cancel'])) {
    header("Location: guardian-details.php?id=" . $guardianId);
    exit;
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

error_log("=== LOGIN AS PARENT PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login as Parent - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', sans-serif;
        }
        
        .confirmation-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            padding: 40px;
            text-align: center;
            animation: slideUp 0.5s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .warning-icon {
            width: 80px;
            height: 80px;
            background: #fff3cd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            color: #856404;
            font-size: 40px;
        }
        
        .guardian-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            margin: 0 auto 20px;
            border: 4px solid #25A194;
            padding: 4px;
            background: white;
        }
        
        .guardian-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .guardian-name {
            font-size: 24px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .guardian-email {
            color: #666;
            margin-bottom: 24px;
            font-size: 14px;
        }
        
        .info-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin: 24px 0;
            text-align: left;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-icon {
            width: 36px;
            height: 36px;
            background: #e9ecef;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #25A194;
        }
        
        .info-content {
            flex: 1;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            margin-bottom: 2px;
        }
        
        .info-value {
            font-weight: 600;
            color: #333;
        }
        
        .warning-text {
            background: #fff3cd;
            color: #856404;
            padding: 16px;
            border-radius: 12px;
            margin: 24px 0;
            font-size: 14px;
            text-align: left;
            display: flex;
            gap: 12px;
        }
        
        .warning-text i {
            font-size: 24px;
            color: #856404;
        }
        
        .btn-group {
            display: flex;
            gap: 16px;
            margin-top: 32px;
        }
        
        .btn {
            flex: 1;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #25A194;
            border: none;
        }
        
        .btn-primary:hover {
            background: #1e7e74;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(37, 161, 148, 0.4);
        }
        
        .btn-outline-secondary {
            border: 2px solid #e9ecef;
            color: #666;
        }
        
        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #25A194;
            color: #25A194;
        }
        
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        
        .toast {
            min-width: 300px;
            background: white;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin-bottom: 10px;
            animation: slideIn 0.3s ease;
        }
        
        .toast.success {
            border-left-color: #28a745;
        }
        .toast.success .toast-header {
            background-color: #d4edda;
            color: #155724;
        }
        .toast.error {
            border-left-color: #dc3545;
        }
        .toast.error .toast-header {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
    </style>
</head>

<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer">
    <?php if (!empty($toastSuccess)): ?>
    <div class="toast success show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
        <div class="toast-header">
            <i class="ri-checkbox-circle-line me-2"></i>
            <strong class="me-auto">Success</strong>
            <small>just now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            <?php echo htmlspecialchars($toastSuccess); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($toastError)): ?>
    <div class="toast error show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
        <div class="toast-header">
            <i class="ri-error-warning-line me-2"></i>
            <strong class="me-auto">Error</strong>
            <small>just now</small>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
        <div class="toast-body">
            <?php echo htmlspecialchars($toastError); ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="confirmation-card">
    <div class="warning-icon">
        <i class="ri-shield-user-line"></i>
    </div>
    
    <h2 class="h4 mb-2">Login as Parent</h2>
    <p class="text-muted mb-4">You are about to impersonate a parent account</p>
    
    <div class="guardian-avatar">
        <img src="<?php echo !empty($guardian['profile_photo']) ? htmlspecialchars($guardian['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/teacher-details-img.png'; ?>" 
             alt="<?php echo htmlspecialchars($guardian['name']); ?>">
    </div>
    
    <div class="guardian-name"><?php echo htmlspecialchars($guardian['name']); ?></div>
    <div class="guardian-email"><?php echo htmlspecialchars($guardian['email']); ?></div>
    
    <div class="info-box">
        <div class="info-item">
            <div class="info-icon">
                <i class="ri-user-star-line"></i>
            </div>
            <div class="info-content">
                <div class="info-label">Account Type</div>
                <div class="info-value">Parent/Guardian</div>
            </div>
        </div>
        
        <div class="info-item">
            <div class="info-icon">
                <i class="ri-group-line"></i>
            </div>
            <div class="info-content">
                <div class="info-label">Linked Students</div>
                <div class="info-value"><?php echo $guardian['student_count']; ?> Student(s)</div>
            </div>
        </div>
        
        <div class="info-item">
            <div class="info-icon">
                <i class="ri-calendar-line"></i>
            </div>
            <div class="info-content">
                <div class="info-label">Last Login</div>
                <div class="info-value">
                    <?php echo $guardian['last_login'] ? date('d M Y, h:i A', strtotime($guardian['last_login'])) : 'Never'; ?>
                </div>
            </div>
        </div>
        
        <div class="info-item">
            <div class="info-icon">
                <i class="ri-shield-check-line"></i>
            </div>
            <div class="info-content">
                <div class="info-label">Account Status</div>
                <div class="info-value">
                    <span class="badge badge-success">Active</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="warning-text">
        <i class="ri-information-line"></i>
        <div>
            <strong>Important Security Notice:</strong>
            <p class="mb-0 mt-2">You are about to access this account as an administrator. All actions performed while impersonating will be logged and audited. Use this feature only for troubleshooting and support purposes.</p>
        </div>
    </div>
    
    <div class="btn-group">
        <a href="?id=<?php echo $guardianId; ?>&cancel=1" class="btn btn-outline-secondary">
            <i class="ri-close-line me-2"></i>
            Cancel
        </a>
        <a href="?id=<?php echo $guardianId; ?>&confirm=yes" class="btn btn-primary" onclick="return confirmImpersonation()">
            <i class="ri-login-box-line me-2"></i>
            Login as Parent
        </a>
    </div>
</div>

<script>
    $(document).ready(function() {
        // Initialize Bootstrap toasts
        $('.toast').toast({
            autohide: true,
            delay: 5000
        });
        $('.toast').toast('show');
    });
    
    function confirmImpersonation() {
        return confirm('Are you sure you want to login as this parent? All actions will be logged for audit purposes.');
    }
</script>

<!-- jQuery library js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<!-- Bootstrap js -->
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>

</body>
</html>