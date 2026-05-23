<?php
/**
 * Admin Profile Page
 * Displays and allows editing of admin profile information.
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/admin_profile.log');

error_log("=== ADMIN PROFILE PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');

// Start session
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_lifetime' => 86400,
            'read_and_close'  => false,
        ]);
    }
} catch (Exception $e) {
    error_log("Session error: " . $e->getMessage());
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Load school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Check authentication
$isAuthenticated = isset($_SESSION['school_auth']) && 
                   is_array($_SESSION['school_auth']) && 
                   ($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug;

if (!$isAuthenticated) {
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (only admin can access own profile, but we'll let any logged-in user see their own)
// For now, we'll allow any authenticated user.

// Load configuration
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found");
    }
    require_once $autoloadPath;

    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }

} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed. Please contact administrator.");
}

// Connect to school database
$schoolDb = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
    } else {
        throw new Exception("School database name not found");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    die("Database connection failed.");
}

// CSRF token functions
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
$csrfToken = generateCsrfToken();

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    $csrfTokenPost = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfTokenPost)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'update_profile':
            try {
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $gender = $_POST['gender'] ?? null;
                $date_of_birth = $_POST['date_of_birth'] ?? null;
                $blood_group = $_POST['blood_group'] ?? null;
                $religion = $_POST['religion'] ?? null;
                $address = trim($_POST['address'] ?? '');

                if (empty($name) || empty($email)) {
                    throw new Exception('Name and email are required.');
                }

                // Check if email already exists for another user
                $checkStmt = $schoolDb->prepare("SELECT id FROM users WHERE email = ? AND school_id = ? AND id != ?");
                $checkStmt->execute([$email, $school['id'], $userId]);
                if ($checkStmt->fetch()) {
                    throw new Exception('Email already in use by another user.');
                }

                $stmt = $schoolDb->prepare("
                    UPDATE users SET
                        name = ?,
                        email = ?,
                        phone = ?,
                        gender = ?,
                        date_of_birth = ?,
                        blood_group = ?,
                        religion = ?,
                        address = ?,
                        updated_at = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$name, $email, $phone, $gender, $date_of_birth, $blood_group, $religion, $address, $userId, $school['id']]);

                // Audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                    VALUES (?, ?, ?, 'profile_updated', 'users', ?, ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $userId,
                    json_encode(['updated_fields' => ['name', 'email', 'phone']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);

                $response = ['success' => true, 'message' => 'Profile updated successfully.'];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => 'Failed to update profile: ' . $e->getMessage()];
            }
            break;

        case 'upload_photo':
            try {
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No file uploaded or upload error.');
                }

                $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowed)) {
                    throw new Exception('Only JPG, PNG, GIF, and WEBP images are allowed.');
                }

                // School-specific upload folder
                $uploadDir = __DIR__ . '/../../../assets/uploads/profiles/' . $school['id'] . '/';
                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0755, true)) {
                        throw new Exception('Failed to create upload directory.');
                    }
                }

                $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
                $uploadPath = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
                    throw new Exception('Failed to save uploaded file.');
                }

                $photoPath = '/assets/uploads/profiles/' . $school['id'] . '/' . $filename;

                $updateStmt = $schoolDb->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $updateStmt->execute([$photoPath, $userId, $school['id']]);

                $response = ['success' => true, 'message' => 'Profile photo updated.', 'photo_url' => $photoPath];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => $e->getMessage()];
            }
            break;

        case 'change_password':
            try {
                $current = $_POST['current_password'] ?? '';
                $new = $_POST['new_password'] ?? '';
                $confirm = $_POST['confirm_password'] ?? '';

                if (empty($current) || empty($new) || empty($confirm)) {
                    throw new Exception('All password fields are required.');
                }
                if ($new !== $confirm) {
                    throw new Exception('New passwords do not match.');
                }
                if (strlen($new) < 8) {
                    throw new Exception('Password must be at least 8 characters.');
                }

                // Verify current password
                $stmt = $schoolDb->prepare("SELECT password FROM users WHERE id = ? AND school_id = ?");
                $stmt->execute([$userId, $school['id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user || !password_verify($current, $user['password'])) {
                    throw new Exception('Current password is incorrect.');
                }

                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $updateStmt = $schoolDb->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $updateStmt->execute([$hashed, $userId, $school['id']]);

                // Audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                    VALUES (?, ?, ?, 'password_changed', 'users', ?, ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $userId,
                    json_encode(['password_updated' => true]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);

                $response = ['success' => true, 'message' => 'Password changed successfully.'];
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => $e->getMessage()];
            }
            break;

        default:
            $response = ['success' => false, 'message' => 'Unknown action.'];
    }

    echo json_encode($response);
    exit;
}

// Fetch current admin data
$userStmt = $schoolDb->prepare("
    SELECT u.*, GROUP_CONCAT(DISTINCT r.name SEPARATOR ', ') as role_names
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.id = ? AND u.school_id = ?
    GROUP BY u.id
");
$userStmt->execute([$userId, $school['id']]);
$admin = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    error_log("Admin user not found for ID: " . $userId);
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Determine profile photo
if (!empty($admin['profile_photo'])) {
    $profilePhoto = $admin['profile_photo'];
} else {
    // Generate avatar with initials
    $nameParts = explode(' ', $admin['name']);
    $initials = '';
    foreach ($nameParts as $part) {
        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
    }
    $profilePhoto = 'https://ui-avatars.com/api/?name=' . urlencode($initials) . '&size=200&background=0f172a&color=fff&bold=true&length=2';
}

// Helper arrays
$genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$religions = ['Christianity', 'Islam', 'Hinduism', 'Buddhism', 'Traditional', 'Other'];

// Get unread notification count (optional)
$unreadCount = 0;
try {
    $notifStmt = $schoolDb->prepare("SELECT COUNT(*) FROM notifications WHERE school_id = ? AND user_id = ? AND is_read = 0");
    $notifStmt->execute([$school['id'], $userId]);
    $unreadCount = $notifStmt->fetchColumn();
} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

error_log("=== ADMIN PROFILE PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Admin Profile - School Management System">
    <meta name="keywords" content="Admin Profile, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
    <style>
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
        .badge-active {
            background-color: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .badge-inactive {
            background-color: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .custom-accordion-btn {
            background: #f8f9fa;
            border: none;
            width: 100%;
            text-align: left;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .custom-accordion-btn:hover {
            background: #e9ecef;
        }
        .custom-accordion-btn.active {
            background: #e9ecef;
        }
        .custom-accordion-content {
            display: none;
            padding: 20px;
        }
        .avatar-upload-btn {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        .avatar-upload-btn input[type=file] {
            position: absolute;
            opacity: 0;
            right: 0;
            top: 0;
            cursor: pointer;
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Theme Customization Structure Start -->


<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Admin Profile</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light"> / Profile</span>
                </div>
            </div>
        </div>

        <div class="mt-24">
            <div class="row">
                <!-- Left Column - Avatar Card -->
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-body p-24 text-center">
                            <figure class="mb-24 w-150-px h-150-px mx-auto rounded-circle overflow-hidden border border-2 border-primary">
                                <img src="<?php echo htmlspecialchars($profilePhoto); ?>" alt="Admin Avatar" class="w-100 h-100 object-fit-cover" id="avatarImg">
                            </figure>
                            <h2 class="h5 text-primary-light mb-1 fw-semibold"><?php echo htmlspecialchars($admin['name']); ?></h2>
                            <p class="text-muted mb-2"><?php echo htmlspecialchars($admin['email']); ?></p>
                            <div class="d-flex justify-content-center gap-2 mb-3">
                                <span class="badge bg-primary-100 text-primary-600 px-3 py-2"><?php echo ucfirst($admin['user_type']); ?></span>
                                <?php if (!empty($admin['role_names'])): ?>
                                <span class="badge bg-info-100 text-info-600 px-3 py-2"><?php echo htmlspecialchars($admin['role_names']); ?></span>
                                <?php endif; ?>
                            </div>
                            <hr>
                            <form id="photoUploadForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="upload_photo">
                                <div class="avatar-upload-btn">
                                    <button type="button" class="btn btn-outline-primary w-100" onclick="document.getElementById('photoInput').click();">
                                        <i class="ri-camera-line me-2"></i>Change Photo
                                    </button>
                                    <input type="file" id="photoInput" name="photo" accept="image/*" style="display: none;">
                                </div>
                                <small class="text-muted d-block mt-2">Max size 2MB. JPG, PNG, GIF, WEBP.</small>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Profile Tabs -->
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header bg-transparent border-bottom py-16 px-24">
                            <ul class="nav nav-pills bordered-tab" id="profileTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 bg-transparent px-20 py-12" id="profile-info-tab" data-bs-toggle="pill" data-bs-target="#profile-info" type="button" role="tab">
                                        <i class="ri-user-3-line"></i> Profile Information
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link d-flex align-items-center gap-8 text-secondary-light fw-medium text-sm text-hover-primary-600 bg-transparent px-20 py-12" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab">
                                        <i class="ri-shield-keyhole-line"></i> Security
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body p-24">
                            <div class="tab-content" id="profileTabsContent">
                                <!-- Profile Information Tab -->
                                <div class="tab-pane fade show active" id="profile-info" role="tabpanel">
                                    <form id="profileForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="update_profile">
                                        <div class="row g-4">
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Full Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Email Address <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Phone Number</label>
                                                <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Gender</label>
                                                <select class="form-select" name="gender">
                                                    <option value="">Select Gender</option>
                                                    <?php foreach ($genders as $val => $label): ?>
                                                    <option value="<?php echo $val; ?>" <?php echo ($admin['gender'] ?? '') == $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Date of Birth</label>
                                                <input type="date" class="form-control" name="date_of_birth" value="<?php echo htmlspecialchars($admin['date_of_birth'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Blood Group</label>
                                                <select class="form-select" name="blood_group">
                                                    <option value="">Select Blood Group</option>
                                                    <?php foreach ($bloodGroups as $bg): ?>
                                                    <option value="<?php echo $bg; ?>" <?php echo ($admin['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Religion</label>
                                                <select class="form-select" name="religion">
                                                    <option value="">Select Religion</option>
                                                    <?php foreach ($religions as $rel): ?>
                                                    <option value="<?php echo $rel; ?>" <?php echo ($admin['religion'] ?? '') == $rel ? 'selected' : ''; ?>><?php echo $rel; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold text-primary-light">Address</label>
                                                <textarea class="form-control" name="address" rows="3"><?php echo htmlspecialchars($admin['address'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-primary-600 px-5">
                                                    <i class="ri-save-line me-2"></i>Save Changes
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Security Tab -->
                                <div class="tab-pane fade" id="security" role="tabpanel">
                                    <form id="passwordForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="change_password">
                                        <div class="row g-4">
                                            <div class="col-12">
                                                <label class="form-label fw-semibold text-primary-light">Current Password</label>
                                                <input type="password" class="form-control" name="current_password" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">New Password</label>
                                                <input type="password" class="form-control" name="new_password" id="newPassword" required minlength="8">
                                                <small class="text-muted">Minimum 8 characters</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-semibold text-primary-light">Confirm New Password</label>
                                                <input type="password" class="form-control" name="confirm_password" id="confirmPassword" required>
                                            </div>
                                            <div class="col-12 text-end">
                                                <button type="submit" class="btn btn-warning px-5">
                                                    <i class="ri-lock-line me-2"></i>Change Password
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                    <hr class="my-4">
                                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                                        <i class="ri-alert-line fs-4 me-3"></i>
                                        <div>
                                            <strong>Two-Factor Authentication</strong><br>
                                            Two-factor authentication adds an extra layer of security to your account. Enable it to protect your account.
                                        </div>
                                        <button class="btn btn-outline-warning ms-auto" disabled>Coming Soon</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Scripts -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Toast function
    function showToast(message, type = 'success') {
        const toastHtml = `
            <div class="toast ${type} show" role="alert">
                <div class="toast-header">
                    <i class="ri-${type === 'success' ? 'checkbox-circle' : 'error-warning'}-line me-2"></i>
                    <strong class="me-auto">${type === 'success' ? 'Success' : 'Error'}</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">${message}</div>
            </div>
        `;
        $('#toastContainer').append(toastHtml);
        setTimeout(() => $('.toast').first().remove(), 5000);
    }

    // CSRF token
    const csrfToken = '<?php echo $csrfToken; ?>';

    // Photo upload
    $('#photoInput').on('change', function() {
        const formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('action', 'upload_photo');
        formData.append('photo', this.files[0]);

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    // Update avatar image with cache busting
                    $('#avatarImg').attr('src', response.photo_url + '?t=' + new Date().getTime());
                } else {
                    showToast(response.message, 'error');
                }
            },
            error: function() {
                showToast('Upload failed. Please try again.', 'error');
            }
        });
    });

    // Profile form submission
    $('#profileForm').on('submit', function(e) {
        e.preventDefault();
        $.post(window.location.href, $(this).serialize(), function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                // Optionally update name in header if changed
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Password form submission
    $('#passwordForm').on('submit', function(e) {
        e.preventDefault();
        const newPass = $('#newPassword').val();
        const confirmPass = $('#confirmPassword').val();
        if (newPass !== confirmPass) {
            showToast('New passwords do not match.', 'error');
            return;
        }
        $.post(window.location.href, $(this).serialize(), function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                $('#passwordForm')[0].reset();
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Password match indicator (optional)
    $('#confirmPassword').on('keyup', function() {
        const newPass = $('#newPassword').val();
        const confirmPass = $(this).val();
        if (newPass !== confirmPass) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });

    // Sidebar toggles (if any)
    $('.my-sidebar-btn').on('click', function() {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });
});
</script>
</body>
</html>