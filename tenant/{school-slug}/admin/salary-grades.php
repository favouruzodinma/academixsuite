<?php require_once __DIR__ . '/includes/handlers/hrm-handler.php'; ?>
<?php
// salary-grades.php
require_once 'payroll_common.php';

// Handle AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validatePayrollCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => 'Invalid action.'];

    switch ($action) {
        case 'add_salary_grade':
            $response = $payrollManager->addSalaryGrade($_POST);
            break;
        case 'update_salary_grade':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->updateSalaryGrade($id, $_POST);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
        case 'delete_salary_grade':
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $response = $payrollManager->deleteSalaryGrade($id);
            } else {
                $response = ['success' => false, 'message' => 'Missing ID'];
            }
            break;
    }

    echo json_encode($response);
    exit;
}

$items = $payrollManager->getSalaryGrades();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($school['name']); ?> | Salary Grades</title>
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
        <div class="d-flex justify-content-between align-items-center mb-24">
            <h1 class="fw-semibold h6 text-primary-light">Salary Grades</h1>
            <button class="btn btn-primary-600" data-bs-toggle="modal" data-bs-target="#gradeModal" onclick="openGradeModal()">+ Add Grade</button>
        </div>

        <div class="card">
            <div class="card-body">
                <table class="table bordered-table" id="gradesTable">
                    <thead>
                        <tr>
                            <th>Grade Name</th>
                            <th>Basic Salary</th>
                            <th>House Allow.</th>
                            <th>Transport Allow.</th>
                            <th>Medical Allow.</th>
                            <th>Other Allow.</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $g): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($g['grade_name']); ?></td>
                            <td><?php echo $currencySymbol . number_format($g['basic_salary'], 2); ?></td>
                            <td><?php echo $currencySymbol . number_format($g['house_allowance'], 2); ?></td>
                            <td><?php echo $currencySymbol . number_format($g['transport_allowance'], 2); ?></td>
                            <td><?php echo $currencySymbol . number_format($g['medical_allowance'], 2); ?></td>
                            <td><?php echo $currencySymbol . number_format($g['other_allowances'], 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $g['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $g['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-warning" onclick='editGrade(<?php echo json_encode($g); ?>)'>Edit</button>
                                <button class="btn btn-sm btn-danger" onclick="deleteGrade(<?php echo $g['id']; ?>)">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="gradeForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="gradeModalTitle">Add Salary Grade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="gradeId">
                    <div class="mb-3">
                        <label class="form-label">Grade Name *</label>
                        <input type="text" class="form-control" name="grade_name" id="grade_name" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary *</label>
                            <input type="number" step="0.01" class="form-control" name="basic_salary" id="basic_salary" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">House Allowance</label>
                            <input type="number" step="0.01" class="form-control" name="house_allowance" id="house_allowance" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Transport Allowance</label>
                            <input type="number" step="0.01" class="form-control" name="transport_allowance" id="transport_allowance" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Medical Allowance</label>
                            <input type="number" step="0.01" class="form-control" name="medical_allowance" id="medical_allowance" value="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Other Allowances</label>
                            <input type="number" step="0.01" class="form-control" name="other_allowances" id="other_allowances" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveGradeBtn">Save</button>
                </div>
            </form>
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
    let table = new DataTable('#gradesTable');
    const csrfToken = '<?php echo $csrfToken; ?>';

    // Toast function (same as payroll.php)
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

    let gradeModal = new bootstrap.Modal(document.getElementById('gradeModal'));

    function openGradeModal() {
        document.getElementById('gradeForm').reset();
        document.getElementById('gradeId').value = '';
        document.getElementById('gradeModalTitle').innerText = 'Add Salary Grade';
    }

    function editGrade(data) {
        document.getElementById('gradeId').value = data.id;
        document.getElementById('grade_name').value = data.grade_name;
        document.getElementById('basic_salary').value = data.basic_salary;
        document.getElementById('house_allowance').value = data.house_allowance;
        document.getElementById('transport_allowance').value = data.transport_allowance;
        document.getElementById('medical_allowance').value = data.medical_allowance;
        document.getElementById('other_allowances').value = data.other_allowances;
        document.getElementById('description').value = data.description || '';
        document.getElementById('is_active').checked = data.is_active == 1;
        document.getElementById('gradeModalTitle').innerText = 'Edit Salary Grade';
        gradeModal.show();
    }

    document.getElementById('gradeForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('csrf_token', csrfToken);
        const id = formData.get('id');
        const action = id ? 'update_salary_grade' : 'add_salary_grade';
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
                gradeModal.hide();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('error', res.message);
            }
        })
        .catch(() => showToast('error', 'Request failed.'));
    });

    function deleteGrade(id) {
        if (!confirm('Are you sure? This cannot be undone if grade is not used.')) return;
        const formData = new FormData();
        formData.append('action', 'delete_salary_grade');
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