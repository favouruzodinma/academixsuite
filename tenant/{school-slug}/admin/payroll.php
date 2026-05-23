<?php require_once __DIR__ . '/includes/handlers/hrm-handler.php'; ?>
<?php
/**
 * Payroll List Page
 * Displays all employees with their payroll information
 */

// Error reporting (disable in production)
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_log("=== PAYROLL LIST PAGE START ===");

// Define constants if not defined
defined('APP_NAME') or define('APP_NAME', 'AcademixSuite');
defined('IS_LOCAL') or define('IS_LOCAL', true);

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,
        'read_and_close'  => false,
    ]);
}

// Get school slug from GLOBALS (set by router.php)
$schoolSlug = $GLOBALS['SCHOOL_SLUG'] ?? '';
$userType = $GLOBALS['USER_TYPE'] ?? 'admin';
$currentPage = $GLOBALS['CURRENT_PAGE'] ?? 'payroll.php';
$schoolData = $GLOBALS['SCHOOL_DATA'] ?? [];
$baseUrl = $GLOBALS['BASE_URL'] ?? '';

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
$schoolAuth = $_SESSION['school_auth'] ?? [];
$userId = (int)($schoolAuth['user_id'] ?? 0);
$userType = $schoolAuth['user_type'] ?? '';

// Verify access (only admin and accountant should access payroll)
if (!in_array($userType, ['admin', 'accountant'])) {
    error_log("ERROR: User does not have access to payroll");
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied.";
    exit;
}

// Load configuration
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

// Load PayrollManager
require_once __DIR__ . '/../../../includes/PayrollManager.php';
$payrollManager = new PayrollManager($schoolDb, $school['id']);

// ========== PAYROLL-SPECIFIC CSRF FUNCTIONS ==========
function generatePayrollCsrfToken() {
    if (!isset($_SESSION['payroll_csrf_token'])) {
        $_SESSION['payroll_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['payroll_csrf_token'];
}
function validatePayrollCsrfToken($token) {
    return isset($_SESSION['payroll_csrf_token']) && hash_equals($_SESSION['payroll_csrf_token'], $token);
}
// =====================================================

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validatePayrollCsrfToken($csrfToken)) {
        error_log("Payroll CSRF validation failed. Received: " . $csrfToken . ", Expected: " . ($_SESSION['payroll_csrf_token'] ?? 'none'));
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'get_payslip':
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $periodId = (int)($_POST['period_id'] ?? 0);
            if ($employeeId) {
                if ($periodId) {
                    $payslip = $payrollManager->getPayslip($employeeId, $periodId);
                } else {
                    $payslip = $payrollManager->getLatestPayslip($employeeId);
                }
                if ($payslip) {
                    $response = ['success' => true, 'data' => $payslip];
                } else {
                    $response = ['success' => false, 'message' => 'Payslip not found'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Missing employee ID'];
            }
            break;

        case 'update_payment_status':
            $payslipId = (int)($_POST['payslip_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            if ($payslipId && in_array($status, ['pending','paid','failed'])) {
                $response = $payrollManager->updatePaymentStatus($payslipId, $status, $userId);
            } else {
                $response = ['success' => false, 'message' => 'Invalid parameters'];
            }
            break;

        case 'process_payroll':
            $periodId = (int)($_POST['period_id'] ?? 0);
            if ($periodId) {
                $response = $payrollManager->processPayroll($periodId, $userId);
            } else {
                $response = ['success' => false, 'message' => 'Period ID required'];
            }
            break;

        case 'get_eligible_users':
            $eligible = $payrollManager->getEligiblePayrollUsers();
            $response = ['success' => true, 'data' => $eligible];
            break;

        case 'add_to_payroll':
            $userIds = isset($_POST['user_ids']) ? json_decode($_POST['user_ids'], true) : [];
            if (!empty($userIds) && is_array($userIds)) {
                $result = $payrollManager->addUsersToPayroll($userIds);
                // Convert array response to object
                if (is_array($result) && isset($result[0])) {
                    $response = ['success' => $result[0], 'message' => $result[1]];
                } else {
                    $response = ['success' => false, 'message' => 'Unexpected response'];
                }
            } else {
                $response = ['success' => false, 'message' => 'No users selected'];
            }
            break;

        case 'get_employee_details':
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            if ($employeeId) {
                $details = $payrollManager->getEmployeeDetails($employeeId);
                if ($details) {
                    $response = ['success' => true, 'data' => $details];
                } else {
                    $response = ['success' => false, 'message' => 'Employee not found'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Missing employee ID'];
            }
            break;

        case 'update_payroll_details':
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $data = [
                'employee_number' => $_POST['employee_number'] ?? '',
                'department'      => $_POST['department'] ?? '',
                'designation'     => $_POST['designation'] ?? '',
                'bank_name'       => $_POST['bank_name'] ?? '',
                'bank_account'    => $_POST['bank_account'] ?? '',
                'ifsc_code'       => $_POST['ifsc_code'] ?? '',
                'salary_grade_id' => $_POST['salary_grade_id'] ?? '',
                'basic_salary'    => $_POST['basic_salary'] ?? ''
            ];
            if ($employeeId) {
                $response = $payrollManager->updateEmployeeDetails($employeeId, $data);
            } else {
                $response = ['success' => false, 'message' => 'Missing employee ID'];
            }
            break;

        case 'remove_from_payroll':
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $permanent = ($_POST['permanent'] ?? 'false') === 'true';
            if ($employeeId) {
                $response = $payrollManager->removeFromPayroll($employeeId, $permanent);
            } else {
                $response = ['success' => false, 'message' => 'Missing employee ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

// Fetch all employees (the manager's getEmployees() now includes teacher_subjects)
$employees = $payrollManager->getEmployees();

// Fetch open periods for "Process Payroll" button
$periods = $payrollManager->getPayrollPeriods('open');

// Fetch salary grades for edit form
$salaryGrades = $payrollManager->getSalaryGrades();

// Get settings for currency symbol etc.
$settings = [];
try {
    $settingsStmt = $schoolDb->prepare("SELECT `key`, `value` FROM settings WHERE school_id = ?");
    if ($settingsStmt) {
        $settingsStmt->execute([$school['id']]);
        while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
    }
} catch (Exception $e) {
    error_log("Error loading settings: " . $e->getMessage());
}

$currencySymbol = $settings['currency_symbol'] ?? '₦';

// Get logged in user details
$adminUser = ['name' => 'Admin User', 'role_name' => 'Administrator'];
try {
    $userStmt = $schoolDb->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.id = ? AND u.school_id = ?
        LIMIT 1
    ");
    if ($userStmt) {
        $userStmt->execute([$userId, $school['id']]);
        $adminUserData = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($adminUserData) {
            $adminUser = $adminUserData;
        } elseif (isset($_SESSION['school_user']['name'])) {
            $adminUser = [
                'name' => $_SESSION['school_user']['name'],
                'role_name' => 'Administrator'
            ];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching user details: " . $e->getMessage());
}

// Generate payroll-specific CSRF token
$csrfToken = generatePayrollCsrfToken();

error_log("=== PAYROLL LIST PAGE END ===");
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="Payroll Management">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | Payroll</title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>
<body>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;" id="toastContainer"></div>

<!-- Theme Customization Structure Start -->



<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php') ?>

<main class="dashboard-main">
<?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-semibold mb-4 h6 text-primary-light">Payroll</h1>
                <div>
                    <a href="index.html" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
                    <span class="text-secondary-light">/ Payroll</span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php if (!empty($periods)): ?>
                <button type="button" class="my-sidebar-btn btn btn-primary-600 d-flex align-items-center gap-6" data-bs-toggle="modal" data-bs-target="#processPayrollModal">
                    <span class="d-flex text-md"><i class="ri-add-large-line"></i></span>
                    Process Payroll
                </button>
                <?php endif; ?>
                <button type="button" class="btn btn-success-600 d-flex align-items-center gap-6" id="addToPayrollBtn">
                    <span class="d-flex text-md"><i class="ri-user-add-line"></i></span>
                    Add to Payroll
                </button>
            </div>
        </div>

        <div class="mt-24">
            <div class="card h-100">
                <div class="card-body p-0 dataTable-wrapper">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
                        <div class="d-flex flex-wrap align-items-center gap-16">
                            <!-- Export dropdown -->
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                                        <i class="ri-file-upload-line text-md line-height-1"></i> Export
                                    </span>
                                    <span><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <ul class="dropdown-menu p-12 border bg-base shadow">
                                    <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"><i class="ri-file-3-line"></i> PDF</button></li>
                                    <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10"><i class="ri-file-excel-line"></i> Excel</button></li>
                                </ul>
                            </div>
                            <!-- Search -->
                            <form class="navbar-search dt-search m-0">
                                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable" name="search" placeholder="Search...">
                                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
                            </form>
                            <!-- Filter dropdown -->
                            <div class="dropdown">
                                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">Filter</span>
                                    <span><i class="ri-arrow-down-s-line"></i></span>
                                </button>
                                <div class="dropdown-menu border bg-base shadow dropdown-menu-lg p-0">
                                    <div class="d-flex align-items-center justify-content-between border-bottom py-8 px-16">
                                        <span class="fw-semibold text-lg text-primary-light">Filter</span>
                                        <button type="button"><i class="ri-close-large-line"></i></button>
                                    </div>
                                    <form action="#" class="p-16">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Department</label>
                                                <select class="form-control form-select" id="filterDepartment">
                                                    <option value="">All Departments</option>
                                                    <?php
                                                    $depts = array_unique(array_column($employees, 'department'));
                                                    foreach ($depts as $dept) {
                                                        if (!empty($dept)) {
                                                            echo "<option value=\"" . htmlspecialchars($dept) . "\">" . htmlspecialchars($dept) . "</option>";
                                                        }
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Payment Status</label>
                                                <select class="form-control form-select" id="filterStatus">
                                                    <option value="">All Status</option>
                                                    <option value="Paid">Paid</option>
                                                    <option value="Pending">Pending</option>
                                                    <option value="Unpaid">Unpaid</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <button type="reset" class="btn btn-danger-200 text-danger-600 w-100">Reset</button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="btn btn-primary-600 w-100" id="applyFilter">Apply</button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-8 text-secondary-light">
                            <span>Rows per page:</span>
                            <div class="dt-length">
                                <select name="dataTable_length" aria-controls="dataTable" class="dt-input form-control form-select">
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
                        <table class="table bordered-table mb-0 data-table" id="dataTable" data-page-length='10'>
                            <thead>
                                <tr>
                                    <th scope="col"><div class="form-check style-check d-flex align-items-center"><input class="form-check-input" type="checkbox"><label class="form-check-label">S.L</label></div></th>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Department</th>
                                    <th scope="col">Designation</th>
                                    <th scope="col">Subjects/Classes</th>  <!-- NEW COLUMN -->
                                    <th scope="col">Payment Method</th>
                                    <th scope="col">Net Salary</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $sl = 1; foreach ($employees as $emp): ?>
                                <tr>
                                    <td>
                                        <div class="form-check style-check d-flex align-items-center">
                                            <input class="form-check-input" type="checkbox">
                                            <label class="form-check-label"><?php echo str_pad($sl++, 2, '0', STR_PAD_LEFT); ?></label>
                                        </div>
                                    </td>
                                    <td><span class="text-primary-600"><?php echo htmlspecialchars($emp['employee_number']); ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php 
                                            $photo = !empty($emp['profile_photo']) ? htmlspecialchars($emp['profile_photo']) : 'https://academixsuite.com/tenant/assets/images/thumbs/default-avatar.png';
                                            ?>
                                            <img src="<?php echo $photo; ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>" class="flex-shrink-0 me-12 radius-8" width="40" height="40">
                                            <div>
                                                <h6 class="text-md mb-0 fw-medium flex-grow-1"><?php echo htmlspecialchars($emp['name']); ?></h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($emp['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($emp['designation'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($emp['teacher_subjects'] ?? 'N/A'); ?></td>  <!-- Display subjects -->
                                    <td><?php echo htmlspecialchars($emp['last_payment_method'] ?? 'Bank'); ?></td>
                                    <td><?php echo $currencySymbol . number_format($emp['last_net_salary'] ?? 0, 2); ?></td>
                                    <td>
                                        <?php
                                        $status = $emp['last_payment_status'] ?? 'pending';
                                        $badgeClass = '';
                                        switch ($status) {
                                            case 'paid':
                                                $badgeClass = 'bg-success-100 text-success-600';
                                                break;
                                            case 'pending':
                                                $badgeClass = 'bg-warning-100 text-warning-600';
                                                break;
                                            case 'failed':
                                            default:
                                                $badgeClass = 'bg-danger-100 text-danger-600';
                                                break;
                                        }
                                        ?>
                                        <span class="<?php echo $badgeClass; ?> px-20 py-4 radius-4 fw-medium text-sm"><?php echo ucfirst($status); ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button type="button" class="bg-info-100 text-info-600 px-12 py-4 radius-4 fw-medium text-sm view-payslip-btn" 
                                                    data-employee-id="<?php echo $emp['id']; ?>" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#payslipModal">
                                                <i class="ri-eye-line"></i> Payslip
                                            </button>
                                            <button type="button" class="bg-warning-100 text-warning-600 px-12 py-4 radius-4 fw-medium text-sm edit-employee-btn" 
                                                    data-employee-id="<?php echo $emp['id']; ?>">
                                                <i class="ri-edit-line"></i> Edit
                                            </button>
                                            <button type="button" class="bg-danger-100 text-danger-600 px-12 py-4 radius-4 fw-medium text-sm remove-employee-btn" 
                                                    data-employee-id="<?php echo $emp['id']; ?>"
                                                    data-employee-name="<?php echo htmlspecialchars($emp['name']); ?>">
                                                <i class="ri-delete-bin-line"></i> Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
</main>

<!-- Process Payroll Modal (if periods exist) -->
<?php if (!empty($periods)): ?>
<div class="modal fade" id="processPayrollModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Process Payroll</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Select a payroll period to process:</p>
                <select class="form-select" id="payrollPeriodSelect">
                    <?php foreach ($periods as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['start_date']; ?> - <?php echo $p['end_date']; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary-600" id="processPayrollBtn">Process</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Payslip Modal -->
<div class="modal fade" id="payslipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered max-w-600-px">
        <div class="modal-content radius-16 bg-base">
            <div class="modal-body p-24" id="payslipContent">
                <div class="text-center">
                    <h6 class="mb-0"><?php echo htmlspecialchars($school['name']); ?></h6>
                    <p class="text-secondary-light"><?php echo htmlspecialchars($school['address'] ?? ''); ?></p>
                </div>
                <div class="d-flex align-items-center justify-content-between gap-20 flex-wrap mt-24" id="payslipHeader"></div>
                <ul class="border mt-24 radius-8 overflow-hidden" id="payslipDetails"></ul>
                <div class="pt-28 ms-16 text-start" id="payslipFooter"></div>
                <div class="text-center mt-100-px">
                    <h6 class="text-xl mb-4">Thanks</h6>
                    <p class="text-secondary-light text-sm mb-0">If you need further assistance, please feel free to contact HR at <span class="fw-semibold text-primary-light"><?php echo htmlspecialchars($school['name']); ?></span></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Employee Sidebar -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0" id="editEmployeeSidebar">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Edit Payroll Details</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex"><i class="ri-close-large-line"></i></button>
    </div>
    <div class="p-20">
        <form id="editEmployeeForm">
            <input type="hidden" name="employee_id" id="edit_employee_id">
            <div class="row g-3">
                <div class="col-12">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Employee Number</label>
                    <input type="text" class="form-control" id="edit_employee_number" name="employee_number">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Department</label>
                    <input type="text" class="form-control" id="edit_department" name="department">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Designation</label>
                    <input type="text" class="form-control" id="edit_designation" name="designation">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank Name</label>
                    <input type="text" class="form-control" id="edit_bank_name" name="bank_name">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Bank Account</label>
                    <input type="text" class="form-control" id="edit_bank_account" name="bank_account">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">IFSC Code</label>
                    <input type="text" class="form-control" id="edit_ifsc_code" name="ifsc_code">
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Salary Grade</label>
                    <select class="form-select" id="edit_salary_grade_id" name="salary_grade_id">
                        <option value="">None</option>
                        <?php foreach ($salaryGrades as $sg): ?>
                        <option value="<?php echo $sg['id']; ?>"><?php echo htmlspecialchars($sg['grade_name']); ?> (<?php echo $currencySymbol . $sg['basic_salary']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Basic Salary (if no grade)</label>
                    <input type="number" step="0.01" class="form-control" id="edit_basic_salary" name="basic_salary">
                </div>
            </div>
            <div class="mt-4 d-flex justify-content-end gap-3">
                <button type="button" class="btn btn-secondary" id="cancelEditEmployee">Cancel</button>
                <button type="submit" class="btn btn-primary-600" id="saveEditEmployee">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Add to Payroll Sidebar -->
<div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0" id="addPayrollSidebar">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
        <h5 class="text-lg mb-0">Add Users to Payroll</h5>
        <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex"><i class="ri-close-large-line"></i></button>
    </div>
    <div class="p-20">
        <div id="eligibleUsersContainer">
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p>Loading eligible users...</p>
            </div>
        </div>
        <div class="mt-4 d-flex justify-content-end gap-3">
            <button type="button" class="btn btn-secondary" id="cancelAddPayroll">Cancel</button>
            <button type="button" class="btn btn-primary-600" id="saveAddPayroll">Add Selected</button>
        </div>
    </div>
</div>

<!-- Confirmation Modal for Remove -->
<div class="modal fade" id="confirmRemoveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Removal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove <strong id="removeEmployeeName"></strong> from payroll?</p>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="permanentDelete">
                    <label class="form-check-label" for="permanentDelete">
                        Permanently delete (cannot be undone)
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmRemoveBtn">Remove</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
    let table = new DataTable('#dataTable');
    const csrfToken = '<?php echo $csrfToken; ?>';

    // Toast function
    function showToast(type, message) {
        const toastContainer = document.getElementById('toastContainer');
        const toastId = 'toast-' + Date.now();
        const bgClass = type === 'success' ? 'bg-success' : (type === 'error' ? 'bg-danger' : 'bg-warning');
        const iconClass = type === 'success' ? 'ri-checkbox-circle-line' : (type === 'error' ? 'ri-error-warning-line' : 'ri-alert-line');
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0 mb-2" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="5000">
                <div class="d-flex">
                    <div class="toast-body d-flex align-items-center gap-2">
                        <i class="${iconClass} fs-5"></i>
                        <span>${message}</span>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, { autohide: true, delay: 5000 });
        toast.show();
        
        toastElement.addEventListener('hidden.bs.toast', function () {
            toastElement.remove();
        });
    }

    // DataTable search and length handling
    $('.data-table').each(function () {
        const $table = $(this);
        const tableInstance = new DataTable(this);
        $table.closest('.dataTable-wrapper').find('.dt-search .dt-input').on('keyup', function () {
            tableInstance.search(this.value).draw();
        });
        $table.closest('.dataTable-wrapper').find('.dt-length .dt-input').on('change', function () {
            tableInstance.page.len($(this).val()).draw();
        });
    });

    // View Payslip button
    $('.view-payslip-btn').on('click', function () {
        const employeeId = $(this).data('employee-id');
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_payslip',
                employee_id: employeeId,
                period_id: 0,
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    populatePayslipModal(res.data);
                } else {
                    showToast('error', res.message);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                showToast('error', 'An error occurred while fetching payslip.');
            }
        });
    });

    function populatePayslipModal(data) {
        $('#payslipHeader').empty();
        $('#payslipDetails').empty();
        $('#payslipFooter').empty();

        const headerHtml = `
            <div class="d-flex flex-column">
                <div class="text-sm fw-medium d-flex"><span class="text-primary-light w-110-px text-start">Invoice No</span><span class="text-primary-light">: #${data.id}</span></div>
                <div class="text-sm fw-medium d-flex"><span class="text-primary-light w-110-px text-start">Employee Name</span><span class="text-primary-light">: ${data.employee_name}</span></div>
                <div class="text-sm fw-medium d-flex"><span class="text-primary-light w-110-px text-start">Phone</span><span class="text-primary-light">: ${data.phone}</span></div>
            </div>
            <div class="d-flex flex-column">
                <div class="text-sm fw-medium d-flex"><span class="text-primary-light text-start">Payslip</span></div>
                <div class="text-sm fw-medium d-flex"><span class="text-secondary-light text-start">Month: ${data.period_name}</span></div>
                <div class="text-sm fw-medium d-flex"><span class="text-secondary-light text-start">Payment: ${data.payment_date || 'Not paid'}</span></div>
            </div>
        `;
        $('#payslipHeader').html(headerHtml);

        const detailsHtml = `
            <li class="py-10 px-20 d-flex align-items-center justify-content-between gap-20 bg-neutral-50 border-bottom">
                <span class="text-primary-light fw-semibold">Name</span>
                <span class="text-primary-light fw-semibold">Amount</span>
            </li>
            <li class="py-10 px-20 d-flex align-items-center justify-content-between gap-20 border-bottom">
                <span class="text-primary-light">Base Salary</span>
                <span class="text-primary-light">${data.gross_salary}</span>
            </li>
            <li class="py-10 px-20 d-flex align-items-center justify-content-between gap-20 border-bottom">
                <span class="text-primary-light">Allowances</span>
                <span class="text-primary-light">${data.total_allowances}</span>
            </li>
            <li class="py-10 px-20 d-flex align-items-center justify-content-between gap-20 border-bottom">
                <span class="text-primary-light">Deductions</span>
                <span class="text-primary-light">${data.total_deductions}</span>
            </li>
            <li class="py-10 px-20 d-flex align-items-center justify-content-between gap-20 bg-neutral-50">
                <span class="text-primary-light fw-semibold text-lg">Net Salary</span>
                <span class="text-primary-light fw-semibold text-lg">${data.net_salary}</span>
            </li>
        `;
        $('#payslipDetails').html(detailsHtml);

        const footerHtml = `<p class="text-primary-light fw-medium mb-0">Payment method: ${data.payment_method || 'N/A'}</p>`;
        $('#payslipFooter').html(footerHtml);
    }

    // Process payroll button
    $('#processPayrollBtn').on('click', function () {
        const periodId = $('#payrollPeriodSelect').val();
        if (!periodId) return;
        $('#processPayrollBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
        
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'process_payroll',
                period_id: periodId,
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    showToast('success', res.message);
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showToast('error', res.message);
                    $('#processPayrollBtn').prop('disabled', false).text('Process');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                showToast('error', 'An error occurred while processing payroll.');
                $('#processPayrollBtn').prop('disabled', false).text('Process');
            }
        });
    });

    // Filter functionality
    $('#applyFilter').on('click', function () {
        const dept = $('#filterDepartment').val();
        const status = $('#filterStatus').val().toLowerCase();
        table.column(3).search(dept).draw();
        if (status) {
            table.column(8).search(status, true, false).draw(); // Status column index changed due to new column
        } else {
            table.column(8).search('').draw();
        }
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

    // Add to Payroll Sidebar logic
    $('#addToPayrollBtn').on('click', function () {
        $('#addPayrollSidebar').addClass('active');
        $('.overlay').addClass('active');
        loadEligibleUsers();
    });

    $('#cancelAddPayroll, .overlay').on('click', function () {
        $('#addPayrollSidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    function loadEligibleUsers() {
        $('#eligibleUsersContainer').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p>Loading eligible users...</p>
            </div>
        `);
        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_eligible_users',
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    displayEligibleUsers(res.data);
                } else {
                    $('#eligibleUsersContainer').html('<div class="alert alert-danger">Failed to load users.</div>');
                    showToast('error', res.message);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                $('#eligibleUsersContainer').html('<div class="alert alert-danger">Error loading users.</div>');
                showToast('error', 'Error loading eligible users.');
            }
        });
    }

    function displayEligibleUsers(users) {
        if (!users || users.length === 0) {
            $('#eligibleUsersContainer').html('<div class="alert alert-info">No eligible users found. All staff may already be in payroll.</div>');
            return;
        }

        let html = '<div class="list-group">';
        users.forEach(u => {
            html += `
                <label class="list-group-item d-flex align-items-center gap-3 border-0 ps-0">
                    <input type="checkbox" class="form-check-input user-checkbox me-2" value="${u.id}">
                    <img src="${u.profile_photo || 'https://academixsuite.com/tenant/assets/images/thumbs/default-avatar.png'}" 
                         alt="${escapeHtml(u.name)}" class="rounded-circle" width="32" height="32">
                    <div>
                        <strong>${escapeHtml(u.name)}</strong><br>
                        <small class="text-secondary-light">${escapeHtml(u.email)} (${escapeHtml(u.user_type)})</small>
                    </div>
                </label>
            `;
        });
        html += '</div>';
        $('#eligibleUsersContainer').html(html);
    }

    $('#saveAddPayroll').on('click', function () {
        const selected = [];
        $('.user-checkbox:checked').each(function () {
            selected.push($(this).val());
        });
        if (selected.length === 0) {
            showToast('error', 'Please select at least one user.');
            return;
        }

        $('#saveAddPayroll').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Adding...');

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'add_to_payroll',
                user_ids: JSON.stringify(selected),
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    showToast('success', res.message);
                    $('#addPayrollSidebar').removeClass('active');
                    $('.overlay').removeClass('active');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('error', res.message);
                    $('#saveAddPayroll').prop('disabled', false).text('Add Selected');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                showToast('error', 'Error adding users.');
                $('#saveAddPayroll').prop('disabled', false).text('Add Selected');
            }
        });
    });

    // Helper to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ========== EDIT EMPLOYEE ==========
    let currentEditEmployeeId = null;

    $('.edit-employee-btn').on('click', function () {
        currentEditEmployeeId = $(this).data('employee-id');
        $('#editEmployeeSidebar').addClass('active');
        $('.overlay').addClass('active');
        $('#editEmployeeForm')[0].reset();

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'get_employee_details',
                employee_id: currentEditEmployeeId,
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    const d = res.data;
                    $('#edit_employee_id').val(d.id);
                    $('#edit_employee_number').val(d.employee_number);
                    $('#edit_department').val(d.department || '');
                    $('#edit_designation').val(d.designation || '');
                    $('#edit_bank_name').val(d.bank_name || '');
                    $('#edit_bank_account').val(d.bank_account || '');
                    $('#edit_ifsc_code').val(d.ifsc_code || '');
                    $('#edit_salary_grade_id').val(d.salary_grade_id || '');
                    $('#edit_basic_salary').val(d.basic_salary || '');
                } else {
                    showToast('error', res.message);
                    $('#editEmployeeSidebar').removeClass('active');
                    $('.overlay').removeClass('active');
                }
            },
            error: function () {
                showToast('error', 'Error loading employee details.');
                $('#editEmployeeSidebar').removeClass('active');
                $('.overlay').removeClass('active');
            }
        });
    });

    $('#editEmployeeForm').on('submit', function (e) {
        e.preventDefault();
        const formData = $(this).serializeArray();
        formData.push({ name: 'action', value: 'update_payroll_details' });
        formData.push({ name: 'csrf_token', value: csrfToken });

        $('#saveEditEmployee').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: formData,
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                if (res.success) {
                    showToast('success', res.message);
                    $('#editEmployeeSidebar').removeClass('active');
                    $('.overlay').removeClass('active');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('error', res.message);
                    $('#saveEditEmployee').prop('disabled', false).text('Save Changes');
                }
            },
            error: function () {
                showToast('error', 'Error saving changes.');
                $('#saveEditEmployee').prop('disabled', false).text('Save Changes');
            }
        });
    });

    $('#cancelEditEmployee, .close-my-sidebar, .overlay').on('click', function () {
        $('#editEmployeeSidebar').removeClass('active');
        $('.overlay').removeClass('active');
    });

    // ========== REMOVE EMPLOYEE ==========
    let removeEmployeeId = null;
    let removeEmployeeName = '';

    $('.remove-employee-btn').on('click', function () {
        removeEmployeeId = $(this).data('employee-id');
        removeEmployeeName = $(this).data('employee-name');
        $('#removeEmployeeName').text(removeEmployeeName);
        $('#permanentDelete').prop('checked', false);
        $('#confirmRemoveModal').modal('show');
    });

    $('#confirmRemoveBtn').on('click', function () {
        const permanent = $('#permanentDelete').is(':checked');
        $('#confirmRemoveBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Removing...');

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: {
                action: 'remove_from_payroll',
                employee_id: removeEmployeeId,
                permanent: permanent,
                csrf_token: csrfToken
            },
            dataType: 'json',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                $('#confirmRemoveModal').modal('hide');
                if (res.success) {
                    showToast('success', res.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('error', res.message);
                }
            },
            error: function () {
                $('#confirmRemoveModal').modal('hide');
                showToast('error', 'Error removing employee.');
            },
            complete: function () {
                $('#confirmRemoveBtn').prop('disabled', false).text('Remove');
            }
        });
    });
</script>
</body>
</html>