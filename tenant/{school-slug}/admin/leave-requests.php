<?php
/**
 * Leave Requests Management Page
 * List, view, update status, and delete leave requests.
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/leave_requests.log');

error_log("=== LEAVE REQUESTS PAGE START ===");
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');

// Start session safely
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
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];

if (empty($schoolSlug)) {
    error_log("ERROR: Empty school slug from router");
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'School identifier missing']);
    exit;
}

// Get school info from session or GLOBALS
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
    error_log("User not authenticated, redirecting to login");
    header('Location: ../../login.php?school_slug=' . urlencode($schoolSlug));
    exit;
}

// Get user info from session
$schoolAuth = $_SESSION['school_auth'];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (admin, teacher, parent, etc. - adjust as needed)
if (!in_array($userType, ['admin', 'teacher', 'parent'])) {
    error_log("ERROR: User does not have access to leave requests");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied.";
    exit;
}

// Load configuration and autoload
try {
    $autoloadPath = __DIR__ . '/../../../includes/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("Autoload file not found at: " . $autoloadPath);
    }
    require_once $autoloadPath;

    if (!class_exists('Database')) {
        throw new Exception("Database class not found");
    }
} catch (Exception $e) {
    error_log("Error loading files: " . $e->getMessage());
    http_response_code(500);
    die("Configuration loading failed: " . $e->getMessage());
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
    $schoolDb = null;
}

// Load LeaveRequestManager
require_once __DIR__ . '/../../../includes/LeaveRequestManager.php';
$manager = new LeaveRequestManager($schoolDb, $school['id']);

// Helper function for CSRF token
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

// Handle AJAX requests (view, update status, delete)
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'view':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                $response = ['success' => false, 'message' => 'Invalid ID.'];
            } else {
                $data = $manager->getById($id);
                if ($data) {
                    $response = ['success' => true, 'data' => $data];
                } else {
                    $response = ['success' => false, 'message' => 'Leave request not found.'];
                }
            }
            break;

        case 'update_status':
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $note = trim($_POST['note'] ?? '');
            if (!$id || !in_array($status, ['pending', 'approved', 'rejected'])) {
                $response = ['success' => false, 'message' => 'Invalid parameters.'];
            } else {
                $response = $manager->updateStatus($id, $status, $note, $userId);
            }
            break;

        case 'delete':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                $response = ['success' => false, 'message' => 'Invalid ID.'];
            } else {
                $response = $manager->delete($id);
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// Fetch all leave requests (filtered by user type)
$leaveRequests = $manager->getAll($userType, $userId);

// Generate CSRF token
$csrfToken = generateCsrfToken();

error_log("=== LEAVE REQUESTS PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Leave Requests - School Management System">
    <meta name="keywords" content="Leave Requests, School Management">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave Requests - <?php echo htmlspecialchars($school['name']); ?></title>
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
    </style>
</head>
<body>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Theme Customization Structure (unchanged) -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar (include your dynamic sidebar) -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <!-- Navbar unchanged (you can update user info dynamically) -->
        <div class="row align-items-center justify-content-between">
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-4">
                    <button type="button" class="sidebar-mobile-toggle" aria-label="Sidebar Mobile Toggler Button">
                        <iconify-icon icon="heroicons:bars-3-solid" class="icon"></iconify-icon>
                    </button>
                    <form class="navbar-search">
                        <input type="text" class="bg-transparent" name="search" placeholder="Search">
                        <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                    </form>
                </div>
            </div>
            <div class="col-auto">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <button type="button" data-theme-toggle class="w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center" aria-label="Dark & Light Mode Button"></button>
                    <div class="dropdown">
                        <button class="has-indicator w-40-px h-40-px bg-neutral-200 rounded-circle d-flex justify-content-center align-items-center position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notification Button">
                            <iconify-icon icon="iconoir:bell" class="text-primary-light text-xl"></iconify-icon>
                        </button>
                        <div class="dropdown-menu to-top dropdown-menu-lg p-0">
                            <div class="text-center py-20">
                                <p class="text-secondary-light">No new notifications</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Leave Requests</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light"> / Leave Requests</span>
                </div>
            </div>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-file-upload-line text-md line-height-1"></i>
                                        Export
                                    </span>
                                    <span class=""><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <ul class="dropdown-menu p-12 border bg-base shadow">
                                    <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" onclick="exportTable('pdf')"><i class="ri-file-3-line"></i>PDF</button></li>
                                    <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" onclick="exportTable('excel')"><i class="ri-file-excel-line"></i>Excel</button></li>
                                </ul>
                            </div>
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" id="searchInput" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span>Rows per page:</span>
                            <div class="dt-length">
                                <select id="pageLength" class="dt-input form-control form-select">
                                    <option value="5">5</option>
                                    <option value="10" selected>10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="p-0">
                        <table class="table bordered-table mb-0 data-table" id="leaveRequestsTable">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Apply Date</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">User Type</th>
                                    <th scope="col">Leave Type</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Duration</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php foreach ($leaveRequests as $index => $request): ?>
                                <tr data-id="<?php echo $request['id']; ?>">
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo date('d M Y', strtotime($request['applied_on'])); ?></td>
                                    <td><?php echo htmlspecialchars($request['user_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo ucfirst($request['user_type'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        $icon = '';
                                        switch($request['leave_type_name'] ?? '') {
                                            case 'Medical Leave': $icon = 'ri-hospital-line'; break;
                                            case 'Casual Leave': $icon = 'ri-sun-line'; break;
                                            case 'Half Day Leave': $icon = 'ri-time-line'; break;
                                            case 'Vacation Leave': $icon = 'ri-flight-takeoff-line'; break;
                                            case 'Study Leave': $icon = 'ri-book-open-line'; break;
                                            case 'Paid Leave': $icon = 'ri-money-dollar-circle-line'; break;
                                            case 'Emergency Leave': $icon = 'ri-alarm-warning-line'; break;
                                            case 'Maternity Leave': $icon = 'ri-parent-line'; break;
                                            case 'Paternity Leave': $icon = 'ri-user-heart-line'; break;
                                            case 'Unpaid Leave': $icon = 'ri-close-circle-line'; break;
                                            default: $icon = 'ri-calendar-line';
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?> me-1"></i>
                                        <?php echo htmlspecialchars($request['leave_type_name'] ?? 'N/A'); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $start = date('d M Y', strtotime($request['start_date']));
                                        $end = date('d M Y', strtotime($request['end_date']));
                                        echo $start . ($start != $end ? ' - ' . $end : '');
                                        ?>
                                    </td>
                                    <td><?php echo $request['duration']; ?></td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        switch($request['status']) {
                                            case 'approved':
                                                $statusClass = 'bg-success-100 text-success-600';
                                                break;
                                            case 'pending':
                                                $statusClass = 'bg-warning-100 text-warning-600';
                                                break;
                                            case 'rejected':
                                                $statusClass = 'bg-danger-100 text-danger-600';
                                                break;
                                            default:
                                                $statusClass = 'bg-secondary-100 text-secondary-600';
                                        }
                                        ?>
                                        <span class="<?php echo $statusClass; ?> px-24 py-4 radius-4 fw-medium text-sm">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button" class="view-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-id="<?php echo $request['id']; ?>">
                                                        <i class="ri-eye-line"></i> View Request
                                                    </button>
                                                </li>
                                                <?php if ($userType === 'admin'): ?>
                                                <li>
                                                    <button type="button" class="update-status-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-id="<?php echo $request['id']; ?>" data-status="<?php echo $request['status']; ?>">
                                                        <i class="ri-pencil-line"></i> Update Status
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <li>
                                                    <button type="button" class="delete-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" data-id="<?php echo $request['id']; ?>">
                                                        <i class="ri-delete-bin-6-line"></i> Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($leaveRequests)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-20 text-secondary-light">
                                        No leave requests found.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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

<!-- View/Update Sidebar -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0" id="viewSidebar">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Leave Request Details</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <div class="p-20" id="sidebarContent">
        <!-- Dynamic content will be loaded here -->
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0" id="deleteConfirmMessage">Are you sure you want to delete this leave request?</h6>
                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                    <button type="button" class="flex-grow-1 border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-24 py-11 radius-8" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="flex-grow-1 btn btn-primary-600 border border-primary-600 text-md px-16 py-12 radius-8" id="confirmDeleteBtn">Yes, Delete</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
$(document).ready(function() {
    // DataTable initialization
    let table = new DataTable('#leaveRequestsTable', {
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        searching: true,
        ordering: true,
        info: true,
        paging: true,
        dom: 'rtip',
    });

    // Bind external search
    $('#searchInput').on('keyup', function() {
        table.search(this.value).draw();
    });

    // Bind external page length
    $('#pageLength').on('change', function() {
        table.page.len(parseInt($(this).val())).draw();
    });

    // Toast function
    function showToast(message, type = 'success') {
        const toastHtml = `
            <div class="toast ${type} show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
                <div class="toast-header">
                    <i class="ri-${type === 'success' ? 'checkbox-circle' : 'error-warning'}-line me-2"></i>
                    <strong class="me-auto">${type === 'success' ? 'Success' : 'Error'}</strong>
                    <small>just now</small>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;
        $('#toastContainer').append(toastHtml);
        $('.toast').toast('show');
        setTimeout(() => {
            $('.toast').first().remove();
        }, 5000);
    }

    // Sidebar open/close
    $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    // View button click
    $(document).on('click', '.view-btn', function() {
        const id = $(this).data('id');
        $.post(window.location.href, {
            action: 'view',
            id: id,
            csrf_token: '<?php echo $csrfToken; ?>'
        }, function(response) {
            if (response.success) {
                const d = response.data;
                let html = `
                    <div class="d-flex flex-column gap-28">
                        <div class="d-flex flex-column gap-8">
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Apply Date</span>
                                <span class="fw-normal text-sm text-primary-light">: ${new Date(d.applied_on).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})}</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Name</span>
                                <span class="fw-normal text-sm text-primary-light">: ${d.user_name || 'N/A'}</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">User Type</span>
                                <span class="fw-normal text-sm text-primary-light">: ${d.user_type ? d.user_type.charAt(0).toUpperCase() + d.user_type.slice(1) : 'N/A'}</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Leave Type</span>
                                <span class="fw-normal text-sm text-primary-light">: ${d.leave_type_name || 'N/A'}</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Date</span>
                                <span class="fw-normal text-sm text-primary-light">: ${new Date(d.start_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})} - ${new Date(d.end_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'})}</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Duration</span>
                                <span class="fw-normal text-sm text-primary-light">: ${d.duration} day(s)</span>
                            </div>
                            <div class="d-flex gap-4">
                                <span class="fw-semibold text-sm text-secondary-light w-110-px">Reason</span>
                                <span class="fw-normal text-sm text-primary-light">: ${d.reason || 'N/A'}</span>
                            </div>
                        </div>
                `;
                // Only show status update if user is admin
                <?php if ($userType === 'admin'): ?>
                html += `
                        <div class="">
                            <h5 class="text-md mb-0">Update Status</h5>
                            <form id="statusUpdateForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="id" value="${d.id}">
                                <div class="d-flex align-items-center flex-wrap gap-28 mt-16">
                                    <div class="form-check checked-primary d-flex align-items-center gap-2">
                                        <input class="form-check-input" type="radio" name="status" value="pending" id="statusPending" ${d.status === 'pending' ? 'checked' : ''}>
                                        <label class="form-check-label" for="statusPending">Pending</label>
                                    </div>
                                    <div class="form-check checked-primary d-flex align-items-center gap-2">
                                        <input class="form-check-input" type="radio" name="status" value="approved" id="statusApproved" ${d.status === 'approved' ? 'checked' : ''}>
                                        <label class="form-check-label" for="statusApproved">Approved</label>
                                    </div>
                                    <div class="form-check checked-primary d-flex align-items-center gap-2">
                                        <input class="form-check-input" type="radio" name="status" value="rejected" id="statusRejected" ${d.status === 'rejected' ? 'checked' : ''}>
                                        <label class="form-check-label" for="statusRejected">Rejected</label>
                                    </div>
                                </div>
                                <div class="mt-16">
                                    <label for="statusNote" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Note (optional)</label>
                                    <textarea class="form-control" id="statusNote" name="note" placeholder="Enter note...">${d.rejection_reason || ''}</textarea>
                                </div>
                                <div class="d-flex align-items-center justify-content-center gap-3 mt-24">
                                    <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">Cancel</button>
                                    <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">Update</button>
                                </div>
                            </form>
                        </div>
                `;
                <?php endif; ?>
                html += `</div>`;
                $('#sidebarContent').html(html);
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');

                // Handle status update form submission
                $('#statusUpdateForm').on('submit', function(e) {
                    e.preventDefault();
                    const formData = $(this).serialize();
                    $.post(window.location.href, formData, function(response) {
                        if (response.success) {
                            showToast(response.message, 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showToast(response.message, 'error');
                        }
                    }, 'json').fail(function() {
                        showToast('Request failed.', 'error');
                    });
                });
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Failed to fetch request details.', 'error');
        });
    });

    // Delete button click
    let deleteId = null;
    $(document).on('click', '.delete-btn', function() {
        deleteId = $(this).data('id');
        $('#deleteConfirmMessage').text('Are you sure you want to delete this leave request?');
        $('#deleteModal').modal('show');
    });

    // Confirm delete
    $('#confirmDeleteBtn').on('click', function() {
        if (!deleteId) return;
        $.post(window.location.href, {
            action: 'delete',
            id: deleteId,
            csrf_token: '<?php echo $csrfToken; ?>'
        }, function(response) {
            $('#deleteModal').modal('hide');
            if (response.success) {
                showToast(response.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Request failed.', 'error');
        });
    });

    // Export placeholder
    window.exportTable = function(format) {
        showToast(`Export as ${format.toUpperCase()} coming soon.`, 'info');
    };
});
</script>
</body>
</html>