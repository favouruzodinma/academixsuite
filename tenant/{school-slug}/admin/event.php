<?php
/**
 * School Event Page
 * Manages school events with calendar view and email notifications
 *
 * @package AcademixSuite
 * @version 2.1
 */

// --- Error Reporting & Logging ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$logDir = __DIR__ . '/../../../logs/';
if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    $logDir = sys_get_temp_dir() . '/academix_logs/';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
}
ini_set('error_log', $logDir . 'school_event.log');

error_log("=== EVENT PAGE START ===");
error_log("Script: " . __FILE__);

// --- Constants ---
// IS_LOCAL is self-defining via config/constants.php; never force it true.
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');

// --- Safe Session Start ---
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'read_and_close'  => false,
        'cookie_secure'   => !IS_LOCAL,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax'
    ]);
    error_log("Session started");
}

// --- Get School Slug (from router or URL) ---
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? $_GET['school_slug'] ?? '';
if (empty($schoolSlug)) {
    error_log("ERROR: Missing school identifier");
    header('HTTP/1.1 400 Bad Request');
    die('School identifier missing');
}
define('CURRENT_SCHOOL_SLUG', $schoolSlug);

// --- Load School Data ---
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$school = $schoolData;
if (empty($school) && isset($_SESSION['school_info'][$schoolSlug])) {
    $school = $_SESSION['school_info'][$schoolSlug];
}

if (empty($school)) {
    error_log("ERROR: School data not found for slug: " . $schoolSlug);
    header("Location: ../../login.php?school_slug=" . urlencode($schoolSlug));
    exit;
}
error_log("School: ID={$school['id']}, Name={$school['name']}");

// --- Authentication Check ---
$isAuthenticated = isset($_SESSION['school_auth']) &&
                   is_array($_SESSION['school_auth']) &&
                   ($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug;

if (!$isAuthenticated) {
    error_log("Not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

$schoolAuth = $_SESSION['school_auth'];

$currentPage = basename(__FILE__);
$userId     = (int)($schoolAuth['user_id'] ?? 0);
$userType   = $schoolAuth['user_type'] ?? '';
error_log("User: ID=$userId, Type=$userType");

// --- Load Core Files ---
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file missing");
    }
    require_once $autoloadPath;
    error_log("Autoload loaded");

    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }

    // Include EventManager
    $eventManagerPath = __DIR__ . '/../../../includes/EventManager.php';
    if (!file_exists($eventManagerPath)) {
        throw new Exception("EventManager file missing");
    }
    require_once $eventManagerPath;
    error_log("EventManager loaded");

    // Optional GuardianManager
    $guardianPath = __DIR__ . '/../../../includes/GuardianManager.php';
    if (file_exists($guardianPath)) {
        require_once $guardianPath;
        error_log("GuardianManager loaded");
    }
} catch (Exception $e) {
    error_log("CRITICAL: " . $e->getMessage());
    http_response_code(500);
    die("System configuration failed. Please try again later.");
}

// --- Database Connections ---
$platformDb = null;
$schoolDb   = null;

try {
    $platformDb = Database::getPlatformConnection();
    error_log("Platform DB connected");
} catch (Exception $e) {
    error_log("Platform DB error: " . $e->getMessage());
}

try {
    if (empty($school['database_name'])) {
        throw new Exception("No database name configured for school");
    }
    $schoolDb = Database::getSchoolConnection($school['database_name']);
    if (!$schoolDb) {
        throw new Exception("School DB connection returned null");
    }
    error_log("School DB connected: " . $school['database_name']);
} catch (Exception $e) {
    error_log("School DB error: " . $e->getMessage());
    $_SESSION['toast_error'] = "Database connection failed. Contact support.";
    $schoolDb = null;
}

// --- Initialize Event Manager ---
$eventManager = null;
$eventStats   = [];

if ($schoolDb && $platformDb) {
    try {
        $eventManager = new EventManager($schoolDb, $platformDb, $school['id'], $userId, $userType, $school);
        error_log("EventManager initialized");

        if (method_exists($eventManager, 'getEventStats')) {
            $eventStats = $eventManager->getEventStats();
        }
    } catch (Exception $e) {
        error_log("EventManager init error: " . $e->getMessage());
        $_SESSION['toast_error'] = "Failed to initialize event system.";
    }
}

// --- Notifications (optional) ---
$notificationCount = 0;
$notifications     = [];

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
        error_log("GuardianManager error: " . $e->getMessage());
    }
}

// --- Request Handling ---
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$events  = [];
$upcomingEvents = [];
$selectedEvent  = null;
$calendarEvents = [];

if ($eventManager) {
    try {
        if ($eventId > 0) {
            $selectedEvent = $eventManager->getEventById($eventId);
            if (!$selectedEvent) {
                $_SESSION['toast_error'] = "Event not found.";
                header("Location: event.php");
                exit;
            }
        }

        $calendarEvents = $eventManager->getCalendarEvents();
        $upcomingEvents = $eventManager->getUpcomingEvents(10);
        error_log("Fetched " . count($calendarEvents) . " events");
    } catch (Exception $e) {
        error_log("Event fetch error: " . $e->getMessage());
        $_SESSION['toast_error'] = "Error loading events.";
    }
}

// --- Event Types & Colors ---
$eventTypes = [
    'holiday'    => 'Holiday',
    'exam'       => 'Exam',
    'meeting'    => 'Meeting',
    'celebration'=> 'Celebration',
    'sports'     => 'Sports Event',
    'other'      => 'Other'
];

$eventColors = [
    'holiday'    => '#dc3545',
    'exam'       => '#fd7e14',
    'meeting'    => '#0d6efd',
    'celebration'=> '#198754',
    'sports'     => '#6f42c1',
    'other'      => '#6c757d'
];

// --- AJAX Handlers ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    try {
        if ($_GET['ajax'] === 'get_events' && $eventManager) {
            $start = $_GET['start'] ?? null;
            $end   = $_GET['end'] ?? null;
            $events = ($start && $end)
                ? $eventManager->getEventsByDateRange($start, $end)
                : $eventManager->getCalendarEvents();
            echo json_encode(['success' => true, 'events' => $events]);
            exit;
        }

        if ($_GET['ajax'] === 'get_event' && isset($_GET['event_id']) && $eventManager) {
            $event = $eventManager->getEventById($_GET['event_id']);
            echo json_encode($event ? ['success' => true, 'event' => $event] : ['success' => false, 'error' => 'Not found']);
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Server error']);
        exit;
    }
}

// --- CSRF Token ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// --- Handle POST (Create, Update, Delete) ---
$message = '';
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
        $error = "Invalid security token.";
        error_log("CSRF failure");
    } else {
        try {
            if (!$eventManager) {
                throw new Exception("Event manager not available");
            }

            // Determine if notification should be sent (checkbox present)
            $sendNotification = isset($_POST['send_notification']);

            switch ($action) {
                case 'create_event':
                    $eventData = [
                        'title'       => $_POST['title'],
                        'description' => $_POST['description'] ?? '',
                        'type'        => $_POST['type'] ?? 'other',
                        'start_date'  => $_POST['start_date'],
                        'end_date'    => $_POST['end_date'] ?: $_POST['start_date'],
                        'start_time'  => $_POST['start_time'] ?: null,
                        'end_time'    => $_POST['end_time'] ?: null,
                        'venue'       => $_POST['venue'] ?: null,
                        'is_public'   => 1
                    ];
                    $result = $eventManager->createEvent($eventData, $sendNotification);
                    break;

                case 'edit_event':
                    if (empty($_POST['event_id'])) throw new Exception("Event ID required");
                    $eventData = [
                        'title'       => $_POST['title'],
                        'description' => $_POST['description'] ?? '',
                        'type'        => $_POST['type'] ?? 'other',
                        'start_date'  => $_POST['start_date'],
                        'end_date'    => $_POST['end_date'] ?: $_POST['start_date'],
                        'start_time'  => $_POST['start_time'] ?: null,
                        'end_time'    => $_POST['end_time'] ?: null,
                        'venue'       => $_POST['venue'] ?: null,
                        'is_public'   => 1
                    ];
                    $result = $eventManager->updateEvent($_POST['event_id'], $eventData, $sendNotification);
                    break;

                case 'delete_event':
                    if (empty($_POST['event_id'])) throw new Exception("Event ID required");
                    $result = $eventManager->deleteEvent($_POST['event_id'], true);
                    break;

                default:
                    throw new Exception("Unknown action");
            }

            if ($result['success']) {
                $success = true;
                $message = $result['message'];
            } else {
                $error = $result['message'];
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            error_log("POST error: " . $error);
        }
    }
}

// Collect toast messages
$toastSuccess = $_SESSION['toast_success'] ?? ($success ? $message : '');
$toastError   = $_SESSION['toast_error'] ?? $error;
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Sanitization helper
if (!function_exists('sanitize')) {
    function sanitize($input) {
        return $input === null ? null : htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

error_log("=== EVENT PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="School Events Calendar - Manage all school events and activities">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($school['name']) ?> | <?= APP_NAME ?> - Events Calendar</title>
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
        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; }
        .toast { min-width: 300px; background: white; border-left: 4px solid; box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin-bottom: 10px; animation: slideIn 0.3s ease; }
        .toast.success { border-left-color: #28a745; }
        .toast.success .toast-header { background-color: #d4edda; color: #155724; }
        .toast.error { border-left-color: #dc3545; }
        .toast.error .toast-header { background-color: #f8d7da; color: #721c24; }
        @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .event-item { transition: all 0.3s ease; }
        .event-item:hover { background-color: #f8f9fa; border-radius: 8px; padding-left: 10px; }
        .my-sidebar, .edit-sidebar { transition: transform 0.3s ease; transform: translateX(100%); }
        .my-sidebar.active, .edit-sidebar.active { transform: translateX(0); }
        .overlay.active { visibility: visible; opacity: 1; }
        #calendar { max-width: 100%; margin: 0 auto; }
        .fc-event { cursor: pointer; }
        .event-color-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .upcoming-event-item { padding: 12px; border-left: 3px solid; margin-bottom: 10px; background: #f8f9fa; border-radius: 0 8px 8px 0; transition: all 0.3s ease; display: block; text-decoration: none; color: inherit; }
        .upcoming-event-item:hover { background: #e9ecef; text-decoration: none; color: inherit; }
        .upcoming-event-title { font-weight: 600; margin-bottom: 4px; }
        .upcoming-event-date { font-size: 12px; color: #6c757d; }
        .days-badge { font-size: 11px; padding: 2px 8px; border-radius: 12px; background: #e9ecef; }
        .event-detail-item { margin-bottom: 16px; }
        .event-detail-label { font-size: 12px; color: #6c757d; margin-bottom: 4px; }
        .event-detail-value { font-size: 16px; font-weight: 500; color: #212529; }
    </style>
</head>
<body>
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer">
        <?php if (!empty($toastSuccess)): ?>
        <div class="toast success show" role="alert" data-autohide="true" data-delay="5000">
            <div class="toast-header">
                <i class="ri-checkbox-circle-line me-2"></i>
                <strong class="me-auto">Success</strong>
                <small>just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body"><?= htmlspecialchars($toastSuccess) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($toastError)): ?>
        <div class="toast error show" role="alert" data-autohide="true" data-delay="5000">
            <div class="toast-header">
                <i class="ri-error-warning-line me-2"></i>
                <strong class="me-auto">Error</strong>
                <small>just now</small>
                <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
            <div class="toast-body"><?= htmlspecialchars($toastError) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Theme Customization (unchanged) -->
    <div class="body-overlay"></div>
    <button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
        <i class="ri-settings-3-line animate-spin"></i>
    </button>
    <div class="theme-customization-sidebar w-100 bg-base h-100vh overflow-y-auto position-fixed end-0 top-0">
        <!-- ... theme content ... (keep as original) -->
    </div>

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar (include your actual sidebar file) -->
    <?php include_once('includes/sidebar.php'); ?>

    <main class="dashboard-main">
        
        <?php include_once('includes/header.php'); ?>
</div>
                <div class="col-auto">
                    <div class="d-flex flex-wrap align-items-center gap-3">
                        <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                        <div class="dropdown d-inline-block">
                            <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                                <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                                <?php if ($notificationCount > 0): ?>
                                <span class="w-8-px h-8-px bg-danger-600 position-absolute end-0 top-0 rounded-circle mt-2 me-2"></span>
                                <?php endif; ?>
                            </button>
                            <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                                <div class="m-16 py-12 px-16 radius-8 bg-primary-50 mb-16 d-flex align-items-center justify-content-between gap-2">
                                    <h6 class="text-lg text-primary-light fw-semibold mb-0">Notifications</h6>
                                    <span class="text-primary-600 fw-semibold text-lg w-40-px h-40-px rounded-circle bg-base d-flex justify-content-center align-items-center"><?= str_pad($notificationCount, 2, '0', STR_PAD_LEFT) ?></span>
                                </div>
                                <div class="max-h-400-px overflow-y-auto scroll-sm pe-4">
                                    <?php if (!empty($notifications)): ?>
                                        <?php foreach ($notifications as $notification): ?>
                                        <a href="#" class="px-24 py-12 d-flex align-items-start gap-3 mb-2 justify-content-between <?= !$notification['is_read'] ? 'bg-neutral-50' : '' ?>">
                                            <div class="text-black hover-bg-transparent hover-text-primary d-flex align-items-center gap-3">
                                                <span class="w-44-px h-44-px bg-<?= $notification['type'] == 'success' ? 'success' : ($notification['type'] == 'error' ? 'danger' : 'info') ?>-subtle text-<?= $notification['type'] == 'success' ? 'success' : ($notification['type'] == 'error' ? 'danger' : 'info') ?>-main rounded-circle d-flex justify-content-center align-items-center flex-shrink-0">
                                                    <i class="ri-<?= $notification['icon'] ?? 'notification-line' ?> text-xl"></i>
                                                </span>
                                                <div>
                                                    <h6 class="text-md fw-semibold mb-4"><?= htmlspecialchars($notification['title']) ?></h6>
                                                    <p class="mb-0 text-sm text-secondary-light text-w-200-px"><?= htmlspecialchars($notification['message']) ?></p>
                                                </div>
                                            </div>
                                            <span class="text-sm text-secondary-light flex-shrink-0"><?= date('d M', strtotime($notification['created_at'])) ?></span>
                                        </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="text-center py-20">
                                            <p class="text-secondary-light">No notifications</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-center py-12 px-16">
                                    <a href="notifications.php" class="text-primary-600 fw-semibold text-md hover-underline">See All Notifications</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div>
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Events Calendar</h1>
                    <div>
                        <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                        <?php if ($selectedEvent): ?>
                        <span class="text-secondary-light">/ Event Details</span>
                        <?php else: ?>
                        <span class="text-secondary-light">/ Events</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!$selectedEvent): ?>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                    <span class="d-flex text-md"><i class="ri-add-large-line"></i></span>
                    Add New Event
                </button>
                <?php else: ?>
                <a href="event.php" class="btn btn-outline-primary d-flex align-items-center gap-6">
                    <span class="d-flex text-md"><i class="ri-arrow-left-line"></i></span>
                    Back to Calendar
                </a>
                <?php endif; ?>
            </div>

            <?php if ($selectedEvent): ?>
            <!-- Single Event View -->
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="card">
                        <div class="card-body p-32">
                            <div class="d-flex justify-content-between align-items-start mb-24">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="event-color-dot" style="background-color: <?= $eventColors[$selectedEvent['type']] ?? '#6c757d' ?>;"></span>
                                        <span class="badge bg-<?= $selectedEvent['type'] == 'holiday' ? 'danger' : ($selectedEvent['type'] == 'exam' ? 'warning' : ($selectedEvent['type'] == 'celebration' ? 'success' : 'info')) ?>">
                                            <?= $eventTypes[$selectedEvent['type']] ?? ucfirst($selectedEvent['type']) ?>
                                        </span>
                                    </div>
                                    <h2 class="fw-semibold mb-2"><?= htmlspecialchars($selectedEvent['title']) ?></h2>
                                    <div class="d-flex flex-wrap gap-2 mb-16">
                                        <span class="badge bg-primary">Posted by: <?= htmlspecialchars($selectedEvent['created_by_name'] ?? 'System') ?></span>
                                        <span class="badge bg-info">Status: <?= ucfirst($selectedEvent['status'] ?? 'upcoming') ?></span>
                                    </div>
                                </div>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="ri-more-2-fill"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item edit-event-btn" data-event='<?= json_encode($selectedEvent) ?>'><i class="ri-edit-line"></i> Edit</button></li>
                                        <li><button type="button" class="dropdown-item text-danger" onclick="deleteEvent(<?= $selectedEvent['id'] ?>, '<?= addslashes($selectedEvent['title']) ?>')"><i class="ri-delete-bin-line"></i> Delete</button></li>
                                    </ul>
                                </div>
                            </div>
                            <div class="row g-4 mb-24">
                                <div class="col-md-6">
                                    <div class="event-detail-item">
                                        <div class="event-detail-label">Start Date</div>
                                        <div class="event-detail-value">
                                            <?= date('l, F j, Y', strtotime($selectedEvent['start_date'])) ?>
                                            <?php if (!empty($selectedEvent['start_time'])): ?><br><small class="text-muted"><?= date('g:i A', strtotime($selectedEvent['start_time'])) ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="event-detail-item">
                                        <div class="event-detail-label">End Date</div>
                                        <div class="event-detail-value">
                                            <?= date('l, F j, Y', strtotime($selectedEvent['end_date'])) ?>
                                            <?php if (!empty($selectedEvent['end_time'])): ?><br><small class="text-muted"><?= date('g:i A', strtotime($selectedEvent['end_time'])) ?></small><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($selectedEvent['venue'])): ?>
                                <div class="col-md-6">
                                    <div class="event-detail-item">
                                        <div class="event-detail-label">Venue</div>
                                        <div class="event-detail-value"><?= htmlspecialchars($selectedEvent['venue']) ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <div class="event-detail-item">
                                        <div class="event-detail-label">Duration</div>
                                        <div class="event-detail-value">
                                            <?php
                                            $start = new DateTime($selectedEvent['start_date']);
                                            $end   = new DateTime($selectedEvent['end_date']);
                                            $diff  = $start->diff($end);
                                            echo ($diff->days + 1) . ' day' . (($diff->days + 1) > 1 ? 's' : '');
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($selectedEvent['description'])): ?>
                            <div class="border-top pt-24 mt-24">
                                <h6 class="fw-semibold mb-12">Description</h6>
                                <div class="text-secondary-light"><?= nl2br(htmlspecialchars($selectedEvent['description'])) ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center border-top pt-24 mt-24">
                                <a href="event.php" class="btn btn-outline-primary"><i class="ri-arrow-left-line me-2"></i>Back to Calendar</a>
                                <span class="text-muted">Created: <?= date('d M Y, h:i A', strtotime($selectedEvent['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- Calendar and Events List -->
            <div class="row gy-4">
                <div class="col-xxl-3 col-lg-4">
                    <div class="card h-100 p-0">
                        <div class="card-header py-16 px-24 border-bottom">
                            <h6 class="fw-semibold mb-0">Upcoming Events</h6>
                        </div>
                        <div class="card-body p-24">
                            <div class="mt-8">
                                <?php if (empty($upcomingEvents)): ?>
                                <div class="text-center py-4">
                                    <i class="ri-calendar-line fs-1 text-secondary-light mb-3 d-block"></i>
                                    <p class="text-secondary-light">No upcoming events</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($upcomingEvents as $event):
                                        $daysUntil = $event['days_until'] ?? 0;
                                    ?>
                                    <a href="event.php?id=<?= $event['id'] ?>" class="text-decoration-none">
                                        <div class="upcoming-event-item" style="border-left-color: <?= $eventColors[$event['type']] ?? '#6c757d' ?>;">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="upcoming-event-title"><?= htmlspecialchars($event['title']) ?></div>
                                                <?php if ($daysUntil == 0): ?>
                                                <span class="days-badge bg-warning text-dark">Today</span>
                                                <?php elseif ($daysUntil == 1): ?>
                                                <span class="days-badge bg-info">Tomorrow</span>
                                                <?php elseif ($daysUntil <= 7): ?>
                                                <span class="days-badge">In <?= $daysUntil ?> days</span>
                                                <?php else: ?>
                                                <span class="days-badge"><?= $daysUntil ?> days</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="upcoming-event-date">
                                                <?= date('D, M j, Y', strtotime($event['start_date'])) ?>
                                                <?php if (!empty($event['start_time'])): ?> • <?= date('g:i A', strtotime($event['start_time'])) ?><?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?= $eventTypes[$event['type']] ?? ucfirst($event['type']) ?></small>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xxl-9 col-lg-8">
                    <div class="card h-100 p-0">
                        <div class="card-body p-24">
                            <div id='calendar'></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <footer class="d-footer">
            <p class="mb-0 text-center">&copy; <span class="current-year"></span> <?= htmlspecialchars($school['name']) ?> | Made With ❤️ by AcademixSuite.</p>
        </footer>
    </main>

    <!-- Add Event Sidebar - FIXED HTML -->
    <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Add New Event</h5>
            <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20">
            <input type="hidden" name="action" value="create_event">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label for="eventTitle" class="form-label fw-semibold text-primary-light text-sm mb-8">Event Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control radius-8" id="eventTitle" name="title" placeholder="Enter Event Title" required>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Event Type</label>
                    <div class="d-flex align-items-center flex-wrap gap-28">
                        <?php foreach ($eventTypes as $key => $value): ?>
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input" type="radio" name="type" value="<?= $key ?>" id="type_<?= $key ?>" <?= $key == 'other' ? 'checked' : '' ?>>
                            <label class="form-check-label line-height-1 fw-medium text-secondary-light text-sm d-flex align-items-center gap-1" for="type_<?= $key ?>">
                                <span class="w-8-px h-8-px rounded-circle" style="background-color: <?= $eventColors[$key] ?>;"></span>
                                <?= $value ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label for="startDate" class="form-label fw-semibold text-primary-light text-sm mb-8">Start Date <span class="text-danger">*</span></label>
                    <input class="form-control radius-8 bg-base" id="startDate" name="start_date" type="date" required>
                </div>
                <div class="col-md-6">
                    <label for="endDate" class="form-label fw-semibold text-primary-light text-sm mb-8">End Date</label>
                    <input class="form-control radius-8 bg-base" id="endDate" name="end_date" type="date">
                    <small class="text-muted">Leave blank for single day event</small>
                </div>

                <div class="col-md-6">
                    <label for="startTime" class="form-label fw-semibold text-primary-light text-sm mb-8">Start Time</label>
                    <input class="form-control radius-8" id="startTime" name="start_time" type="time">
                </div>
                <div class="col-md-6">
                    <label for="endTime" class="form-label fw-semibold text-primary-light text-sm mb-8">End Time</label>
                    <input class="form-control radius-8" id="endTime" name="end_time" type="time">
                </div>

                <div class="col-12">
                    <label for="venue" class="form-label fw-semibold text-primary-light text-sm mb-8">Venue</label>
                    <input type="text" class="form-control radius-8" id="venue" name="venue" placeholder="Enter venue/location">
                </div>

                <div class="col-12">
                    <label for="description" class="form-label fw-semibold text-primary-light text-sm mb-8">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4" placeholder="Write event description"></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="send_notification" id="sendNotification" value="1" checked>
                        <label class="form-check-label" for="sendNotification">Send email notifications to all users</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">Cancel</button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">Create Event</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Event Sidebar - FIXED HTML -->
    <div class="edit-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100">
        <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
            <h5 class="text-lg mb-0">Edit Event</h5>
            <button type="button" class="close-edit-sidebar text-danger-600 text-lg d-flex">
                <i class="ri-close-large-line"></i>
            </button>
        </div>
        <form method="POST" class="p-20" id="editEventForm">
            <input type="hidden" name="action" value="edit_event">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="event_id" id="edit_event_id">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Event Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control radius-8" name="title" id="edit_title" required>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Event Type</label>
                    <div class="d-flex align-items-center flex-wrap gap-28">
                        <?php foreach ($eventTypes as $key => $value): ?>
                        <div class="form-check d-flex align-items-center gap-2">
                            <input class="form-check-input" type="radio" name="type" value="<?= $key ?>" id="edit_type_<?= $key ?>">
                            <label class="form-check-label line-height-1 fw-medium text-secondary-light text-sm d-flex align-items-center gap-1" for="edit_type_<?= $key ?>">
                                <span class="w-8-px h-8-px rounded-circle" style="background-color: <?= $eventColors[$key] ?>;"></span>
                                <?= $value ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Start Date <span class="text-danger">*</span></label>
                    <input class="form-control radius-8" name="start_date" id="edit_start_date" type="date" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">End Date</label>
                    <input class="form-control radius-8" name="end_date" id="edit_end_date" type="date">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Start Time</label>
                    <input class="form-control radius-8" name="start_time" id="edit_start_time" type="time">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">End Time</label>
                    <input class="form-control radius-8" name="end_time" id="edit_end_time" type="time">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Venue</label>
                    <input type="text" class="form-control radius-8" name="venue" id="edit_venue" placeholder="Enter venue/location">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Description</label>
                    <textarea class="form-control" name="description" id="edit_description" rows="4"></textarea>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="send_notification" id="editSendNotification" value="1" checked>
                        <label class="form-check-label" for="editSendNotification">Send email notifications about this update</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">Cancel</button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">Update Event</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- View Event Modal -->
    <div class="modal fade" id="viewEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content radius-16 bg-base">
                <div class="modal-header py-16 px-24 border-bottom">
                    <h5 class="modal-title" id="viewEventModalLabel">Event Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-24" id="viewEventContent"><!-- Content loaded dynamically --></div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteEventModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content radius-16 bg-base">
                <div class="modal-body pt-32 px-36 pb-24 text-center">
                    <span class="mb-16 fs-11 line-height-1 text-danger">
                        <i class="ri-delete-bin-line" style="font-size: 48px;"></i>
                    </span>
                    <h6 class="text-lg fw-semibold text-primary-light mb-8">Delete Event</h6>
                    <p class="mb-24" id="deleteEventMessage">Are you sure you want to delete this event?</p>
                    <form method="POST" id="deleteEventForm">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="event_id" id="delete_event_id">
                        <div class="d-flex align-items-center justify-content-center gap-3">
                            <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger-600 border border-danger-600 text-md px-24 py-12 radius-8">Yes, Delete</button>
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
    <script src="https://academixsuite.com/tenant/assets/js/lib/full-calendar.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <script>
        $(document).ready(function() {
            // Toasts
            $('.toast').toast({ autohide: true, delay: 5000 }).toast('show');
            $('.current-year').text(new Date().getFullYear());

            // FullCalendar
            var calendarEl = document.getElementById('calendar');
            if (calendarEl) {
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
                    events: <?= json_encode($calendarEvents ?? []) ?>,
                    eventClick: function(info) { window.location.href = 'event.php?id=' + info.event.id; },
                    eventDidMount: function(info) {
                        $(info.el).tooltip({ title: info.event.title, placement: 'top', trigger: 'hover', container: 'body' });
                    },
                    height: 'auto',
                    firstDay: 1
                });
                calendar.render();
            }

            // Sidebar toggle (fixed)
            $('.my-sidebar-btn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });

            $('.close-my-sidebar, .overlay').on('click', function() {
                $('.my-sidebar').removeClass('active');
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            $('.edit-sidebar-btn, .edit-event-btn').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if ($(this).hasClass('edit-event-btn')) {
                    const eventData = $(this).data('event');
                    if (eventData) populateEditForm(eventData);
                }
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });

            $('.close-edit-sidebar').on('click', function() {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            function populateEditForm(event) {
                $('#edit_event_id').val(event.id);
                $('#edit_title').val(event.title);
                $('#edit_type_' + event.type).prop('checked', true);
                $('#edit_start_date').val(event.start_date);
                $('#edit_end_date').val(event.end_date || event.start_date);
                $('#edit_start_time').val(event.start_time || '');
                $('#edit_end_time').val(event.end_time || '');
                $('#edit_venue').val(event.venue || '');
                $('#edit_description').val(event.description || '');
            }

            // Date validation
            $('#endDate, #edit_end_date').on('change', function() {
                const startDate = $(this).closest('form').find('input[name="start_date"]').val();
                const endDate = $(this).val();
                if (endDate && startDate && endDate < startDate) {
                    alert('End date cannot be before start date');
                    $(this).val(startDate);
                }
            });

            $('#endTime, #edit_end_time').on('change', function() {
                const startTime = $(this).closest('form').find('input[name="start_time"]').val();
                const endTime = $(this).val();
                const startDate = $(this).closest('form').find('input[name="start_date"]').val();
                const endDate = $(this).closest('form').find('input[name="end_date"]').val();
                if (startTime && endTime && startDate === endDate && endTime <= startTime) {
                    alert('End time must be after start time');
                    $(this).val('');
                }
            });

            // Search
            $('.navbar-search input').on('keyup', function() {
                const term = $(this).val().toLowerCase();
                $('.upcoming-event-item').each(function() {
                    const title = $(this).find('.upcoming-event-title').text().toLowerCase();
                    const type = $(this).find('small').text().toLowerCase();
                    $(this).toggle(title.includes(term) || type.includes(term));
                });
            });

            // Flatpickr
            flatpickr("input[type=date]", { dateFormat: "Y-m-d" });
            flatpickr("input[type=time]", { enableTime: true, noCalendar: true, dateFormat: "H:i", time_24hr: true });
        });

        function deleteEvent(eventId, eventTitle) {
            $('#delete_event_id').val(eventId);
            $('#deleteEventMessage').text('Are you sure you want to delete "' + eventTitle + '"? This will notify all users via email.');
            $('#deleteEventModal').modal('show');
        }
    </script>
</body>
</html>