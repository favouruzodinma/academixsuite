<?php require_once __DIR__ . '/includes/handlers/hrm-handler.php'; ?>
<?php
// payroll-periods.php
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
        case 'add_period':
            $response = $payrollManager->addPayrollPeriod($_POST);
            break;
        case 'update_period':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->updatePayrollPeriod($id, $_POST);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
        case 'delete_period':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->deletePayrollPeriod($id);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

$items = $payrollManager->getPayrollPeriods();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($school['name']); ?> | Payroll Periods</title>
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
        <div class="d-flex justify-content-between align-items-center mb-24">
            <h1 class="fw-semibold h6 text-primary-light">Payroll Periods</h1>
            <button class="btn btn-primary-600" data-bs-toggle="modal" data-bs-target="#periodModal" onclick="openPeriodModal()">+ Add Period</button>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="table bordered-table" id="periodsTable">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td><?php echo htmlspecialchars($p['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($p['end_date']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $p['status'] == 'open' ? 'success' : ($p['status'] == 'processing' ? 'warning' : 'secondary'); ?>">
                                    <?php echo ucfirst($p['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning" onclick='editPeriod(<?php echo json_encode($p); ?>)'>Edit</button>
                                <button class="btn btn-sm btn-danger" onclick="deletePeriod(<?php echo $p['id']; ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Period Modal -->
<div class="modal fade" id="periodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="periodForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="periodModalTitle">Add Payroll Period</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="periodId">
                    <div class="mb-3">
                        <label class="form-label">Period Name *</label>
                        <input type="text" class="form-control" name="name" id="period_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" class="form-control" name="start_date" id="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" class="form-control" name="end_date" id="end_date" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="status">
                            <option value="open">Open</option>
                            <option value="processing">Processing</option>
                            <option value="closed">Closed</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="savePeriodBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
    let table = new DataTable('#periodsTable');
    const csrfToken = '<?php echo $csrfToken; ?>';
    // Copy showToast function from salary-grades.php

    let periodModal = new bootstrap.Modal(document.getElementById('periodModal'));

    function openPeriodModal() {
        document.getElementById('periodForm').reset();
        document.getElementById('periodId').value = '';
        document.getElementById('periodModalTitle').innerText = 'Add Payroll Period';
    }

    function editPeriod(data) {
        document.getElementById('periodId').value = data.id;
        document.getElementById('period_name').value = data.name;
        document.getElementById('start_date').value = data.start_date;
        document.getElementById('end_date').value = data.end_date;
        document.getElementById('status').value = data.status;
        document.getElementById('periodModalTitle').innerText = 'Edit Payroll Period';
        periodModal.show();
    }

    document.getElementById('periodForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('csrf_token', csrfToken);
        const id = formData.get('id');
        const action = id ? 'update_period' : 'add_period';
        formData.append('action', action);

        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                showToast('success', res.message);
                periodModal.hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', res.message);
            }
        })
        .catch(() => showToast('error', 'Request failed.'));
    });

    function deletePeriod(id) {
        if (!confirm('Are you sure? This cannot be undone if payroll runs exist for this period.')) return;
        const formData = new FormData();
        formData.append('action', 'delete_period');
        formData.append('id', id);
        formData.append('csrf_token', csrfToken);
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                showToast('success', res.message);
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', res.message);
            }
        });
    }
</script>
</body>
</html>