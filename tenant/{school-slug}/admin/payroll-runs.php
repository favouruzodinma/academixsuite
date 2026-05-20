<?php require_once __DIR__ . '/includes/handlers/hrm-handler.php'; ?>
<?php
// payroll-runs.php
require_once 'payroll_common.php';

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validatePayrollCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'get_run_details':
            $runId = (int)($_POST['run_id'] ?? 0);
            if ($runId) {
                $details = $payrollManager->getPayrollRunDetails($runId);
                if ($details) {
                    $response = ['success' => true, 'data' => $details];
                } else {
                    $response = ['success' => false, 'message' => 'Run not found'];
                }
            } else {
                $response = ['success' => false, 'message' => 'Missing run ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

$runs = $payrollManager->getPayrollRuns();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($school['name']); ?> | Payroll Runs</title>
   <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>
<body>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;" id="toastContainer"></div>

<!-- Theme Customization (optional) -->
<div class="body-overlay"></div>
<button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
    <i class="ri-settings-3-line animate-spin"></i>
</button>

<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
        <!-- Navbar (same as payroll.php) -->
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
        <h1 class="fw-semibold h6 text-primary-light mb-24">Payroll Runs</h1>

        <div class="card">
            <div class="card-body">
                <table class="table bordered-table" id="runsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Period</th>
                            <th>Processed Date</th>
                            <th>Employees</th>
                            <th>Total Net</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $r): ?>
                        <tr>
                            <td><?php echo $r['id']; ?></td>
                            <td><?php echo htmlspecialchars($r['period_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                            <td><?php echo $r['employee_count']; ?></td>
                            <td><?php echo $currencySymbol . number_format($r['total_net'] ?? 0, 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $r['status'] == 'approved' ? 'success' : ($r['status'] == 'draft' ? 'warning' : 'secondary'); ?>">
                                    <?php echo ucfirst($r['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-info" onclick="viewRunDetails(<?php echo $r['id']; ?>)">View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Run Details Modal -->
<div class="modal fade" id="runDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Payroll Run Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="runDetailsContent">
                Loading...
            </div>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
    let table = new DataTable('#runsTable');
    const csrfToken = '<?php echo $csrfToken; ?>';
    // include showToast function

    let runDetailsModal = new bootstrap.Modal(document.getElementById('runDetailsModal'));

    function viewRunDetails(runId) {
        document.getElementById('runDetailsContent').innerHTML = '<div class="text-center"><div class="spinner-border"></div> Loading...</div>';
        runDetailsModal.show();

        const formData = new FormData();
        formData.append('action', 'get_run_details');
        formData.append('run_id', runId);
        formData.append('csrf_token', csrfToken);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                displayRunDetails(res.data);
            } else {
                document.getElementById('runDetailsContent').innerHTML = '<div class="alert alert-danger">' + res.message + '</div>';
            }
        })
        .catch(() => {
            document.getElementById('runDetailsContent').innerHTML = '<div class="alert alert-danger">Error loading details.</div>';
        });
    }

    function displayRunDetails(data) {
        let html = `
            <p><strong>Period:</strong> ${data.period_name} (${data.start_date} - ${data.end_date})</p>
            <p><strong>Processed By:</strong> ${data.processed_by_name || 'System'}</p>
            <p><strong>Processed At:</strong> ${data.created_at}</p>
            <p><strong>Status:</strong> ${data.status}</p>
            <hr>
            <h6>Employee Slips</h6>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Gross</th>
                        <th>Allowances</th>
                        <th>Deductions</th>
                        <th>Net</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
        `;
        data.slips.forEach(s => {
            html += `
                <tr>
                    <td>${escapeHtml(s.name)} (${s.employee_number})</td>
                    <td>${s.gross_salary}</td>
                    <td>${s.total_allowances}</td>
                    <td>${s.total_deductions}</td>
                    <td>${s.net_salary}</td>
                    <td>${s.payment_status}</td>
                </tr>
            `;
        });
        html += '</tbody></table>';
        document.getElementById('runDetailsContent').innerHTML = html;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>
</body>
</html>