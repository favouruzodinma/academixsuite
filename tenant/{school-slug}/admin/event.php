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
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

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
$whatsappEventEnabled = false;

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

if ($schoolDb && class_exists('WhatsAppService')) {
    $whatsappEventEnabled = WhatsAppService::featureEnabled($schoolDb, (int)$school['id'], 'events', true);
}

// --- CSRF Token ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ============================================
// AJAX HANDLERS FOR FULLCALENDAR
// ============================================

// --- AJAX GET Handlers for FullCalendar ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['fc_ajax'])) {
    header('Content-Type: application/json');

    try {
        // Check if event manager is initialized
        if (!$eventManager) {
            throw new Exception("Event manager not initialized");
        }

        // Handle different AJAX actions
        switch ($_GET['fc_ajax']) {
            case 'get_events':
                // Get date range from FullCalendar
                $normalizeCalendarDate = static function ($value): ?string {
                    if ($value === null || $value === '') {
                        return null;
                    }
                    if (is_numeric($value)) {
                        $timestamp = (int)$value;
                        if ($timestamp > 2000000000) {
                            $timestamp = (int)floor($timestamp / 1000);
                        }
                        return date('Y-m-d', $timestamp);
                    }
                    $timestamp = strtotime((string)$value);
                    return $timestamp ? date('Y-m-d', $timestamp) : null;
                };

                $start = $normalizeCalendarDate($_GET['start'] ?? null);
                $end = $normalizeCalendarDate($_GET['end'] ?? null);

                error_log("FC AJAX: Fetching events from $start to $end");

                // Fetch events based on date range
                if ($start && $end) {
                    $events = $eventManager->getEventsByDateRange($start, $end);
                } else {
                    $events = $eventManager->getCalendarEvents();
                }

                // Format events for FullCalendar
                $formattedEvents = [];
                foreach ($events as $event) {
                    $eventStartDate = $event['start'] ?? $event['start_date'] ?? null;
                    $eventEndDate = $event['end'] ?? $event['end_date'] ?? $eventStartDate;
                    if (empty($eventStartDate)) {
                        continue;
                    }

                    // Determine if it's an all-day event
                    $allDay = empty($event['start_time']) && empty($event['end_time']);

                    // Format start and end dates with times
                    $startDate = $eventStartDate;
                    $endDate = $eventEndDate ?: $eventStartDate;

                    if (!$allDay) {
                        if (!empty($event['start_time'])) {
                            $startDate = $eventStartDate . 'T' . date('H:i:s', strtotime($event['start_time']));
                        }
                        if (!empty($event['end_time'])) {
                            $endDate = ($eventEndDate ?: $eventStartDate) . 'T' . date('H:i:s', strtotime($event['end_time']));
                        }
                    }

                    // Get color based on event type
                    $color = $event['color'] ?? null;
                    if (!$color && isset($event['type'])) {
                        $eventColors = [
                            'holiday' => '#dc3545',
                            'exam' => '#fd7e14',
                            'meeting' => '#0d6efd',
                            'celebration' => '#198754',
                            'sports' => '#6f42c1',
                            'other' => '#6c757d'
                        ];
                        $color = $eventColors[$event['type']] ?? $eventColors['other'];
                    }

                    $formattedEvents[] = [
                        'id' => $event['id'],
                        'title' => $event['title'],
                        'start' => $startDate,
                        'end' => $endDate,
                        'allDay' => $allDay,
                        'color' => $color,
                        'textColor' => '#ffffff',
                        'type' => $event['type'] ?? 'other',
                        'venue' => $event['venue'] ?? null,
                        'description' => $event['description'] ?? null,
                        'className' => 'fc-event-' . ($event['type'] ?? 'other')
                    ];
                }

                echo json_encode([
                    'success' => true,
                    'events' => $formattedEvents
                ]);
                break;

            case 'get_event':
                // Get single event by ID
                $eventId = $_GET['event_id'] ?? 0;
                if (!$eventId) {
                    throw new Exception("Event ID required");
                }

                $event = $eventManager->getEventById($eventId);
                if ($event) {
                    echo json_encode([
                        'success' => true,
                        'event' => $event
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Event not found'
                    ]);
                }
                break;

            case 'get_upcoming':
                // Get upcoming events
                $limit = $_GET['limit'] ?? 10;
                $events = $eventManager->getUpcomingEvents($limit);

                echo json_encode([
                    'success' => true,
                    'events' => $events
                ]);
                break;

            default:
                throw new Exception("Invalid AJAX action");
        }

    } catch (Exception $e) {
        error_log("FC AJAX Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// --- AJAX POST Handlers for creating/updating events ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fc_ajax'])) {
    header('Content-Type: application/json');

    try {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrfToken) {
            throw new Exception("Invalid security token");
        }

        if (!$eventManager) {
            throw new Exception("Event manager not initialized");
        }

        $action = $_POST['fc_ajax'];

        switch ($action) {
            case 'create_event':
                // Validate required fields
                if (empty($_POST['title']) || empty($_POST['start_date'])) {
                    throw new Exception("Title and start date are required");
                }

                $eventData = [
                    'title' => $_POST['title'],
                    'description' => $_POST['description'] ?? '',
                    'type' => $_POST['type'] ?? 'other',
                    'start_date' => $_POST['start_date'],
                    'end_date' => $_POST['end_date'] ?? $_POST['start_date'],
                    'start_time' => $_POST['start_time'] ?? null,
                    'end_time' => $_POST['end_time'] ?? null,
                    'venue' => $_POST['venue'] ?? null,
                    'is_public' => 1,
                    'send_whatsapp' => isset($_POST['send_whatsapp_present']) ? isset($_POST['send_whatsapp']) : $whatsappEventEnabled
                ];

                $sendNotification = isset($_POST['send_notification']);
                $result = $eventManager->createEvent($eventData, $sendNotification);

                echo json_encode($result);
                break;

            case 'update_event':
                if (empty($_POST['event_id'])) {
                    throw new Exception("Event ID required");
                }

                $eventData = [
                    'title' => $_POST['title'],
                    'description' => $_POST['description'] ?? '',
                    'type' => $_POST['type'] ?? 'other',
                    'start_date' => $_POST['start_date'],
                    'end_date' => $_POST['end_date'] ?? $_POST['start_date'],
                    'start_time' => $_POST['start_time'] ?? null,
                    'end_time' => $_POST['end_time'] ?? null,
                    'venue' => $_POST['venue'] ?? null,
                    'is_public' => 1,
                    'send_whatsapp' => isset($_POST['send_whatsapp_present']) ? isset($_POST['send_whatsapp']) : $whatsappEventEnabled
                ];

                $sendNotification = isset($_POST['send_notification']);
                $result = $eventManager->updateEvent($_POST['event_id'], $eventData, $sendNotification);

                echo json_encode($result);
                break;

            case 'delete_event':
                if (empty($_POST['event_id'])) {
                    throw new Exception("Event ID required");
                }

                $result = $eventManager->deleteEvent($_POST['event_id'], true);
                echo json_encode($result);
                break;

            default:
                throw new Exception("Invalid action");
        }

    } catch (Exception $e) {
        error_log("FC AJAX POST Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// ============================================
// REGULAR PAGE HANDLING (Non-AJAX)
// ============================================

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

if ($eventManager) {
    try {
        if ($eventId > 0) {
            $selectedEvent = $eventManager->getEventById($eventId);
            if (!$selectedEvent) {
                $_SESSION['toast_error'] = "Event not found.";
                header("Location: event.php?school_slug=" . urlencode($schoolSlug));
                exit;
            }
        }

        $upcomingEvents = $eventManager->getUpcomingEvents(10);
        error_log("Fetched " . count($upcomingEvents) . " upcoming events");
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

// --- Handle Regular POST (non-AJAX form submissions) ---
$message = '';
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['fc_ajax'])) {
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
                        'is_public'   => 1,
                        'send_whatsapp' => isset($_POST['send_whatsapp_present']) ? isset($_POST['send_whatsapp']) : $whatsappEventEnabled
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
                        'is_public'   => 1,
                        'send_whatsapp' => isset($_POST['send_whatsapp_present']) ? isset($_POST['send_whatsapp']) : $whatsappEventEnabled
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

                // Redirect to refresh the page after successful operation
                $redirectUrl = "event.php?school_slug=" . urlencode($schoolSlug);
                if ($action === 'edit_event' || $action === 'create_event') {
                    // Stay on same page
                } else if ($action === 'delete_event') {
                    // Redirect to main calendar
                    header("Location: " . $redirectUrl);
                    exit;
                }
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

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png" sizes="16x16">

    <!-- Core Styles -->
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">

    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">

    <!-- Main Style -->
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

        #calendar {
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border-radius: 18px;
            overflow: hidden;
        }
        .fc-event {
            cursor: pointer;
            border: 0 !important;
            border-radius: 9px !important;
            padding: 4px 7px !important;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.10);
        }
        .fc-state-highlight {
            background: #eefbf8 !important;
        }
        .fc-header-title h2 {
            font-size: 26px;
            font-weight: 800;
            color: #1f2937;
        }
        .fc-button {
            border-radius: 10px !important;
            border-color: #d7e6e3 !important;
            background: #fff !important;
            color: #27776f !important;
            text-transform: capitalize !important;
        }
        .fc-button.fc-state-active,
        .fc-button:hover {
            background: #4aa398 !important;
            color: #fff !important;
        }
        .event-color-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin-right: 8px; }
        .upcoming-event-item { padding: 12px; border-left: 3px solid; margin-bottom: 10px; background: #f8f9fa; border-radius: 0 8px 8px 0; transition: all 0.3s ease; display: block; text-decoration: none; color: inherit; }
        .upcoming-event-item:hover { background: #e9ecef; text-decoration: none; color: inherit; }
        .upcoming-event-title { font-weight: 600; margin-bottom: 4px; }
        .upcoming-event-date { font-size: 12px; color: #6c757d; }
        .days-badge { font-size: 11px; padding: 2px 8px; border-radius: 12px; background: #e9ecef; }
        .event-detail-item { margin-bottom: 16px; }
        .event-detail-label { font-size: 12px; color: #6c757d; margin-bottom: 4px; }
        .event-detail-value { font-size: 16px; font-weight: 500; color: #212529; }
        .event-detail-shell { max-width: 1120px; margin: 0 auto; }
        .event-detail-card { border: 0; border-radius: 28px; overflow: hidden; box-shadow: 0 24px 70px rgba(15, 23, 42, .12); background: #fff; }
        .event-detail-hero { position: relative; padding: 34px; color: #fff; isolation: isolate; overflow: hidden; }
        .event-detail-hero::after { content: ""; position: absolute; inset: auto -80px -130px auto; width: 340px; height: 340px; border-radius: 50%; background: rgba(255,255,255,.16); z-index: -1; }
        .event-detail-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 28px; }
        .event-detail-pill-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
        .event-detail-pill { display: inline-flex; align-items: center; gap: 8px; min-height: 30px; padding: 0 12px; border-radius: 999px; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.28); color: #fff; font-size: 12px; font-weight: 700; }
        .event-detail-title { max-width: 820px; margin: 0; font-size: clamp(34px, 4.8vw, 64px); line-height: 1; font-weight: 800; letter-spacing: 0; }
        .event-detail-subtitle { margin: 14px 0 0; max-width: 760px; color: rgba(255,255,255,.86); font-size: 15px; }
        .event-detail-menu { width: 42px; height: 42px; border-radius: 14px; border: 1px solid rgba(255,255,255,.24); background: rgba(255,255,255,.14); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
        .event-detail-body { padding: 30px 34px 34px; }
        .event-detail-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
        .event-detail-info { min-height: 126px; padding: 18px; border: 1px solid #e5eef1; border-radius: 18px; background: #f8fafc; }
        .event-detail-info .icon { width: 42px; height: 42px; border-radius: 14px; display: grid; place-items: center; background: #e6f7f5; color: #27776f; font-size: 20px; margin-bottom: 14px; }
        .event-detail-info span { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; }
        .event-detail-info strong { display: block; color: #111827; font-size: 16px; line-height: 1.35; margin-top: 5px; }
        .event-detail-info small { display: block; color: #64748b; margin-top: 3px; }
        .event-detail-description { margin-top: 22px; padding: 24px; border: 1px solid #e5eef1; border-radius: 20px; background: #fff; }
        .event-detail-description h3 { margin: 0 0 10px; font-size: 20px; font-weight: 800; color: #111827; }
        .event-detail-description p { margin: 0; color: #475569; line-height: 1.7; }
        .event-detail-footer { margin-top: 24px; padding-top: 20px; border-top: 1px solid #edf2f7; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        @media (max-width: 991px) { .event-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575px) {
            .event-detail-hero, .event-detail-body { padding: 24px; }
            .event-detail-grid { grid-template-columns: 1fr; }
            .event-detail-title { font-size: 36px; }
        }

        /* Calendar loading state */
        #calendar.fc-loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        #calendar.fc-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 40px;
            height: 40px;
            margin: -20px 0 0 -20px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 1000;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .fc-event-venue {
            font-size: 0.85em;
            opacity: 0.9;
            margin-top: 2px;
            display: block;
        }
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

    <!-- Theme Customization -->
    
    

    <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

    <!-- Sidebar -->
    <?php include_once('includes/sidebar.php'); ?>

    <main class="dashboard-main">
        <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

        <div class="dashboard-main-body">
            <!-- Breadcrumb -->
            <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
                <div>
                    <h1 class="fw-semibold mb-4 h6 text-primary-light">Events Calendar</h1>
                    <div>
                        <a href="index.php?school_slug=<?= urlencode($schoolSlug) ?>" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
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
                <a href="event.php?school_slug=<?= urlencode($schoolSlug) ?>" class="btn btn-outline-primary d-flex align-items-center gap-6">
                    <span class="d-flex text-md"><i class="ri-arrow-left-line"></i></span>
                    Back to Calendar
                </a>
                <?php endif; ?>
            </div>

            <?php if ($selectedEvent): ?>
            <!-- Single Event View -->
            <?php
                $selectedType = (string) ($selectedEvent['type'] ?? 'other');
                $eventColor = $eventColors[$selectedType] ?? '#4aa398';
                $eventTypeLabel = $eventTypes[$selectedType] ?? ucwords(str_replace('_', ' ', $selectedType));
                $eventStatus = ucwords(str_replace('_', ' ', (string) ($selectedEvent['status'] ?? 'upcoming')));
                $startDateTs = strtotime((string) ($selectedEvent['start_date'] ?? ''));
                $endDateTs = strtotime((string) ($selectedEvent['end_date'] ?? ($selectedEvent['start_date'] ?? '')));
                $createdTs = strtotime((string) ($selectedEvent['created_at'] ?? ''));
                $startDateText = $startDateTs ? date('l, F j, Y', $startDateTs) : 'Not scheduled';
                $endDateText = $endDateTs ? date('l, F j, Y', $endDateTs) : $startDateText;
                $startTimeText = !empty($selectedEvent['start_time']) ? date('g:i A', strtotime($selectedEvent['start_time'])) : '';
                $endTimeText = !empty($selectedEvent['end_time']) ? date('g:i A', strtotime($selectedEvent['end_time'])) : '';
                try {
                    $start = new DateTime((string) ($selectedEvent['start_date'] ?? 'now'));
                    $end = new DateTime((string) ($selectedEvent['end_date'] ?? ($selectedEvent['start_date'] ?? 'now')));
                    $durationDays = $start->diff($end)->days + 1;
                } catch (Throwable $e) {
                    $durationDays = 1;
                }
                $audience = trim((string) ($selectedEvent['target_audience'] ?? $selectedEvent['audience'] ?? 'School community'));
                $createdText = $createdTs ? date('d M Y, h:i A', $createdTs) : 'Recently';
            ?>
            <div class="event-detail-shell">
                <article class="event-detail-card">
                    <div class="event-detail-hero" style="background: linear-gradient(135deg, <?= htmlspecialchars($eventColor) ?>, #1f2937);">
                        <div class="event-detail-hero-top">
                            <div class="event-detail-pill-row">
                                <span class="event-detail-pill"><span class="event-color-dot" style="background:#fff;"></span><?= htmlspecialchars($eventTypeLabel) ?></span>
                                <span class="event-detail-pill"><i class="ri-time-line"></i><?= htmlspecialchars($eventStatus) ?></span>
                                <span class="event-detail-pill"><i class="ri-user-smile-line"></i>Posted by <?= htmlspecialchars($selectedEvent['created_by_name'] ?? 'System') ?></span>
                            </div>
                            <div class="dropdown">
                                <button type="button" class="event-detail-menu" data-bs-toggle="dropdown" aria-label="Event actions">
                                    <i class="ri-more-2-fill"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><button type="button" class="dropdown-item edit-event-btn" data-event='<?= htmlspecialchars(json_encode($selectedEvent), ENT_QUOTES, 'UTF-8') ?>'><i class="ri-edit-line"></i> Edit event</button></li>
                                    <li><button type="button" class="dropdown-item text-danger" onclick="deleteEvent(<?= (int) $selectedEvent['id'] ?>, '<?= addslashes((string) $selectedEvent['title']) ?>')"><i class="ri-delete-bin-line"></i> Delete event</button></li>
                                </ul>
                            </div>
                        </div>
                        <h2 class="event-detail-title"><?= htmlspecialchars($selectedEvent['title']) ?></h2>
                        <p class="event-detail-subtitle">
                            <?= htmlspecialchars($startDateText) ?><?php if ($startTimeText): ?> at <?= htmlspecialchars($startTimeText) ?><?php endif; ?>
                            <?php if (!empty($selectedEvent['venue'])): ?> &middot; <?= htmlspecialchars($selectedEvent['venue']) ?><?php endif; ?>
                        </p>
                    </div>

                    <div class="event-detail-body">
                        <div class="event-detail-grid">
                            <div class="event-detail-info">
                                <div class="icon"><i class="ri-calendar-event-line"></i></div>
                                <span>Start date</span>
                                <strong><?= htmlspecialchars($startDateText) ?></strong>
                                <?php if ($startTimeText): ?><small><?= htmlspecialchars($startTimeText) ?></small><?php endif; ?>
                            </div>
                            <div class="event-detail-info">
                                <div class="icon"><i class="ri-calendar-check-line"></i></div>
                                <span>End date</span>
                                <strong><?= htmlspecialchars($endDateText) ?></strong>
                                <?php if ($endTimeText): ?><small><?= htmlspecialchars($endTimeText) ?></small><?php endif; ?>
                            </div>
                            <div class="event-detail-info">
                                <div class="icon"><i class="ri-hourglass-line"></i></div>
                                <span>Duration</span>
                                <strong><?= (int) $durationDays ?> day<?= $durationDays > 1 ? 's' : '' ?></strong>
                                <small><?= htmlspecialchars($eventStatus) ?></small>
                            </div>
                            <div class="event-detail-info">
                                <div class="icon"><i class="ri-group-line"></i></div>
                                <span>Audience</span>
                                <strong><?= htmlspecialchars($audience !== '' ? $audience : 'School community') ?></strong>
                                <?php if (!empty($selectedEvent['venue'])): ?><small><?= htmlspecialchars($selectedEvent['venue']) ?></small><?php endif; ?>
                            </div>
                        </div>

                        <section class="event-detail-description">
                            <h3>Description</h3>
                            <p><?= !empty($selectedEvent['description']) ? nl2br(htmlspecialchars($selectedEvent['description'])) : 'No description has been added for this event yet.' ?></p>
                        </section>

                        <div class="event-detail-footer">
                            <a href="event.php?school_slug=<?= urlencode($schoolSlug) ?>" class="btn btn-outline-primary"><i class="ri-arrow-left-line me-2"></i>Back to Calendar</a>
                            <span class="text-muted">Created: <?= htmlspecialchars($createdText) ?></span>
                        </div>
                    </div>
                </article>
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
                                    <a href="event.php?id=<?= $event['id'] ?>&school_slug=<?= urlencode($schoolSlug) ?>" class="text-decoration-none">
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

        <?php require_once __DIR__ . '/includes/footer.php'; ?>
    </main>

    <!-- Add Event Sidebar -->
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
            <input type="hidden" name="school_slug" value="<?= htmlspecialchars($schoolSlug) ?>">

            <div class="row g-3">
                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Event Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" class="form-control" placeholder="Enter event title" required>
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Event Type</label>
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
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Start Date <span class="text-danger-600">*</span>
                        </label>
                        <input type="date" name="start_date" class="form-control flatpickr" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" class="form-control flatpickr">
                        <small class="text-muted">Leave blank for single day event</small>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Time</label>
                        <input type="time" name="start_time" class="form-control flatpickr-time">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Time</label>
                        <input type="time" name="end_time" class="form-control flatpickr-time">
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Venue</label>
                        <input type="text" name="venue" class="form-control" placeholder="Enter venue/location">
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" class="form-control" rows="4" placeholder="Enter event description..."></textarea>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="send_notification" class="form-check-input" id="send_notification" value="1" checked>
                        <label class="form-check-label" for="send_notification">Send email notifications to all users</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="send_whatsapp" class="form-check-input" id="send_whatsapp_event" value="1" <?php echo $whatsappEventEnabled ? 'checked' : ''; ?>>
                        <input type="hidden" name="send_whatsapp_present" value="1">
                        <label class="form-check-label" for="send_whatsapp_event">Send WhatsApp notifications to parents and teachers</label>
                        <small class="d-block text-secondary-light">Controlled globally from General Settings &gt; WhatsApp.</small>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Create Event
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Edit Event Sidebar -->
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
            <input type="hidden" name="school_slug" value="<?= htmlspecialchars($schoolSlug) ?>">

            <div class="row g-3">
                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Event Title <span class="text-danger-600">*</span>
                        </label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Event Type</label>
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
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">
                            Start Date <span class="text-danger-600">*</span>
                        </label>
                        <input type="date" name="start_date" id="edit_start_date" class="form-control flatpickr" required>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Date</label>
                        <input type="date" name="end_date" id="edit_end_date" class="form-control flatpickr">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Start Time</label>
                        <input type="time" name="start_time" id="edit_start_time" class="form-control flatpickr-time">
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">End Time</label>
                        <input type="time" name="end_time" id="edit_end_time" class="form-control flatpickr-time">
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Venue</label>
                        <input type="text" name="venue" id="edit_venue" class="form-control" placeholder="Enter venue/location">
                    </div>
                </div>

                <div class="col-12">
                    <div class="">
                        <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                        <textarea name="description" id="edit_description" class="form-control" rows="4"></textarea>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="send_notification" class="form-check-input" id="edit_send_notification" value="1" checked>
                        <label class="form-check-label" for="edit_send_notification">Send email notifications about this update</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="send_whatsapp" class="form-check-input" id="edit_send_whatsapp_event" value="1" <?php echo $whatsappEventEnabled ? 'checked' : ''; ?>>
                        <input type="hidden" name="send_whatsapp_present" value="1">
                        <label class="form-check-label" for="edit_send_whatsapp_event">Send WhatsApp notifications about this update</label>
                        <small class="d-block text-secondary-light">Parents and teachers with valid phone numbers will be notified.</small>
                    </div>
                </div>

                <div class="col-12">
                    <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                        <button type="button" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-edit-sidebar">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                            Update Event
                        </button>
                    </div>
                </div>
            </div>
        </form>
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
    <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

    <!-- Your custom calendar script -->
    <script src="https://academixsuite.com/tenant/assets/js/full-calendar.js"></script>
    <script src="https://academixsuite.com/tenant/assets/js/flatpickr.min.js"></script>

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

            // Initialize flatpickr for date and time inputs
            if (typeof flatpickr !== 'undefined') {
                flatpickr(".flatpickr", {
                    dateFormat: "Y-m-d"
                });

                flatpickr(".flatpickr-time", {
                    enableTime: true,
                    noCalendar: true,
                    dateFormat: "H:i",
                    time_24hr: true
                });
            }

            // --- Sidebar Toggle ---
            $('.my-sidebar-btn').on('click', function () {
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });

            $('.close-my-sidebar, .overlay').on('click', function () {
                $('.my-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Edit sidebar
            $('.edit-event-btn').on('click', function () {
                const eventData = $(this).data('event');
                if (eventData) {
                    $('#edit_event_id').val(eventData.id);
                    $('#edit_title').val(eventData.title);
                    $('#edit_type_' + eventData.type).prop('checked', true);
                    $('#edit_start_date').val(eventData.start_date);
                    $('#edit_end_date').val(eventData.end_date || eventData.start_date);
                    $('#edit_start_time').val(eventData.start_time || '');
                    $('#edit_end_time').val(eventData.end_time || '');
                    $('#edit_venue').val(eventData.venue || '');
                    $('#edit_description').val(eventData.description || '');
                }
                $('.edit-sidebar').addClass('active');
                $('.overlay').addClass('active');
            });

            $('.close-edit-sidebar, .overlay').on('click', function () {
                $('.edit-sidebar').removeClass('active');
                $('.overlay').removeClass('active');
            });

            // Date validation
            $('#end_date, #edit_end_date').on('change', function() {
                const startDate = $(this).closest('form').find('input[name="start_date"]').val();
                const endDate = $(this).val();
                if (endDate && startDate && endDate < startDate) {
                    alert('End date cannot be before start date');
                    $(this).val(startDate);
                }
            });

            // Search functionality
            $('.navbar-search input').on('keyup', function() {
                const term = $(this).val().toLowerCase();
                $('.upcoming-event-item').each(function() {
                    const title = $(this).find('.upcoming-event-title').text().toLowerCase();
                    const type = $(this).find('small').text().toLowerCase();
                    $(this).toggle(title.includes(term) || type.includes(term));
                });
            });

            initializeSchoolCalendar();
        });

        function calendarDateParam(value) {
            if (!value) return '';
            if (typeof value.format === 'function') {
                return value.format('YYYY-MM-DD');
            }
            const date = value instanceof Date ? value : new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

        function initializeSchoolCalendar() {
            const $calendar = $('#calendar');
            if (!$calendar.length || typeof $.fn.fullCalendar !== 'function') {
                return;
            }

            try {
                $calendar.fullCalendar('destroy');
            } catch (error) {
                // Calendar may not have been initialized by the bundled script yet.
            }

            $calendar.fullCalendar({
                header: {
                    left: 'title',
                    center: 'agendaDay,agendaWeek,month',
                    right: 'prev,next today'
                },
                defaultView: 'month',
                firstDay: 1,
                editable: false,
                selectable: false,
                eventLimit: true,
                events: function(start, end, callback) {
                    $.ajax({
                        url: 'event.php',
                        dataType: 'json',
                        data: {
                            school_slug: <?php echo json_encode($schoolSlug); ?>,
                            fc_ajax: 'get_events',
                            start: calendarDateParam(start),
                            end: calendarDateParam(end)
                        },
                        success: function(response) {
                            callback(response && response.success ? response.events : []);
                        },
                        error: function() {
                            callback([]);
                        }
                    });
                },
                eventRender: function(event, element) {
                    const meta = [
                        event.venue ? 'Venue: ' + event.venue : '',
                        event.description ? event.description : ''
                    ].filter(Boolean).join('\n');
                    if (meta) {
                        element.attr('title', meta);
                    }
                    element.addClass('school-calendar-event');
                },
                eventClick: function(event) {
                    if (event && event.id) {
                        window.location.href = 'event.php?id=' + encodeURIComponent(event.id) + '&school_slug=' + encodeURIComponent(<?php echo json_encode($schoolSlug); ?>);
                    }
                }
            });
        }

        // Delete event function
        function deleteEvent(eventId, eventTitle) {
            $('#delete_event_id').val(eventId);
            $('#deleteEventMessage').text('Are you sure you want to delete "' + eventTitle + '"? This will notify all users via email.');
            $('#deleteEventModal').modal('show');
        }
    </script>
</body>
</html>
