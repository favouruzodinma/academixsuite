<?php
// employee-deductions.php
require_once 'payroll_common.php';

$employeeId = (int)($_GET['employee_id'] ?? 0);
if (!$employeeId) {
    die("Employee ID required.");
}
$employee = $payrollManager->getEmployeeDetails($employeeId);
if (!$employee) {
    die("Employee not found.");
}

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
        case 'add_deduction':
            $_POST['employee_id'] = $employeeId;
            $response = $payrollManager->addDeduction($_POST);
            break;
        case 'update_deduction':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->updateDeduction($id, $_POST);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
        case 'delete_deduction':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->deleteDeduction($id);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

$deductions = $payrollManager->getEmployeeDeductions($employeeId);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | Deductions for <?php echo htmlspecialchars($employee['name']); ?></title>
    <link rel="icon" type="image/png" href="https://academixsuite.com/tenant/assets/images/favicon.png">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
    <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>
<body>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;" id="toastContainer"></div>

<!-- Theme Customization -->
<div class="body-overlay"></div>
<button type="button" class="theme-customization__button w-48-px h-48-px bg-primary-600 text-white rounded-circle d-flex justify-content-center align-items-center position-fixed end-0 bottom-0 mb-40 me-40 text-2xxl bg-hover-primary-700" aria-label="Theme Customization Button">
    <i class="ri-settings-3-line animate-spin"></i>
</button>

<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

<!-- Sidebar -->
<?php include_once('includes/sidebar.php'); ?>

<main class="dashboard-main">
    <div class="navbar-header shadow-1">
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
            <h1 class="fw-semibold h6 text-primary-light">Deductions for <?php echo htmlspecialchars($employee['name']); ?></h1>
            <button class="btn btn-primary-600" data-bs-toggle="modal" data-bs-target="#deductionModal" onclick="openDeductionModal()">+ Add Deduction</button>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="table bordered-table" id="deductionsTable">
                    <thead>
                        <tr>
                            <th>Deduction Type</th>
                            <th>Amount</th>
                            <th>Effective From</th>
                            <th>Effective To</th>
                            <th>Recurring</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deductions as $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($d['deduction_type']); ?></td>
                            <td><?php echo $currencySymbol . number_format($d['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($d['effective_from']); ?></td>
                            <td><?php echo htmlspecialchars($d['effective_to'] ?? 'Indefinite'); ?></td>
                            <td><?php echo $d['is_recurring'] ? 'Yes' : 'No'; ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning" onclick='editDeduction(<?php echo json_encode($d); ?>)'>Edit</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteDeduction(<?php echo $d['id']; ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Deduction Modal -->
<div class="modal fade" id="deductionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="deductionForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="deductionModalTitle">Add Deduction</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="deductionId">
                    <div class="mb-3">
                        <label class="form-label">Deduction Type *</label>
                        <input type="text" class="form-control" name="deduction_type" id="deduction_type" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount *</label>
                        <input type="number" step="0.01" class="form-control" name="amount" id="amount" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Effective From *</label>
                            <input type="date" class="form-control" name="effective_from" id="effective_from" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Effective To</label>
                            <input type="date" class="form-control" name="effective_to" id="effective_to">
                        </div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_recurring" id="is_recurring" checked>
                        <label class="form-check-label">Recurring (applies every period)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveDeductionBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
<script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

<script>
    let table = new DataTable('#deductionsTable');
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

    let deductionModal = new bootstrap.Modal(document.getElementById('deductionModal'));

    function openDeductionModal() {
        document.getElementById('deductionForm').reset();
        document.getElementById('deductionId').value = '';
        document.getElementById('deductionModalTitle').innerText = 'Add Deduction';
    }

    function editDeduction(data) {
        document.getElementById('deductionId').value = data.id;
        document.getElementById('deduction_type').value = data.deduction_type;
        document.getElementById('amount').value = data.amount;
        document.getElementById('effective_from').value = data.effective_from;
        document.getElementById('effective_to').value = data.effective_to || '';
        document.getElementById('is_recurring').checked = data.is_recurring == 1;
        document.getElementById('deductionModalTitle').innerText = 'Edit Deduction';
        deductionModal.show();
    }

    document.getElementById('deductionForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('csrf_token', csrfToken);
        const id = formData.get('id');
        const action = id ? 'update_deduction' : 'add_deduction';
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
                deductionModal.hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', res.message);
            }
        })
        .catch(() => showToast('error', 'Request failed.'));
    });

    function deleteDeduction(id) {
        if (!confirm('Are you sure?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_deduction');
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