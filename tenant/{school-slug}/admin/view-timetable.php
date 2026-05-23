<?php
/**
 * View Timetable Page
 * Displays timetable in a grid format for a specific class/day
 * 
 * @package AcademixSuite
 * @version 2.0
 */

// Enable error reporting
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/school_view_timetable.log');

error_log("=== VIEW TIMETABLE PAGE START ===");
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

// Get parameters
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;
$academicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$academicTermId = isset($_GET['academic_term_id']) ? (int)$_GET['academic_term_id'] : 0;

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
$selectedClass = null;
$selectedSection = null;
$selectedYear = null;
$selectedTerm = null;
$timetableGrid = ['grid' => [], 'periods' => [], 'days' => []];
$toastError = '';

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

        // If no academic year selected, get default
        if (!$academicYearId) {
            $defaultYearStmt = $schoolDb->prepare("
                SELECT id FROM academic_years 
                WHERE school_id = ? AND is_default = 1 
                LIMIT 1
            ");
            $defaultYearStmt->execute([$school['id']]);
            $defaultYear = $defaultYearStmt->fetch(PDO::FETCH_ASSOC);
            if ($defaultYear) {
                $academicYearId = $defaultYear['id'];
            }
        }

        // If no academic term selected, get default
        if (!$academicTermId && $academicYearId) {
            $defaultTermStmt = $schoolDb->prepare("
                SELECT id FROM academic_terms 
                WHERE school_id = ? AND academic_year_id = ? 
                ORDER BY is_default DESC, start_date 
                LIMIT 1
            ");
            $defaultTermStmt->execute([$school['id'], $academicYearId]);
            $defaultTerm = $defaultTermStmt->fetch(PDO::FETCH_ASSOC);
            if ($defaultTerm) {
                $academicTermId = $defaultTerm['id'];
            }
        }

        // Get selected class details
        if ($classId) {
            $classDetailStmt = $schoolDb->prepare("
                SELECT * FROM classes WHERE id = ? AND school_id = ?
            ");
            $classDetailStmt->execute([$classId, $school['id']]);
            $selectedClass = $classDetailStmt->fetch(PDO::FETCH_ASSOC);

            // Get sections for this class
            if ($sectionId) {
                $sectionStmt = $schoolDb->prepare("
                    SELECT * FROM sections WHERE id = ? AND school_id = ?
                ");
                $sectionStmt->execute([$sectionId, $school['id']]);
                $selectedSection = $sectionStmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // Get timetable grid
        if ($timetableManager && $classId && $academicYearId && $academicTermId) {
            $filters = [
                'class_id' => $classId,
                'section_id' => $sectionId,
                'academic_year_id' => $academicYearId,
                'academic_term_id' => $academicTermId
            ];
            $timetableGrid = $timetableManager->getTimetableGrid($filters);
        }

    } catch (Exception $e) {
        error_log("Error fetching data: " . $e->getMessage());
        $toastError = "Error loading data. Please refresh.";
    }
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

if (!function_exists('getDayDisplayName')) {
    function getDayDisplayName($day) {
        return ucfirst($day);
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="View Timetable - School Management System">
    <meta name="keywords" content="View Timetable, School Management">
    <meta name="robots" content="INDEX,FOLLOW">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Timetable - <?php echo htmlspecialchars($school['name'] ?? 'School'); ?></title>
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
        .timetable-grid {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 12px;
            overflow: hidden;
        }
        .timetable-grid th {
            background: #f8f9fa;
            padding: 16px;
            font-weight: 600;
            text-align: center;
            border-bottom: 2px solid #dee2e6;
        }
        .timetable-grid td {
            padding: 12px;
            border: 1px solid #dee2e6;
            vertical-align: top;
            min-width: 150px;
        }
        .period-time {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 8px;
        }
        .period-cell {
            background: #f8f9fa;
            font-weight: 600;
            text-align: center;
            width: 80px;
        }
        .timetable-entry {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .timetable-entry.break {
            background: #f5f5f5;
            border-left-color: #9e9e9e;
        }
        .entry-subject {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .entry-teacher {
            font-size: 12px;
            color: #495057;
            margin-bottom: 4px;
        }
        .entry-room {
            font-size: 12px;
            color: #6c757d;
        }
        .badge-break {
            background: #9e9e9e;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .day-header {
            text-transform: uppercase;
            font-size: 14px;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer">
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
                <h1 class="fw-semibold mb-4 h6 text-primary-light">View Timetable</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="timetable-list.php" class="text-secondary-light hover-text-primary hover-underline"> / Timetable</a>
                    <span class="text-secondary-light">/ View Timetable</span>
                </div>
            </div>
            <div>
                <a href="add-timetable.php" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">
                    <i class="ri-add-line me-2"></i>Add New Entry
                </a>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h6 class="fw-semibold mb-16">Select Timetable to View</h6>
            <form method="GET" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="class_id" class="form-label">Class <span class="text-danger">*</span></label>
                    <select class="form-select" id="class_id" name="class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo ($classId == $class['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="section_id" class="form-label">Section</label>
                    <select class="form-select" id="section_id" name="section_id">
                        <option value="">All Sections</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="academic_year_id" class="form-label">Academic Year</label>
                    <select class="form-select" id="academic_year_id" name="academic_year_id">
                        <option value="">Default Year</option>
                        <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php echo ($academicYearId == $year['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="academic_term_id" class="form-label">Academic Term</label>
                    <select class="form-select" id="academic_term_id" name="academic_term_id">
                        <option value="">Default Term</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary-600 w-100">
                        <i class="ri-eye-line me-2"></i>View Timetable
                    </button>
                </div>
            </form>
        </div>

        <?php if ($classId && $selectedClass): ?>
        <!-- Timetable Display -->
        <div class="card">
            <div class="card-header bg-white py-16 px-24 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-semibold mb-1">
                            Timetable for Class: <?php echo htmlspecialchars($selectedClass['name']); ?>
                            <?php if ($selectedSection): ?>
                            - Section <?php echo htmlspecialchars($selectedSection['name']); ?>
                            <?php endif; ?>
                        </h5>
                        <p class="text-secondary-light mb-0">
                            Academic Year: <?php echo htmlspecialchars($selectedYear['name'] ?? ($academicYearId ? 'Selected Year' : 'Default')); ?> | 
                            Term: <?php echo htmlspecialchars($selectedTerm['name'] ?? ($academicTermId ? 'Selected Term' : 'Default')); ?>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="ri-printer-line me-2"></i>Print
                        </button>
                        <a href="add-timetable.php?class_id=<?php echo $classId; ?>&academic_year=<?php echo $academicYearId; ?>&academic_term=<?php echo $academicTermId; ?>" 
                           class="btn btn-primary-600">
                            <i class="ri-add-line me-2"></i>Add Entry
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body p-24">
                <?php if (empty($timetableGrid['grid'])): ?>
                <div class="text-center py-5">
                    <i class="ri-calendar-todo-line fs-1 text-secondary-light"></i>
                    <p class="mt-3 text-secondary-light">No timetable entries found for the selected criteria</p>
                    <a href="add-timetable.php?class_id=<?php echo $classId; ?>&academic_year=<?php echo $academicYearId; ?>&academic_term=<?php echo $academicTermId; ?>" 
                       class="btn btn-primary-600 mt-3">Add First Entry</a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="timetable-grid">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <?php foreach ($timetableGrid['days'] as $day): ?>
                                <th class="day-header"><?php echo getDayDisplayName($day); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($timetableGrid['periods'] as $period): ?>
                            <tr>
                                <td class="period-cell">
                                    <div class="fw-bold">Period <?php echo $period; ?></div>
                                </td>
                                <?php foreach ($timetableGrid['days'] as $day): ?>
                                <td>
                                    <?php 
                                    $entry = $timetableGrid['grid'][$day][$period] ?? null;
                                    if ($entry):
                                    ?>
                                        <div class="timetable-entry <?php echo $entry['is_break'] ? 'break' : ''; ?>">
                                            <?php if ($entry['is_break']): ?>
                                                <div class="badge-break mb-2">BREAK</div>
                                            <?php else: ?>
                                                <div class="entry-subject">
                                                    <?php echo htmlspecialchars($entry['subject_name'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="entry-teacher">
                                                    <i class="ri-user-line me-1"></i>
                                                    <?php echo htmlspecialchars($entry['teacher_name'] ?? 'N/A'); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($entry['room_number'])): ?>
                                            <div class="entry-room">
                                                <i class="ri-door-line me-1"></i>
                                                Room: <?php echo htmlspecialchars($entry['room_number']); ?>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="period-time">
                                                <?php echo formatTime($entry['start_time']); ?> - 
                                                <?php echo formatTime($entry['end_time']); ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center text-secondary-light py-3">
                                            <small>No class</small>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="mt-24 p-20 bg-neutral-50 radius-12">
            <div class="row g-4">
                <div class="col-auto">
                    <div class="d-flex align-items-center gap-2">
                        <div class="w-20-px h-20-px" style="background: #e3f2fd; border-left: 4px solid #1976d2;"></div>
                        <span>Regular Class</span>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex align-items-center gap-2">
                        <div class="w-20-px h-20-px" style="background: #f5f5f5; border-left: 4px solid #9e9e9e;"></div>
                        <span>Break Period</span>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex align-items-center gap-2">
                        <i class="ri-door-line"></i>
                        <span>Room Number</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // Initialize Bootstrap toasts
    $('.toast').toast({
        autohide: true,
        delay: 5000
    });
    $('.toast').toast('show');

    // Load sections when class changes
    $('#class_id').on('change', function() {
        const classId = $(this).val();
        if (classId) {
            $.ajax({
                url: 'ajax/get-sections.php',
                method: 'POST',
                data: {
                    class_id: classId,
                    school_id: <?php echo $school['id']; ?>
                },
                dataType: 'json',
                beforeSend: function() {
                    $('#section_id').html('<option value="">Loading...</option>').prop('disabled', true);
                },
                success: function(response) {
                    let options = '<option value="">All Sections</option>';
                    if (response.success && response.sections.length > 0) {
                        $.each(response.sections, function(index, section) {
                            const selected = (section.id == <?php echo $sectionId ?? 0; ?>) ? 'selected' : '';
                            options += '<option value="' + section.id + '" ' + selected + '>' + section.name + '</option>';
                        });
                    }
                    $('#section_id').html(options).prop('disabled', false);
                },
                error: function() {
                    $('#section_id').html('<option value="">Error loading sections</option>');
                }
            });
        } else {
            $('#section_id').html('<option value="">All Sections</option>').prop('disabled', false);
        }
    });

    // Load terms when academic year changes
    $('#academic_year_id').on('change', function() {
        const yearId = $(this).val();
        if (yearId) {
            $.ajax({
                url: 'ajax/get-terms.php',
                method: 'POST',
                data: {
                    academic_year_id: yearId,
                    school_id: <?php echo $school['id']; ?>
                },
                dataType: 'json',
                beforeSend: function() {
                    $('#academic_term_id').html('<option value="">Loading...</option>').prop('disabled', true);
                },
                success: function(response) {
                    let options = '<option value="">Default Term</option>';
                    if (response.success && response.terms.length > 0) {
                        $.each(response.terms, function(index, term) {
                            const selected = (term.id == <?php echo $academicTermId ?? 0; ?>) ? 'selected' : '';
                            options += '<option value="' + term.id + '" ' + selected + '>' + term.name + '</option>';
                        });
                    }
                    $('#academic_term_id').html(options).prop('disabled', false);
                },
                error: function() {
                    $('#academic_term_id').html('<option value="">Error loading terms</option>');
                }
            });
        }
    });

    // Trigger initial loads if values are preselected
    if ($('#class_id').val()) {
        $('#class_id').trigger('change');
    }
    if ($('#academic_year_id').val()) {
        $('#academic_year_id').trigger('change');
    }

    // Set current year in footer
    $('.current-year').text(new Date().getFullYear());
});
</script>

</body>
</html>