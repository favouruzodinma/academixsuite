<?php
/**
 * School Notice Board Page
 * Displays and manages all announcements and notices in the school
 *
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_notice_board.log');

error_log("=== NOTICE BOARD PAGE START ===");
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
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'notice-board.php';
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
 * Verify authentication
 */
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

error_log("Authenticated User ID: " . $userId);

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

    // Include GuardianManager for notifications
    $guardianManagerPath = __DIR__ . '/../../../includes/GuardianManager.php';
    if (file_exists($guardianManagerPath)) {
        require_once $guardianManagerPath;
        error_log("GuardianManager loaded successfully");
    }

    $whatsAppServicePath = __DIR__ . '/../../../includes/Services/WhatsAppService.php';
    if (file_exists($whatsAppServicePath)) {
        require_once $whatsAppServicePath;
        error_log("WhatsAppService loaded successfully");
    }

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
}

/**
 * Initialize notification variables
 */
$notificationCount = 0;
$notifications = [];
$whatsAppService = null;
$whatsAppConfigured = false;
$whatsAppAnnouncementEnabled = false;
$whatsAppStatusMessage = 'WhatsApp notification service is unavailable.';
$whatsAppDeliveryStats = [
    'total' => 0,
    'sent' => 0,
    'failed' => 0,
    'skipped' => 0,
];

if ($schoolDb && class_exists('GuardianManager')) {
    try {
        $guardianManager = new GuardianManager($schoolDb, $school['id'], $userId, $userType, $school);

        if (method_exists($guardianManager, 'getNotificationCount')) {
            $notificationCount = $guardianManager->getNotificationCount();
        }

        if (method_exists($guardianManager, 'getNotifications')) {
            $notifications = $guardianManager->getNotifications(5);
        }

    } catch (Exception $e) {
        error_log("ERROR initializing GuardianManager: " . $e->getMessage());
    }
}

if ($schoolDb && class_exists('WhatsAppService')) {
    try {
        $whatsAppService = new WhatsAppService($schoolDb, $school);
        $whatsAppService->ensureTables();
        $whatsAppConfigured = $whatsAppService->isConfigured();
        $whatsAppAnnouncementEnabled = WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'announcements', true);
        $whatsAppStatusMessage = $whatsAppService->configurationStatus();

        $whatsAppStatsStmt = $schoolDb->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped
            FROM whatsapp_notifications
            WHERE school_id = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $whatsAppStatsStmt->execute([$school['id']]);
        $stats = $whatsAppStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $whatsAppDeliveryStats = [
            'total' => (int) ($stats['total'] ?? 0),
            'sent' => (int) ($stats['sent'] ?? 0),
            'failed' => (int) ($stats['failed'] ?? 0),
            'skipped' => (int) ($stats['skipped'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log("ERROR initializing WhatsAppService: " . $e->getMessage());
        $whatsAppService = null;
        $whatsAppConfigured = false;
        $whatsAppStatusMessage = 'WhatsApp notification service could not start.';
    }
}

if (!function_exists('notice_board_column_exists')) {
    function notice_board_column_exists(PDO $db, string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            return false;
        }

        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("notice_board_column_exists: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('notice_board_default_campus_id')) {
    function notice_board_default_campus_id(array $school, array $schoolAuth): int
    {
        foreach ([$schoolAuth['campus_id'] ?? null, $school['campus_id'] ?? null, $school['default_campus_id'] ?? null] as $candidate) {
            $candidate = (int) $candidate;
            if ($candidate > 0) {
                return $candidate;
            }
        }

        return 1;
    }
}

/**
 * Get notice ID from URL if viewing single notice
 */
$noticeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/**
 * Fetch notices/announcements
 */
$notices = [];
$totalNotices = 0;
$totalActive = 0;
$totalExpired = 0;
$totalTargeted = 0;
$selectedNotice = null;
$classes = [];
$sections = [];

if ($schoolDb) {
    try {
        // Get all classes for filter
        $classStmt = $schoolDb->prepare("
            SELECT id, name FROM classes
            WHERE school_id = ? AND is_active = 1
            ORDER BY name
        ");
        $classStmt->execute([$school['id']]);
        $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get all sections for filter
        $sectionStmt = $schoolDb->prepare("
            SELECT s.id, s.name, s.class_id, c.name as class_name
            FROM sections s
            LEFT JOIN classes c ON s.class_id = c.id
            WHERE s.school_id = ? AND s.is_active = 1
            ORDER BY c.name, s.name
        ");
        $sectionStmt->execute([$school['id']]);
        $sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Get notices/announcements
        if ($noticeId > 0) {
            // Fetch single notice
            $noticeStmt = $schoolDb->prepare("
                SELECT a.*, u.name as created_by_name,
                       c.name as class_name, sc.name as section_name,
                       CASE
                           WHEN a.end_date IS NOT NULL AND a.end_date < CURDATE() THEN 'expired'
                           WHEN a.start_date IS NOT NULL AND a.start_date > CURDATE() THEN 'scheduled'
                           ELSE 'active'
                       END as status
                FROM announcements a
                LEFT JOIN users u ON a.created_by = u.id
                LEFT JOIN classes c ON a.class_id = c.id
                LEFT JOIN sections sc ON a.section_id = sc.id
                WHERE a.id = ? AND a.school_id = ?
            ");
            $noticeStmt->execute([$noticeId, $school['id']]);
            $selectedNotice = $noticeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$selectedNotice) {
                error_log("Notice not found with ID: " . $noticeId);
                $_SESSION['toast_error'] = "Notice not found.";
                header("Location: notice-board.php");
                exit;
            }

            // Get just this one notice for the list
            $notices = [$selectedNotice];
        } else {
            // Fetch all notices
            $noticeStmt = $schoolDb->prepare("
                SELECT a.*, u.name as created_by_name,
                       c.name as class_name, sc.name as section_name,
                       CASE
                           WHEN a.end_date IS NOT NULL AND a.end_date < CURDATE() THEN 'expired'
                           WHEN a.start_date IS NOT NULL AND a.start_date > CURDATE() THEN 'scheduled'
                           ELSE 'active'
                       END as status
                FROM announcements a
                LEFT JOIN users u ON a.created_by = u.id
                LEFT JOIN classes c ON a.class_id = c.id
                LEFT JOIN sections sc ON a.section_id = sc.id
                WHERE a.school_id = ?
                ORDER BY
                    CASE
                        WHEN a.is_published = 1 AND (a.end_date IS NULL OR a.end_date >= CURDATE()) THEN 1
                        ELSE 2
                    END,
                    a.created_at DESC
            ");
            $noticeStmt->execute([$school['id']]);
            $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // Calculate totals
        $totalNotices = count($notices);
        foreach ($notices as $notice) {
            $status = $notice['status'] ?? 'active';
            if ($status == 'active') {
                $totalActive++;
            } elseif ($status == 'expired') {
                $totalExpired++;
            }
            if ($notice['target'] != 'all') {
                $totalTargeted++;
            }
        }

        error_log("Fetched " . count($notices) . " notices successfully");

    } catch (Exception $e) {
        error_log("Error fetching notices: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading notice board data.";
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Handle form submissions
 */
$message = '';
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            throw new Exception("Invalid security token. Please refresh the page and try again.");
        }

        if (!$schoolDb) {
            throw new Exception("Database connection not available");
        }

        switch ($action) {
            case 'create_notice':
                // Validate required fields
                if (empty($_POST['title']) || empty($_POST['description'])) {
                    throw new Exception("Title and description are required");
                }

                $target = $_POST['target'] ?? 'all';
                $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
                $sectionId = !empty($_POST['section_id']) ? (int) $_POST['section_id'] : null;
                $startDate = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $endDate = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $isPublished = 1;

                if ($target === 'class' && !$classId) {
                    throw new Exception("Please select a class for a class notice");
                }

                if ($target === 'section' && !$sectionId) {
                    throw new Exception("Please select a section for a section notice");
                }

                $schoolDb->beginTransaction();

                $announcementFields = [
                    'school_id',
                    'title',
                    'description',
                    'target',
                    'class_id',
                    'section_id',
                    'start_date',
                    'end_date',
                    'is_published',
                    'created_by',
                ];
                $announcementValues = [
                    $school['id'],
                    $_POST['title'],
                    $_POST['description'],
                    $target,
                    $classId,
                    $sectionId,
                    $startDate,
                    $endDate,
                    $isPublished,
                    $userId
                ];

                if (notice_board_column_exists($schoolDb, 'announcements', 'campus_id')) {
                    array_splice($announcementFields, 1, 0, ['campus_id']);
                    array_splice($announcementValues, 1, 0, [notice_board_default_campus_id($school, $schoolAuth)]);
                }

                $fieldSql = '`' . implode('`, `', $announcementFields) . '`, `created_at`';
                $placeholders = implode(', ', array_fill(0, count($announcementFields), '?')) . ', NOW()';
                $stmt = $schoolDb->prepare("INSERT INTO announcements ({$fieldSql}) VALUES ({$placeholders})");
                $stmt->execute($announcementValues);

                $noticeId = (int) $schoolDb->lastInsertId();

                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'create', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");

                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $noticeId,
                    json_encode(['title' => $_POST['title']]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);

                $schoolDb->commit();

                $success = true;
                $message = "Notice created successfully!";

                if (!empty($_POST['send_whatsapp'])) {
                    $whatsAppAudiences = $_POST['whatsapp_audiences'] ?? [];
                    $whatsAppAudiences = is_array($whatsAppAudiences) ? $whatsAppAudiences : [$whatsAppAudiences];

                    if (!$whatsAppAnnouncementEnabled) {
                        $message .= ' WhatsApp announcement notifications are disabled in settings.';
                    } elseif ($whatsAppService instanceof WhatsAppService) {
                        $whatsAppResult = $whatsAppService->sendAnnouncement(
                            $noticeId,
                            (string) $_POST['title'],
                            (string) $_POST['description'],
                            $target,
                            $classId,
                            $sectionId,
                            $whatsAppAudiences
                        );
                        $message .= ' ' . ($whatsAppResult['message'] ?? 'WhatsApp processing completed.');
                    } else {
                        $message .= ' WhatsApp was not sent because the notification service is unavailable.';
                    }
                }

                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalNotices = count($notices);

                break;

            case 'edit_notice':
                if (empty($_POST['notice_id']) || empty($_POST['title']) || empty($_POST['description'])) {
                    throw new Exception("Notice ID, title, and description are required");
                }

                $schoolDb->beginTransaction();

                $updateParts = [
                    'title = ?',
                    'description = ?',
                    'target = ?',
                    'class_id = ?',
                    'section_id = ?',
                    'start_date = ?',
                    'end_date = ?',
                    'is_published = ?',
                ];
                if (notice_board_column_exists($schoolDb, 'announcements', 'updated_at')) {
                    $updateParts[] = 'updated_at = NOW()';
                }

                if (($_POST['target'] ?? 'all') === 'class' && empty($_POST['class_id'])) {
                    throw new Exception("Please select a class for a class notice");
                }

                if (($_POST['target'] ?? 'all') === 'section' && empty($_POST['section_id'])) {
                    throw new Exception("Please select a section for a section notice");
                }

                $stmt = $schoolDb->prepare("
                    UPDATE announcements
                    SET " . implode(', ', $updateParts) . "
                    WHERE id = ? AND school_id = ?
                ");

                $stmt->execute([
                    $_POST['title'],
                    $_POST['description'],
                    $_POST['target'] ?? 'all',
                    !empty($_POST['class_id']) ? $_POST['class_id'] : null,
                    !empty($_POST['section_id']) ? $_POST['section_id'] : null,
                    !empty($_POST['start_date']) ? $_POST['start_date'] : null,
                    !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    isset($_POST['is_published']) ? 1 : 0,
                    $_POST['notice_id'],
                    $school['id']
                ]);

                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, new_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'update', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");

                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['notice_id'],
                    json_encode(['updated_fields' => array_keys($_POST)]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);

                $schoolDb->commit();

                $success = true;
                $message = "Notice updated successfully!";

                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                break;

            case 'delete_notice':
                if (empty($_POST['notice_id'])) {
                    throw new Exception("Notice ID is required");
                }

                $schoolDb->beginTransaction();

                // Get notice data for audit log
                $getStmt = $schoolDb->prepare("SELECT title FROM announcements WHERE id = ?");
                $getStmt->execute([$_POST['notice_id']]);
                $noticeData = $getStmt->fetch(PDO::FETCH_ASSOC);

                // Soft delete - just mark as inactive
                $stmt = $schoolDb->prepare("
                    UPDATE announcements
                    SET is_published = 0
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$_POST['notice_id'], $school['id']]);

                // Create audit log
                $auditStmt = $schoolDb->prepare("
                    INSERT INTO audit_logs (
                        school_id, user_id, user_type, action, entity_type,
                        entity_id, old_values, ip_address, user_agent, url, created_at
                    ) VALUES (?, ?, ?, 'delete', 'announcement', ?, ?, ?, ?, ?, NOW())
                ");

                $auditStmt->execute([
                    $school['id'],
                    $userId,
                    $userType,
                    $_POST['notice_id'],
                    json_encode($noticeData),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null
                ]);

                $schoolDb->commit();

                $success = true;
                $message = "Notice deleted successfully!";

                // Refresh notices data
                $noticeStmt->execute([$school['id']]);
                $notices = $noticeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $totalNotices = count($notices);

                break;

            default:
                throw new Exception("Unknown action");
        }

    } catch (Exception $e) {
        if ($schoolDb && $schoolDb->inTransaction()) {
            $schoolDb->rollBack();
        }
        $error = $e->getMessage();
        error_log("Error processing notice action: " . $error);
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? ($success ? $message : '');
$toastError = $_SESSION['toast_error'] ?? $error;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Generate CSRF token
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
$csrfToken = generateCsrfToken();

// Helper function for sanitization
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) return null;
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// Target options array
$targetOptions = [
    'all' => 'Everyone',
    'students' => 'Students Only',
    'teachers' => 'Teachers Only',
    'parents' => 'Parents Only',
    'class' => 'Specific Class',
    'section' => 'Specific Section'
];

error_log("=== NOTICE BOARD PAGE END ===");
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Notice Board - Manage all announcements and notices">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?> - Notice Board</title>

    <!-- Styles -->
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

        .stat-card {
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notice-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .notice-card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border-color: #25A194;
        }
        .notice-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .notice-badge {
            background: #25A194;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .target-badge {
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 12px;
            display: inline-block;
        }

        /* Sidebar styles */
        .my-sidebar {
            transition: transform 0.3s ease;
            transform: translateX(100%);
        }
        .my-sidebar.active {
            transform: translateX(0);
        }
        .edit-sidebar {
            transition: transform 0.3s ease;
            transform: translateX(100%);
        }
        .edit-sidebar.active {
            transform: translateX(0);
        }
        .overlay.active {
            visibility: visible;
            opacity: 1;
        }

        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            margin-left: 8px;
        }

        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 12px;
            margin: 0 8px;
        }

        .table td, .table th {
            vertical-align: middle;
        }

        .action-buttons {
            white-space: nowrap;
        }

        .notice-content {
            max-height: 60px;
            overflow: hidden;
            position: relative;
        }
        .notice-content.expanded {
            max-height: none;
        }
        .read-more {
            color: #25A194;
            cursor: pointer;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
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

    <!-- Theme Customization Structure Start -->
    
    

    <!-- Theme Customization Structure End -->

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php') ?>

    <main class="dashboard-main">
        <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div class="">
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Notice Board</h1>
                    <div class="">
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <?php if ($selectedNotice): ?>
                        <span class="text-secondary-light">/ Notice Details</span>
                        <?php else: ?>
                        <span class="text-secondary-light">/ Notices</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$selectedNotice): ?>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-add-large-line"></i>
                    </span>
                    Add New Notice
                </button>
                <?php else: ?>
                <a href="notice-board.php" class="btn btn-outline-primary d-flex align-items-center gap-6">
                    <span class="d-flex text-md">
                        <i class="ri-arrow-left-line"></i>
                    </span>
                    Back to Notices
                </a>
                <?php endif; ?>
            </div>

            <?php if (!$selectedNotice): ?>
            <!-- Stats Cards -->
            <div class="row gy-4 mb-24">
                <div class="col-xxl-3 col-sm-6">
                    <div class="card shadow-1 radius-8 gradient-bg-end-1 h-100">
                        <div class="card-body p-20">
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                <div class="w-44-px h-44-px bg-warning-600 rounded-circle d-flex justify-content-center align-items-center">
                                    <i class="ri-megaphone-line text-white fs-5"></i>
                                </div>
                                <p class="fw-medium text-primary-light mb-1">Total Notices</p>
                            </div>
                            <h6 class="mb-0"><?php echo number_format($totalNotices); ?></h6>
                            <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                    <?php echo $totalActive; ?> Active
                                </span>
                                Posted Notices
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-3 col-sm-6">
                    <div class="card shadow-1 radius-8 gradient-bg-end-2 h-100">
                        <div class="card-body p-20">
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                <div class="w-44-px h-44-px bg-blue-600 rounded-circle d-flex justify-content-center align-items-center">
                                    <i class="ri-checkbox-circle-line text-white fs-5"></i>
                                </div>
                                <p class="fw-medium text-primary-light mb-1">Active Notices</p>
                            </div>
                            <h6 class="mb-0"><?php echo number_format($totalActive); ?></h6>
                            <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                    <?php echo $totalNotices > 0 ? round(($totalActive / $totalNotices) * 100) : 0; ?>%
                                </span>
                                Currently Active
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-3 col-sm-6">
                    <div class="card shadow-1 radius-8 gradient-bg-end-3 h-100">
                        <div class="card-body p-20">
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                <div class="w-44-px h-44-px bg-purple-600 rounded-circle d-flex justify-content-center align-items-center">
                                    <i class="ri-time-line text-white fs-5"></i>
                                </div>
                                <p class="fw-medium text-primary-light mb-1">Expired Notices</p>
                            </div>
                            <h6 class="mb-0"><?php echo number_format($totalExpired); ?></h6>
                            <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                    <?php echo $totalNotices > 0 ? round(($totalExpired / $totalNotices) * 100) : 0; ?>%
                                </span>
                                Past Notices
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-3 col-sm-6">
                    <div class="card shadow-1 radius-8 gradient-bg-end-4 h-100">
                        <div class="card-body p-20">
                            <div class="d-flex flex-wrap align-items-center gap-3 mb-16">
                                <div class="w-44-px h-44-px bg-primary-600 rounded-circle d-flex justify-content-center align-items-center">
                                    <i class="ri-group-line text-white fs-5"></i>
                                </div>
                                <p class="fw-medium text-primary-light mb-1">Targeted Notices</p>
                            </div>
                            <h6 class="mb-0"><?php echo number_format($totalTargeted); ?></h6>
                            <p class="fw-medium text-sm text-primary-light mt-12 mb-0 d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center gap-1 text-primary-600 text-sm fw-semibold">
                                    <?php echo $totalNotices > 0 ? round(($totalTargeted / $totalNotices) * 100) : 0; ?>%
                                </span>
                                Specific Audience
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-24 border-0 shadow-1">
                <div class="card-body p-20">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-16">
                        <div class="d-flex align-items-center gap-12">
                            <span class="w-48-px h-48-px rounded-circle <?php echo $whatsAppConfigured ? 'bg-success-600' : 'bg-warning-600'; ?> text-white d-flex align-items-center justify-content-center">
                                <i class="ri-whatsapp-line text-2xl"></i>
                            </span>
                            <div>
                                <h6 class="mb-4 text-lg">WhatsApp Notifications</h6>
                                <p class="mb-0 text-sm text-secondary-light"><?php echo htmlspecialchars($whatsAppStatusMessage); ?></p>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-12">
                            <span class="px-16 py-8 radius-8 bg-primary-50 text-primary-600 text-sm fw-semibold">
                                <?php echo number_format($whatsAppDeliveryStats['total']); ?> attempts
                            </span>
                            <span class="px-16 py-8 radius-8 bg-success-50 text-success-600 text-sm fw-semibold">
                                <?php echo number_format($whatsAppDeliveryStats['sent']); ?> sent
                            </span>
                            <span class="px-16 py-8 radius-8 bg-danger-50 text-danger-600 text-sm fw-semibold">
                                <?php echo number_format($whatsAppDeliveryStats['failed']); ?> failed
                            </span>
                            <span class="px-16 py-8 radius-8 bg-warning-50 text-warning-600 text-sm fw-semibold">
                                <?php echo number_format($whatsAppDeliveryStats['skipped']); ?> skipped
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="card mb-24">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <select class="form-select" id="targetFilter">
                                <option value="">All Targets</option>
                                <?php foreach ($targetOptions as $key => $value): ?>
                                <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <button type="button" class="btn btn-outline-primary me-2" onclick="exportToExcel()">
                                <i class="ri-file-excel-line"></i> Export
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="printList()">
                                <i class="ri-printer-line"></i> Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($selectedNotice): ?>
            <!-- Single Notice View -->
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card">
                        <div class="card-body p-32">
                            <div class="d-flex justify-content-between align-items-start mb-24">
                                <div>
                                    <h2 class="fw-semibold mb-2"><?php echo htmlspecialchars($selectedNotice['title']); ?></h2>
                                    <div class="d-flex flex-wrap gap-2 mb-16">
                                        <span class="badge bg-primary">Posted by: <?php echo htmlspecialchars($selectedNotice['created_by_name'] ?? 'System'); ?></span>
                                        <span class="badge bg-info"><?php echo date('d M Y', strtotime($selectedNotice['created_at'])); ?></span>
                                        <span class="badge bg-<?php
                                            echo $selectedNotice['status'] == 'active' ? 'success' :
                                                ($selectedNotice['status'] == 'scheduled' ? 'warning' : 'danger');
                                        ?>">
                                            <?php echo ucfirst($selectedNotice['status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button type="button" class="dropdown-item edit-notice-btn"
                                                    data-notice='<?php echo json_encode($selectedNotice); ?>'>
                                                <i class="ri-edit-line"></i> Edit
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item text-danger"
                                                    onclick="deleteNotice(<?php echo $selectedNotice['id']; ?>, '<?php echo addslashes($selectedNotice['title']); ?>')">
                                                <i class="ri-delete-bin-line"></i> Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <?php if ($selectedNotice['target'] != 'all'): ?>
                            <div class="alert alert-info mb-24">
                                <i class="ri-information-line me-2"></i>
                                This notice is targeted to:
                                <strong>
                                    <?php
                                    echo $targetOptions[$selectedNotice['target']] ?? $selectedNotice['target'];
                                    if ($selectedNotice['class_name']) {
                                        echo ' - ' . $selectedNotice['class_name'];
                                    }
                                    if ($selectedNotice['section_name']) {
                                        echo ' (Section ' . $selectedNotice['section_name'] . ')';
                                    }
                                    ?>
                                </strong>
                            </div>
                            <?php endif; ?>

                            <div class="notice-content-full mb-24">
                                <?php echo nl2br(htmlspecialchars($selectedNotice['description'])); ?>
                            </div>

                            <?php if ($selectedNotice['start_date'] || $selectedNotice['end_date']): ?>
                            <div class="border-top pt-24 mt-24">
                                <div class="row">
                                    <?php if ($selectedNotice['start_date']): ?>
                                    <div class="col-md-6">
                                        <p class="mb-1 fw-semibold">Start Date:</p>
                                        <p class="text-primary-light"><?php echo date('d F Y', strtotime($selectedNotice['start_date'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($selectedNotice['end_date']): ?>
                                    <div class="col-md-6">
                                        <p class="mb-1 fw-semibold">End Date:</p>
                                        <p class="text-primary-light"><?php echo date('d F Y', strtotime($selectedNotice['end_date'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between align-items-center border-top pt-24 mt-24">
                                <a href="notice-board.php" class="btn btn-outline-primary">
                                    <i class="ri-arrow-left-line me-2"></i>Back to Notices
                                </a>
                                <div>
                                    <span class="text-muted">Posted: <?php echo date('d M Y, h:i A', strtotime($selectedNotice['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Notices Grid -->
            <div class="row" id="noticesContainer">
                <?php if (empty($notices)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="ri-megaphone-line fs-1 text-secondary-light mb-3 d-block" style="font-size: 3rem;"></i>
                        <h5>No Notices Found</h5>
                        <p class="text-secondary-light mb-4">Get started by creating your first notice</p>
                        <button type="button" class="btn btn-primary-600 my-sidebar-btn">
                            <i class="ri-add-line"></i> Add New Notice
                        </button>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($notices as $index => $notice):
                        $status = $notice['status'] ?? 'active';
                        $statusColor = $status == 'active' ? 'success' : ($status == 'scheduled' ? 'warning' : 'danger');
                    ?>
                    <div class="col-lg-6 notice-item"
                         data-target="<?php echo $notice['target']; ?>"
                         data-status="<?php echo $status; ?>">
                        <div class="notice-card">
                            <div class="notice-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="w-44-px h-44-px bg-<?php echo $statusColor; ?>-subtle rounded-circle d-flex justify-content-center align-items-center">
                                        <i class="ri-megaphone-line text-<?php echo $statusColor; ?> fs-5"></i>
                                    </div>
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($notice['title']); ?></h5>
                                        <div class="d-flex gap-2">
                                            <span class="badge bg-<?php echo $statusColor; ?>"><?php echo ucfirst($status); ?></span>
                                            <span class="badge bg-info"><?php echo date('d M Y', strtotime($notice['created_at'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a href="notice-board.php?id=<?php echo $notice['id']; ?>" class="dropdown-item">
                                                <i class="ri-eye-line"></i> View
                                            </a>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item edit-notice-btn"
                                                    data-notice='<?php echo json_encode($notice); ?>'>
                                                <i class="ri-edit-line"></i> Edit
                                            </button>
                                        </li>
                                        <li>
                                            <hr class="dropdown-divider">
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item text-danger"
                                                    onclick="deleteNotice(<?php echo $notice['id']; ?>, '<?php echo addslashes($notice['title']); ?>')">
                                                <i class="ri-delete-bin-line"></i> Delete
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="notice-content" id="notice-content-<?php echo $notice['id']; ?>">
                                <?php echo nl2br(htmlspecialchars(substr($notice['description'], 0, 150))); ?>
                                <?php if (strlen($notice['description']) > 150): ?>
                                <span class="read-more" onclick="toggleContent(<?php echo $notice['id']; ?>)">... Read more</span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-16">
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="target-badge" style="background: #e3f2fd; color: #1976d2;">
                                        <i class="ri-group-line me-1"></i>
                                        <?php echo $targetOptions[$notice['target']] ?? $notice['target']; ?>
                                    </span>
                                    <?php if ($notice['class_name']): ?>
                                    <span class="target-badge" style="background: #f3e5f5; color: #7b1fa2;">
                                        <i class="ri-school-line me-1"></i>
                                        <?php echo htmlspecialchars($notice['class_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($notice['section_name']): ?>
                                    <span class="target-badge" style="background: #e8f5e8; color: #2e7d32;">
                                        <i class="ri-grid-line me-1"></i>
                                        Section <?php echo htmlspecialchars($notice['section_name']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-16 pt-16 border-top">
                                <small class="text-muted">
                                    <i class="ri-user-line me-1"></i>
                                    <?php echo htmlspecialchars($notice['created_by_name'] ?? 'System'); ?>
                                </small>
                                <?php if ($notice['end_date']): ?>
                                <small class="text-muted">
                                    <i class="ri-calendar-line me-1"></i>
                                    Ends: <?php echo date('d M Y', strtotime($notice['end_date'])); ?>
                                </small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    </main>

     <!-- Add Notice Sidebar -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Notice</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_notice">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" class="form-control" placeholder="Enter notice title" required>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Target Audience</label>
                        <select name="target" class="form-select" id="targetSelect">
                            <?php foreach ($targetOptions as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-12" id="classSelectWrapper" style="display: none;">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select Class</label>
                        <select name="class_id" class="form-select">
                            <option value="">Choose Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-12" id="sectionSelectWrapper" style="display: none;">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Select Section</label>
                        <select name="section_id" class="form-select">
                            <option value="">Choose Section</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?> (<?php echo htmlspecialchars($section['class_name']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter notice description..." required></textarea>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_important" class="form-check-input" id="is_important" value="1">
                        <label class="form-check-label" for="is_important">Mark as Important</label>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="border radius-12 p-16 bg-success-50">
                        <div class="d-flex align-items-start gap-12">
                            <span class="w-40-px h-40-px rounded-circle bg-success-600 text-white d-flex align-items-center justify-content-center flex-shrink-0">
                                <i class="ri-whatsapp-line text-xl"></i>
                            </span>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-start justify-content-between gap-12">
                                    <div>
                                        <label class="form-check-label fw-semibold text-primary-light mb-4" for="send_whatsapp">
                                            Send WhatsApp notification
                                        </label>
                                        <p class="text-sm text-secondary-light mb-0">
                                            Notify parents and teachers using the approved WhatsApp announcement template.
                                        </p>
                                    </div>
                                    <div class="form-switch switch-primary d-flex align-items-center">
                                        <input type="checkbox"
                                               name="send_whatsapp"
                                               class="form-check-input"
                                               id="send_whatsapp"
                                               value="1"
                                               <?php echo ($whatsAppConfigured && $whatsAppAnnouncementEnabled) ? '' : 'disabled'; ?>>
                                    </div>
                                </div>

                                <div class="mt-12 d-flex flex-wrap gap-12">
                                    <label class="form-check d-flex align-items-center gap-8 mb-0">
                                        <input type="checkbox"
                                               name="whatsapp_audiences[]"
                                               class="form-check-input"
                                               value="parents"
                                               checked
                                               <?php echo $whatsAppConfigured ? '' : 'disabled'; ?>>
                                        <span class="text-sm">Parents</span>
                                    </label>
                                    <label class="form-check d-flex align-items-center gap-8 mb-0">
                                        <input type="checkbox"
                                               name="whatsapp_audiences[]"
                                               class="form-check-input"
                                               value="teachers"
                                               checked
                                               <?php echo $whatsAppConfigured ? '' : 'disabled'; ?>>
                                        <span class="text-sm">Teachers</span>
                                    </label>
                                </div>

                                <div class="mt-12 text-sm <?php echo $whatsAppConfigured ? 'text-success-600' : 'text-warning-600'; ?>">
                                    <i class="<?php echo $whatsAppConfigured ? 'ri-checkbox-circle-line' : 'ri-error-warning-line'; ?> me-1"></i>
                                    <?php echo htmlspecialchars($whatsAppStatusMessage); ?>
                                </div>
                                <p class="text-xs text-secondary-light mt-8 mb-0">
                                    Template expected: <strong><?php echo htmlspecialchars(function_exists('env') ? env('WHATSAPP_ANNOUNCEMENT_TEMPLATE', 'school_announcement') : 'school_announcement'); ?></strong>.
                                    Body variables should be: recipient name, school name, notice title, notice message, portal URL.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Publish Notice
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Notice Sidebar -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Notice</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editNoticeForm">
            <input type="hidden" name="action" value="edit_notice">
            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
            <input type="hidden" name="notice_id" id="edit_notice_id">

            <div class="row g-3">
                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Target Audience</label>
                        <select name="target" id="edit_target" class="form-select">
                            <?php foreach ($targetOptions as $key => $value): ?>
                            <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-12" id="edit_class_wrapper">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
                        <select name="class_id" id="edit_class_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-12" id="edit_section_wrapper">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Section</label>
                        <select name="section_id" id="edit_section_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?php echo $section['id']; ?>"><?php echo htmlspecialchars($section['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Date</label>
                        <input type="date" name="start_date" id="edit_start_date" class="form-control">
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" id="edit_end_date" class="form-control">
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4"></textarea>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_important" class="form-check-input" id="edit_is_important" value="1">
                        <label class="form-check-label" for="edit_is_important">Mark as Important</label>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_published" class="form-check-input" id="edit_is_published" value="1">
                        <label class="form-check-label" for="edit_is_published">Published</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Notice
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- View Notice Modal -->
    <div class="modal fade" id="viewNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewNoticeTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Posted Date:</strong> <span id="viewNoticeDate"></span>
                        </div>
                        <div class="col-md-6">
                            <strong>Target:</strong> <span id="viewNoticeTarget"></span>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Start Date:</strong> <span id="viewNoticeStart"></span>
                        </div>
                        <div class="col-md-6">
                            <strong>End Date:</strong> <span id="viewNoticeEnd"></span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong>Posted By:</strong> <span id="viewNoticeAuthor"></span>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <h6>Description:</h6>
                        <p id="viewNoticeDescription" class="text-muted"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteNoticeModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-1 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Notice</h6>
                    <p class="mb-24" id="deleteNoticeMessage">Are you sure you want to delete this notice?</p>
                    <form method="POST" id="deleteNoticeForm">
                        <input type="hidden" name="action" value="delete_notice">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="notice_id" id="delete_notice_id">
                        <div class="d-flex align-items-center justify-content-center gap-3">
                            <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger-600 border border-danger-600 text-md px-24 py-12 radius-8">
                                Yes, Delete
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize Bootstrap toasts
            $('.toast').toast({
                autohide: true,
                delay: 5000
            });
            $('.toast').toast('show');

            // Current year
            $('.current-year').text(new Date().getFullYear());

            // Initialize DataTable
            var table = $('#noticesTable').DataTable({
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, "All"]],
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
                },
                columnDefs: [
                    { orderable: false, targets: [0, 7] }
                ]
            });

            // Select All checkbox
            $('#selectAll').on('click', function() {
                $('.form-check-input').prop('checked', this.checked);
            });

            // Sidebar toggles
            $('.my-sidebar-btn').on('click', function () {
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });
            $('.close-my-sidebar, .overlay').on('click', function () {
                $('.my-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Edit sidebar
            $('.edit-notice-btn').on('click', function () {
                const noticeData = $(this).data('notice');

                // Populate form
                $('#edit_notice_id').val(noticeData.id);
                $('#edit_title').val(noticeData.title);
                $('#edit_target').val(noticeData.target || 'all');
                $('#edit_class_id').val(noticeData.class_id || '');
                $('#edit_section_id').val(noticeData.section_id || '');
                $('#edit_start_date').val(noticeData.start_date || '');
                $('#edit_end_date').val(noticeData.end_date || '');
                $('#edit_description').val(noticeData.description || '');
                $('#edit_is_important').prop('checked', noticeData.is_important == 1);
                $('#edit_is_published').prop('checked', noticeData.is_published == 1);

                // Show/hide class/section based on target
                toggleTargetFields(noticeData.target);

                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });

            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Target select change handler for add form
            $('#targetSelect').on('change', function() {
                toggleTargetFields($(this).val());
            });

            // Target select change handler for edit form
            $('#edit_target').on('change', function() {
                toggleTargetFields($(this).val());
            });

            // Filter functionality
            $('#statusFilter, #targetFilter').on('change', function() {
                const status = $('#statusFilter').val();
                const target = $('#targetFilter').val();

                $.fn.dataTable.ext.search.push(
                    function(settings, data, dataIndex) {
                        const row = table.row(dataIndex).node();
                        const rowStatus = $(row).data('status');
                        const rowTarget = $(row).data('target');

                        let statusMatch = true;
                        let targetMatch = true;

                        if (status && rowStatus != status) {
                            statusMatch = false;
                        }

                        if (target && rowTarget != target) {
                            targetMatch = false;
                        }

                        return statusMatch && targetMatch;
                    }
                );

                table.draw();
                $.fn.dataTable.ext.search.pop();
            });

            // Custom search for navbar
            $('.navbar-search input').on('keyup', function() {
                table.search(this.value).draw();
            });

            // Initialize flatpickr for date inputs
            flatpickr("input[type=date]", {
                dateFormat: "Y-m-d"
            });
        });

        // Toggle target specific fields
        function toggleTargetFields(target) {
            if (target === 'class') {
                $('#classSelectWrapper, #edit_class_wrapper').show();
                $('#sectionSelectWrapper, #edit_section_wrapper').hide();
            } else if (target === 'section') {
                $('#classSelectWrapper, #edit_class_wrapper').show();
                $('#sectionSelectWrapper, #edit_section_wrapper').show();
            } else {
                $('#classSelectWrapper, #edit_class_wrapper, #sectionSelectWrapper, #edit_section_wrapper').hide();
            }
        }

        // View notice function
        function viewNotice(noticeId) {
            // Find the notice data from the table
            const row = $(`button[onclick="viewNotice(${noticeId})"]`).closest('tr');

            $('#viewNoticeTitle').text(row.find('td:eq(2) strong').text().trim());
            $('#viewNoticeDate').text(row.find('td:eq(1) .notice-date').text().trim());
            $('#viewNoticeTarget').text(row.find('td:eq(4)').text().trim());
            $('#viewNoticeStart').text(row.find('td:eq(1) small').text().replace('Starts:', '').trim() || 'N/A');
            $('#viewNoticeEnd').text(row.find('td:eq(5) small').text().replace('days left', '').trim() || 'N/A');
            $('#viewNoticeAuthor').text(row.find('td:eq(6)').text().trim());
            $('#viewNoticeDescription').text(row.find('td:eq(3)').attr('title') || 'No description');

            $('#viewNoticeModal').modal('show');
        }

        // Delete notice function
        function deleteNotice(noticeId, noticeTitle) {
            $('#delete_notice_id').val(noticeId);
            $('#deleteNoticeMessage').text('Are you sure you want to delete "' + noticeTitle + '"? This action cannot be undone.');
            $('#deleteNoticeModal').modal('show');
        }

        // Export to Excel
        function exportToExcel() {
            let csv = "Date,Title,Description,Target,Status,Posted By\n";

            $('#noticesTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const date = $(this).find('td:eq(1)').text().trim();
                    const title = $(this).find('td:eq(2)').text().trim();
                    const description = $(this).find('td:eq(3)').text().trim();
                    const target = $(this).find('td:eq(4)').text().trim();
                    const status = $(this).find('td:eq(5)').text().trim();
                    const author = $(this).find('td:eq(6)').text().trim();

                    csv += `"${date}","${title}","${description}","${target}","${status}","${author}"\n`;
                }
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'notice-board.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        // Print list
        function printList() {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Notice Board - <?php echo htmlspecialchars($school['name']); ?></title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h1 { color: #25A194; }
                        h2 { color: #333; margin-top: 10px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th { background: #f8f9fa; text-align: left; padding: 12px; }
                        td { padding: 10px; border-bottom: 1px solid #dee2e6; }
                        .badge {
                            display: inline-block;
                            padding: 3px 8px;
                            border-radius: 12px;
                            font-size: 12px;
                            background: #e9ecef;
                        }
                        .badge-success { background: #d4edda; color: #155724; }
                        .badge-danger { background: #f8d7da; color: #721c24; }
                        .badge-warning { background: #fff3cd; color: #856404; }
                    </style>
                </head>
                <body>
                    <h1><?php echo htmlspecialchars($school['name']); ?></h1>
                    <h2>Notice Board</h2>
                    <p>Generated on: ${new Date().toLocaleString()}</p>

                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th>Posted By</th>
                            </tr>
                        </thead>
                        <tbody>
            `);

            $('#noticesTable tbody tr').each(function() {
                if ($(this).find('td').length > 1) {
                    const date = $(this).find('td:eq(1)').text().trim();
                    const title = $(this).find('td:eq(2)').text().trim();
                    const description = $(this).find('td:eq(3)').text().trim();
                    const target = $(this).find('td:eq(4)').text().trim();
                    const status = $(this).find('td:eq(5)').text().trim();
                    const author = $(this).find('td:eq(6)').text().trim();

                    printWindow.document.write(`
                        <tr>
                            <td>${date}</td>
                            <td>${title}</td>
                            <td>${description}</td>
                            <td>${target}</td>
                            <td>${status}</td>
                            <td>${author}</td>
                        </tr>
                    `);
                }
            });

            printWindow.document.write(`
                        </tbody>
                    </table>
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.print();
        }
    </script>
</body>
</html>
