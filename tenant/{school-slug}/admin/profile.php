<?php
/**
 * Admin Profile Page
 * Allows admin to view and update their profile information and change password.
 */

// Error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/admin_profile.log');

require_once __DIR__ . '/../../../includes/autoload.php';
session_start();

// Get school slug from router globals
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    header('HTTP/1.1 400 Bad Request');
    exit('School identifier missing');
}

// Load school info
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}
if (empty($school)) {
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}

// Authentication check
if (empty($_SESSION['school_auth']) || $_SESSION['school_auth']['school_slug'] !== $schoolSlug) {
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

$userId = $_SESSION['school_auth']['user_id'] ?? 0;
$userType = $_SESSION['school_auth']['user_type'] ?? '';
if ($userType !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied. Admin privileges required.');
}

// Database connections
$platformDb = Database::getPlatformConnection();
$schoolDb = Database::getSchoolConnection($school['database_name']);

// CSRF token
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
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
$currentPage = basename(__FILE__);
        exit;
    }
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action'];

    switch ($action) {
        case 'update_profile':
            // Handle profile update (text fields)
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

                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                    VALUES (?, ?, 'admin', 'profile_updated', 'users', ?, ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userId,
                    json_encode(['fields' => ['name', 'email', 'phone']]),
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
            // Handle profile photo upload
            try {
                if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception('No file uploaded or upload error.');
                }

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                ];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                finfo_close($finfo);

                if (!isset($allowed[$mime])) {
                    throw new Exception('Only JPG, PNG, GIF, and WEBP images are allowed.');
                }

                // SECURITY: derive the extension from the verified MIME, NOT
                // from the user-supplied filename. Previously an attacker
                // could upload a .php with a PNG MIME and the file would be
                // saved as foo.php.
                $ext = $allowed[$mime];
                $filename = 'profile_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $uploadDir = __DIR__ . '/../../../uploads/profile_photos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $uploadPath = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath)) {
                    throw new Exception('Failed to save uploaded file.');
                }

                // Update user record with new photo path
                $photoPath = '/uploads/profile_photos/' . $filename;
                $stmt = $schoolDb->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $stmt->execute([$photoPath, $userId, $school['id']]);

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
                $update = $schoolDb->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND school_id = ?");
                $update->execute([$hashed, $userId, $school['id']]);

                // Audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (school_id, user_id, user_type, action, entity_type, entity_id, new_values, ip_address, user_agent, url, created_at)
                    VALUES (?, ?, 'admin', 'password_changed', 'users', ?, ?, ?, ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $school['id'],
                    $userId,
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
    SELECT u.*, r.name as role_name
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    WHERE u.id = ? AND u.school_id = ?
");
$userStmt->execute([$userId, $school['id']]);
$admin = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    die('Admin user not found.');
}

// Helper for gender options, blood groups, etc.
$genders = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
$religions = ['Christianity', 'Islam', 'Hinduism', 'Buddhism', 'Traditional', 'Other'];

// Get school settings for additional info
$settings = [];
$settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
$settingsStmt->execute([$school['id']]);
while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}
$currencySymbol = $settings['currency_symbol'] ?? '₦';

// Get unread notification count (optional)
$notifStmt = $schoolDb->prepare("SELECT COUNT(*) FROM notifications WHERE school_id = ? AND user_id = ? AND is_read = 0");
$notifStmt->execute([$school['id'], $userId]);
$unreadCount = $notifStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - <?php echo htmlspecialchars($school['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
    <style>
        .avatar-preview {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background-size: cover;
            background-position: center;
            border: 2px solid #dee2e6;
            margin: 0 auto 15px;
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
        }
        .toast.success { border-left-color: #28a745; }
        .toast.error { border-left-color: #dc3545; }
        .toast.info { border-left-color: #17a2b8; }
        .nav-pills .nav-link {
            color: #495057;
            font-weight: 500;
        }
        .nav-pills .nav-link.active {
            background-color: #25A194;
            color: #fff;
        }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- Theme Customization Structure (optional) -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php'); ?>

    <main class="dashboard-main">
        <!-- Navbar -->
        
        <?php include_once('includes/header.php'); ?>
</div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                        <div class="dropdown">
                            <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <?php if ($unreadCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div>
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Admin Profile</h1>
                    <div>
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <span class="text-secondary-light"> / Profile</span>
                    </div>
                </div>
            </div>

            <!-- Profile Card -->
            <div class="row">
                <div class="col-lg-4">
                    <div class="card shadow-1 radius-12">
                        <div class="card-body text-center p-32">
                            <div class="avatar-preview" id="avatarPreview" style="background-image: url('<?php echo htmlspecialchars($admin['profile_photo'] ?? 'https://academixsuite.com/tenant/assets/images/thumbs/avatar-img1.png'); ?>');"></div>
                            <h5 class="mb-1"><?php echo htmlspecialchars($admin['name']); ?></h5>
                            <p class="text-muted mb-3"><?php echo htmlspecialchars($admin['role_name'] ?? 'Administrator'); ?></p>
                            <div class="d-flex justify-content-center gap-2 mb-4">
                                <span class="badge bg-primary"><?php echo htmlspecialchars($admin['email']); ?></span>
                                <?php if (!empty($admin['phone'])): ?>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($admin['phone']); ?></span>
                                <?php endif; ?>
                            </div>
                            <form id="photoUploadForm" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="upload_photo">
                                <div class="mb-3">
                                    <label for="photo" class="form-label">Change Profile Photo</label>
                                    <input class="form-control form-control-sm" type="file" id="photo" name="photo" accept="image/*">
                                </div>
                                <button type="submit" class="btn btn-primary-600 btn-sm w-100">Upload New Photo</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <div class="card shadow-1 radius-12">
                        <div class="card-header bg-white border-bottom">
                            <ul class="nav nav-pills" id="profileTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="profile-tab" data-bs-toggle="pill" data-bs-target="#profile" type="button" role="tab">Profile Information</button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="security-tab" data-bs-toggle="pill" data-bs-target="#security" type="button" role="tab">Security</button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content" id="profileTabsContent">
                                <!-- Profile Information Tab -->
                                <div class="tab-pane fade show active" id="profile" role="tabpanel">
                                    <form id="profileForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="update_profile">
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Full Name *</label>
                                                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Email *</label>
                                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Phone</label>
                                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Gender</label>
                                                <select name="gender" class="form-select">
                                                    <option value="">Select</option>
                                                    <?php foreach ($genders as $val => $label): ?>
                                                    <option value="<?php echo $val; ?>" <?php echo ($admin['gender'] ?? '') == $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Date of Birth</label>
                                                <input type="date" name="date_of_birth" class="form-control" value="<?php echo htmlspecialchars($admin['date_of_birth'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Blood Group</label>
                                                <select name="blood_group" class="form-select">
                                                    <option value="">Select</option>
                                                    <?php foreach ($bloodGroups as $bg): ?>
                                                    <option value="<?php echo $bg; ?>" <?php echo ($admin['blood_group'] ?? '') == $bg ? 'selected' : ''; ?>><?php echo $bg; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Religion</label>
                                                <select name="religion" class="form-select">
                                                    <option value="">Select</option>
                                                    <?php foreach ($religions as $rel): ?>
                                                    <option value="<?php echo $rel; ?>" <?php echo ($admin['religion'] ?? '') == $rel ? 'selected' : ''; ?>><?php echo $rel; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Address</label>
                                                <textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($admin['address'] ?? ''); ?></textarea>
                                            </div>
                                            <div class="col-12 mt-3">
                                                <button type="submit" class="btn btn-primary-600">Save Changes</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>

                                <!-- Security Tab -->
                                <div class="tab-pane fade" id="security" role="tabpanel">
                                    <form id="passwordForm">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="change_password">
                                        
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label">Current Password</label>
                                                <input type="password" name="current_password" class="form-control" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">New Password</label>
                                                <input type="password" name="new_password" class="form-control" required minlength="8">
                                                <small class="text-muted">Minimum 8 characters</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Confirm New Password</label>
                                                <input type="password" name="confirm_password" class="form-control" required>
                                            </div>
                                            <div class="col-12 mt-3">
                                                <button type="submit" class="btn btn-warning">Change Password</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
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

    <!-- Scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        // Toast function
        function showToast(message, type = 'success') {
            const toastHtml = `
                <div class="toast ${type} show" role="alert">
                    <div class="toast-header">
                        <i class="ri-${type === 'success' ? 'checkbox-circle' : type === 'error' ? 'error-warning' : 'information'}-line me-2"></i>
                        <strong class="me-auto">${type === 'success' ? 'Success' : type === 'error' ? 'Error' : 'Info'}</strong>
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

        // Profile form submission
        $('#profileForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            $.post(window.location.href, formData, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    // Optionally update name in avatar section
                    $('#profile-tab').text('Profile Information'); // no change needed
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed.', 'error'));
        });

        // Photo upload
        $('#photoUploadForm').on('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
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
                        // Update avatar preview
                        $('#avatarPreview').css('background-image', 'url(' + response.photo_url + '?t=' + new Date().getTime() + ')');
                    } else {
                        showToast(response.message, 'error');
                    }
                },
                error: function() {
                    showToast('Upload failed.', 'error');
                }
            });
        });

        // Password change
        $('#passwordForm').on('submit', function(e) {
            e.preventDefault();
            const formData = $(this).serialize();
            $.post(window.location.href, formData, function(response) {
                if (response.success) {
                    showToast(response.message, 'success');
                    $('#passwordForm')[0].reset();
                } else {
                    showToast(response.message, 'error');
                }
            }).fail(() => showToast('Request failed.', 'error'));
        });

        // Sidebar toggles (if any)
        $('.sidebar-mobile-toggle').on('click', function() {
            $('.sidebar').toggleClass('active');
        });
    </script>
</body>
</html>