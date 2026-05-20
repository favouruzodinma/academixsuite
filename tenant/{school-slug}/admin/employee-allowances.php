<?php
// employee-allowances.php
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
        case 'add_allowance':
            $_POST['employee_id'] = $employeeId;
            $response = $payrollManager->addAllowance($_POST);
            break;
        case 'update_allowance':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->updateAllowance($id, $_POST);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
        case 'delete_allowance':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->deleteAllowance($id);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

$allowances = $payrollManager->getEmployeeAllowances($employeeId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($school['name']); ?> | Allowances for <?php echo htmlspecialchars($employee['name']); ?></title>
    <!-- same head -->
</head>
<body>
<!-- Toast Container, Sidebar, Navbar... (same structure) -->

<div class="dashboard-main-body">
    <div class="d-flex justify-content-between align-items-center mb-24">
        <h1 class="fw-semibold h6 text-primary-light">Allowances for <?php echo htmlspecialchars($employee['name']); ?></h1>
        <button class="btn btn-primary-600" data-bs-toggle="modal" data-bs-target="#allowanceModal" onclick="openAllowanceModal()">+ Add Allowance</button>
    </div>

    <div class="card">
        <div class="card-body">
            <table class="table bordered-table" id="allowancesTable">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Effective From</th>
                        <th>Effective To</th>
                        <th>Recurring</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allowances as $a): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($a['allowance_type']); ?></td>
                        <td><?php echo $currencySymbol . number_format($a['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($a['effective_from']); ?></td>
                        <td><?php echo htmlspecialchars($a['effective_to'] ?? 'Indefinite'); ?></td>
                        <td><?php echo $a['is_recurring'] ? 'Yes' : 'No'; ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick='editAllowance(<?php echo json_encode($a); ?>)'>Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteAllowance(<?php echo $a['id']; ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Allowance Modal -->
<div class="modal fade" id="allowanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="allowanceForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="allowanceModalTitle">Add Allowance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="allowanceId">
                    <div class="mb-3">
                        <label class="form-label">Allowance Type *</label>
                        <input type="text" class="form-control" name="allowance_type" id="allowance_type" required>
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
                    <button type="submit" class="btn btn-primary" id="saveAllowanceBtn">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let table = new DataTable('#allowancesTable');
    const csrfToken = '<?php echo $csrfToken; ?>';
    // showToast function

    let allowanceModal = new bootstrap.Modal(document.getElementById('allowanceModal'));

    function openAllowanceModal() {
        document.getElementById('allowanceForm').reset();
        document.getElementById('allowanceId').value = '';
        document.getElementById('allowanceModalTitle').innerText = 'Add Allowance';
    }

    function editAllowance(data) {
        document.getElementById('allowanceId').value = data.id;
        document.getElementById('allowance_type').value = data.allowance_type;
        document.getElementById('amount').value = data.amount;
        document.getElementById('effective_from').value = data.effective_from;
        document.getElementById('effective_to').value = data.effective_to || '';
        document.getElementById('is_recurring').checked = data.is_recurring == 1;
        document.getElementById('allowanceModalTitle').innerText = 'Edit Allowance';
        allowanceModal.show();
    }

    document.getElementById('allowanceForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('csrf_token', csrfToken);
        const id = formData.get('id');
        const action = id ? 'update_allowance' : 'add_allowance';
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
                allowanceModal.hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', res.message);
            }
        })
        .catch(() => showToast('error', 'Request failed.'));
    });

    function deleteAllowance(id) {
        if (!confirm('Are you sure?')) return;
        const formData = new FormData();
        formData.append('action', 'delete_allowance');
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