<?php
/**
 * Timetable List Page
 * Displays all timetable entries with filtering options
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_timetable_list.log');

error_log("=== TIMETABLE LIST PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
error_log("Script: " . __FILE__);

// Define constants
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

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

// Get school slug from GLOBALS
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
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

// Check authentication
$isAuthenticated = false;
if (isset($_SESSION['school_auth']) && is_array($_SESSION['school_auth'])) {
    if (($_SESSION['school_auth']['school_slug'] ?? '') === $schoolSlug) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    error_log("User not authenticated");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

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
    
    // Include TimetableManager
    $timetableManagerPath = __DIR__ . '/../../../includes/TimetableManager.php';
    if (!file_exists($timetableManagerPath)) {
        throw new Exception("TimetableManager file not found");
    }
    require_once $timetableManagerPath;
    
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed.");
}

// Connect to school database
$schoolDb = null;
$timetableManager = null;
try {
    if (!empty($school['database_name'])) {
        $schoolDb = Database::getSchoolConnection($school['database_name']);
        error_log("School database connection successful");
        
        // Initialize TimetableManager
        $timetableManager = new TimetableManager($schoolDb, $school['id'], $userId, $userType, $school);
        error_log("TimetableManager initialized successfully");
    }
} catch (Exception $e) {
    error_log("ERROR connecting to school database: " . $e->getMessage());
    $schoolDb = null;
}

// Initialize variables
$settings = [];
$classes = [];
$academicYears = [];
$academicTerms = [];
$toastSuccess = $_SESSION['toast_success'] ?? '';
$toastError = $_SESSION['toast_error'] ?? '';

// Clear session toasts
unset($_SESSION['toast_success'], $_SESSION['toast_error']);

// Get filter parameters
$filters = [
    'class_id' => isset($_GET['class_id']) ? (int)$_GET['class_id'] : null,
    'teacher_id' => isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : null,
    'day' => isset($_GET['day']) ? $_GET['day'] : null,
    'academic_year_id' => isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : null,
    'academic_term_id' => isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : null,
];

// Handle delete action
if (isset($_GET['delete']) && $timetableManager) {
    $timetableId = (int)$_GET['delete'];
    $result = $timetableManager->deleteTimetable($timetableId);
    
    if ($result[0]) {
        $_SESSION['toast_success'] = $result[1];
    } else {
        $_SESSION['toast_error'] = $result[1];
    }
    
    // Remove delete parameter from URL
    unset($_GET['delete']);
    $queryString = http_build_query($_GET);
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?') . ($queryString ? '?' . $queryString : ''));
    exit;
}

// Fetch data from database
if ($schoolDb) {
    try {
        // Get school settings
        $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
        if ($settingsStmt) {
            $settingsStmt->execute([$school['id']]);
            while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key']] = $row['value'];
            }
        }

        // Get academic years
        $yearStmt = $schoolDb->prepare("
            SELECT * FROM academic_years 
            WHERE school_id = ?
            ORDER BY is_default DESC, start_date DESC
        ");
        if ($yearStmt) {
            $yearStmt->execute([$school['id']]);
            $academicYears = $yearStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get academic terms
        $termStmt = $schoolDb->prepare("
            SELECT at.*, ay.name as academic_year_name
            FROM academic_terms at
            JOIN academic_years ay ON at.academic_year_id = ay.id
            WHERE at.school_id = ?
            ORDER BY ay.start_date DESC, at.start_date
        ");
        if ($termStmt) {
            $termStmt->execute([$school['id']]);
            $academicTerms = $termStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Get classes
        $classStmt = $schoolDb->prepare("
            SELECT c.*, ay.name as academic_year_name 
            FROM classes c
            LEFT JOIN academic_years ay ON c.academic_year_id = ay.id
            WHERE c.school_id = ? AND c.is_active = 1
            ORDER BY c.name
        ");
        if ($classStmt) {
            $classStmt->execute([$school['id']]);
            $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $toastError = "Error loading data. Please refresh.";
    }
}

// Get timetables
$timetables = [];
if ($timetableManager) {
    $timetables = $timetableManager->getTimetables($filters);
}

// Helper functions
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) return null;
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatTime')) {
    function formatTime($time) {
        return date('h:i A', strtotime($time));
    }
}

if (!function_exists('getDayColor')) {
    function getDayColor($day) {
        $colors = [
            'monday' => 'primary',
            'tuesday' => 'success',
            'wednesday' => 'info',
            'thursday' => 'warning',
            'friday' => 'danger',
            'saturday' => 'secondary'
        ];
        return $colors[$day] ?? 'primary';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Timetable List - School Management System">
    <meta name="keywords" content="Timetable List, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Timetable List - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .badge-break {
            background-color: #6c757d;
            color: white;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .table-timetable th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .day-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .day-primary { background: #e3f2fd; color: #1976d2; }
        .day-success { background: #e8f5e9; color: #388e3c; }
        .day-info { background: #e1f5fe; color: #0288d1; }
        .day-warning { background: #fff3e0; color: #f57c00; }
        .day-danger { background: #ffebee; color: #d32f2f; }
        .day-secondary { background: #f5f5f5; color: #616161; }
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

<!-- Theme Customization Structure -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Timetable List</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light">/ Timetable List</span>
                </div>
            </div>
            <a href="add-timetable.php" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                <i class="ri-add-line me-2"></i>Add New Timetable
            </a>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h6 class="fw-semibold mb-16">Filter Timetables</h6>
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="academic_year_id" class="form-label">Academic Year</label>
                    <select class="form-select" id="academic_year_id" name="academic_year_id">
                        <option value="">All Years</option>
                        <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php echo ($filters['academic_year_id'] == $year['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="academic_term_id" class="form-label">Academic Term</label>
                    <select class="form-select" id="academic_term_id" name="academic_term_id">
                        <option value="">All Terms</option>
                        <?php foreach ($academicTerms as $term): ?>
                        <option value="<?php echo $term['id']; ?>" <?php echo ($filters['academic_term_id'] == $term['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($term['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="class_id" class="form-label">Class</label>
                    <select class="form-select" id="class_id" name="class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo ($filters['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="day" class="form-label">Day</label>
                    <select class="form-select" id="day" name="day">
                        <option value="">All Days</option>
                        <option value="monday" <?php echo ($filters['day'] == 'monday') ? 'selected' : ''; ?>>Monday</option>
                        <option value="tuesday" <?php echo ($filters['day'] == 'tuesday') ? 'selected' : ''; ?>>Tuesday</option>
                        <option value="wednesday" <?php echo ($filters['day'] == 'wednesday') ? 'selected' : ''; ?>>Wednesday</option>
                        <option value="thursday" <?php echo ($filters['day'] == 'thursday') ? 'selected' : ''; ?>>Thursday</option>
                        <option value="friday" <?php echo ($filters['day'] == 'friday') ? 'selected' : ''; ?>>Friday</option>
                        <option value="saturday" <?php echo ($filters['day'] == 'saturday') ? 'selected' : ''; ?>>Saturday</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary-600 w-100">
                        <i class="ri-filter-3-line me-2"></i>Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-timetable" id="timetableTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Class/Section</th>
                                <th>Day</th>
                                <th>Period</th>
                                <th>Time</th>
                                <th>Subject</th>
                                <th>Teacher</th>
                                <th>Room</th>
                                <th>Term/Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($timetables)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5">
                                    <i class="ri-calendar-todo-line fs-1 text-secondary-light"></i>
                                    <p class="mt-3 text-secondary-light">No timetable entries found</p>
                                    <a href="add-timetable.php" class="btn btn-primary-600 mt-3">Add First Entry</a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($timetables as $index => $entry): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($entry['class_name'] ?? 'N/A'); ?></div>
                                        <?php if (!empty($entry['section_name'])): ?>
                                        <small class="text-secondary-light">Section: <?php echo htmlspecialchars($entry['section_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="day-badge day-<?php echo getDayColor($entry['day']); ?>">
                                            <?php echo ucfirst($entry['day']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary-600">Period <?php echo $entry['period_number']; ?></span>
                                    </td>
                                    <td>
                                        <?php echo formatTime($entry['start_time']); ?> - <?php echo formatTime($entry['end_time']); ?>
                                    </td>
                                    <td>
                                        <?php if ($entry['is_break']): ?>
                                            <span class="badge badge-break">Break</span>
                                        <?php else: ?>
                                            <div class="fw-semibold"><?php echo htmlspecialchars($entry['subject_name'] ?? 'N/A'); ?></div>
                                            <?php if (!empty($entry['subject_code'])): ?>
                                            <small class="text-secondary-light">(<?php echo htmlspecialchars($entry['subject_code']); ?>)</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$entry['is_break']): ?>
                                            <?php echo htmlspecialchars($entry['teacher_name'] ?? 'N/A'); ?>
                                        <?php else: ?>
                                            <span class="text-secondary-light">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['room_number'])): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($entry['room_number']); ?></span>
                                        <?php else: ?>
                                            <span class="text-secondary-light">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($entry['academic_term_name'] ?? 'N/A'); ?></small><br>
                                        <small class="text-secondary-light"><?php echo htmlspecialchars($entry['academic_year_name'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <a href="edit-timetable.php?id=<?php echo $entry['id']; ?>" 
                                               class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="ri-edit-line"></i>
                                            </a>
                                            <a href="view-timetable.php?class_id=<?php echo $entry['class_id']; ?>&day=<?php echo $entry['day']; ?>" 
                                               class="btn btn-sm btn-outline-info" title="View">
                                                <i class="ri-eye-line"></i>
                                            </a>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-danger" 
                                                    title="Delete"
                                                    onclick="confirmDelete(<?php echo $entry['id']; ?>)">
                                                <i class="ri-delete-bin-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this timetable entry? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
            </div>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');

    // Initialize DataTable
    $('#timetableTable').DataTable({
        pageLength: 25,
        ordering: true,
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

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});

// Delete confirmation
function confirmDelete(id) {
    $('#confirmDeleteBtn').attr('href', '?delete=' + id);
    $('#deleteModal').modal('show');
}
</script>

</body>
</html>