<?php
/**
 * Role & Access Management Page
 * List, create, edit, delete roles.
 */

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../../logs/role_access.log');

error_log("=== ROLE ACCESS PAGE START ===");
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

// Verify access (only admin should manage roles)
if (!in_array($userType, ['admin'])) {
    error_log("ERROR: User does not have access to role management");
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

// Load RoleManager
require_once __DIR__ . '/../../../includes/RoleManager.php';
$manager = new RoleManager($schoolDb, $school['id']);

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

// Handle AJAX requests (CRUD)
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
        case 'create':
            $data = [
                'name'        => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'permissions' => null
            ];
            if (empty($data['name'])) {
                $response = ['success' => false, 'message' => 'Role name is required.'];
            } else {
                $response = $manager->create($data);
            }
            break;

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                $response = ['success' => false, 'message' => 'Invalid ID.'];
                break;
            }
            $data = [
                'name'        => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'permissions' => null
            ];
            if (empty($data['name'])) {
                $response = ['success' => false, 'message' => 'Role name is required.'];
            } else {
                $response = $manager->update($id, $data);
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

        case 'get':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                $response = ['success' => false, 'message' => 'Invalid ID.'];
            } else {
                $data = $manager->getById($id);
                if ($data) {
                    $response = ['success' => true, 'data' => $data];
                } else {
                    $response = ['success' => false, 'message' => 'Role not found.'];
                }
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// Fetch all roles
$roles = $manager->getAll();

// Generate CSRF token
$csrfToken = generateCsrfToken();

error_log("=== ROLE ACCESS PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Role & Access Management - School Management System">
    <meta name="keywords" content="Role Access, Permissions, School Management">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role & Access - <?php echo htmlspecialchars($school['name']); ?></title>
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
        .system-badge {
            background-color: #e9ecef;
            color: #495057;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 30px;
            margin-left: 8px;
        }
        /* Responsive table wrapper */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        /* Ensure modals appear above overlay */
        .modal {
            z-index: 1050;
        }
        .modal-backdrop {
            z-index: 1040;
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
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div class="">
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Role & Access</h1>
                <div class="">
                    <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <a href="employee-list.php" class="text-secondary-light hover-text-primary hover-underline"> / HRM</a>
                    <span class="text-secondary-light"> / Role & Access</span>
                </div>
            </div>
            <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6">
                <span class="d-flex text-md">
                    <i class="ri-add-large-line"></i>
                </span>
                Add Role
            </button>
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

                    <!-- Responsive table wrapper -->
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0 data-table" id="rolesTable">
                            <thead>
                                <tr>
                                    <th scope="col" width="50">
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label">S.L</label>
                                        </div>
                                    </th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Role</th>
                                    <th scope="col">Description</th>
                                    <th scope="col">Action</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php foreach ($roles as $index => $role): ?>
                                <tr data-id="<?php echo $role['id']; ?>">
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label"><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></label>
                                        </div>
                                    </td>
                                    <td><?php echo date('d M Y', strtotime($role['created_at'])); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($role['name']); ?>
                                        <?php if ($role['is_system']): ?>
                                            <span class="system-badge">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($role['description'] ?: '-'); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" aria-expanded="false">
                                                <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                                                <li>
                                                    <button type="button" class="edit-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" 
                                                            data-id="<?php echo $role['id']; ?>"
                                                            <?php echo $role['is_system'] ? 'disabled' : ''; ?>>
                                                        <i class="ri-edit-2-line"></i> Edit
                                                    </button>
                                                </li>
                                                <li>
                                                    <button type="button" class="delete-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6" 
                                                            data-id="<?php echo $role['id']; ?>" 
                                                            data-name="<?php echo htmlspecialchars($role['name']); ?>"
                                                            <?php echo $role['is_system'] ? 'disabled' : ''; ?>>
                                                        <i class="ri-delete-bin-6-line"></i> Delete
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($roles)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-20 text-secondary-light">
                                        No roles found.
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Add/Edit Sidebar (used for both add and edit) -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0" id="formSidebar">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0" id="sidebarTitle">Add Role</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
            <i class="ri-close-large-line"></i>
        </button>
    </div>
    <form id="roleForm" class="d-flex flex-column p-20">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="id" id="roleId" value="0">
        <div class="row g-3">
            <div class="col-sm-12">
                <label for="roleName" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Role Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="roleName" name="name" placeholder="Enter Role Name" required>
            </div>
            <div class="col-sm-12">
                <label for="roleDescription" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Description</label>
                <textarea class="form-control" id="roleDescription" name="description" rows="3" placeholder="Optional description"></textarea>
            </div>
            <div class="col-12">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
                    <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8">Cancel</button>
                    <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">Save</button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body pt-32 px-36 pb-24 text-center">
                <span class="mb-16 fs-1 line-height-1 text-danger">
                    <iconify-icon icon="fluent:delete-24-regular"></iconify-icon>
                </span>
                <h6 class="text-lg fw-semibold text-primary-light mb-0" id="deleteConfirmMessage">Are you sure you want to delete this role?</h6>
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
    let table = new DataTable('#rolesTable', {
        pageLength: 10,
        lengthMenu: [5, 10, 25, 50, 100],
        searching: true,
        ordering: true,
        info: true,
        paging: true,
        dom: 'rtip',
        columnDefs: [
            { orderable: false, targets: [0, 4] }
        ]
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
    $('.my-sidebar-btn').on('click', function() {
        $('#sidebarTitle').text('Add Role');
        $('#formAction').val('create');
        $('#roleId').val(0);
        $('#roleForm')[0].reset();
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
    });
    $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    // Edit button click
    $(document).on('click', '.edit-btn:not([disabled])', function() {
        const id = $(this).data('id');
        $.post(window.location.href, {
            action: 'get',
            id: id,
            csrf_token: '<?php echo $csrfToken; ?>'
        }, function(response) {
            if (response.success) {
                const data = response.data;
                $('#sidebarTitle').text('Edit Role');
                $('#formAction').val('update');
                $('#roleId').val(data.id);
                $('#roleName').val(data.name);
                $('#roleDescription').val(data.description || '');
                $('.my-sidebar').addClass('active');
                $('.overlay').addClass('active');
            } else {
                showToast(response.message, 'error');
            }
        }, 'json').fail(function() {
            showToast('Failed to fetch role data.', 'error');
        });
    });

    // Form submission (create/update)
    $('#roleForm').on('submit', function(e) {
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
            showToast('Request failed. Please try again.', 'error');
        });
    });

    // Delete button click (opens modal)
    let deleteId = null;
    $(document).on('click', '.delete-btn:not([disabled])', function() {
        deleteId = $(this).data('id');
        const name = $(this).data('name');
        $('#deleteConfirmMessage').text(`Are you sure you want to delete "${name}"?`);
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