<?php
require_once __DIR__ . '/includes/handlers/fees-handler.php';
require_once __DIR__ . '/includes/campus-context.php';

$csrf = academix_admin_csrf_token();
$toasts = academix_admin_take_toasts();
$campuses = academix_admin_get_campuses($schoolDb, $school, false);
$activeCampuses = array_values(array_filter($campuses, static fn($campus) => (int)($campus['is_active'] ?? 1) === 1));
$selectedCampusId = academix_admin_resolve_campus_id($schoolDb, $school, false);
$returnTo = 'fees-group.php?campus_id=' . $selectedCampusId;
$feeGroups = [];

if ($schoolDb && $selectedCampusId > 0) {
    try {
        $stmt = $schoolDb->prepare("
            SELECT *
            FROM fee_groups
            WHERE school_id = ? AND campus_id = ?
            ORDER BY status = 'Active' DESC, created_at DESC, name ASC
        ");
        $stmt->execute([(int)$school['id'], $selectedCampusId]);
        $feeGroups = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee group page load failed: ' . $e->getMessage());
        $toasts['error'] = 'Could not load fee groups for this campus.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Fee Groups</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/dataTables.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        .finance-page-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 16px 45px rgba(15, 23, 42, .07);
        }
        .campus-select-card {
            background: linear-gradient(135deg, #f8fafc, #eef2ff);
            border: 1px solid #dbe4ea;
            border-radius: 18px;
            padding: 18px;
        }
        .fee-group-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>
<?php include_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
        <?php foreach ($toasts as $toastType => $toastMessage): ?>
            <?php if ($toastMessage !== ''): ?>
                <div class="alert alert-<?php echo $toastType === 'error' ? 'danger' : academix_admin_e($toastType); ?> alert-dismissible fade show" role="alert">
                    <?php echo academix_admin_e($toastMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-bold mb-4 h4 text-primary-light">Fee Groups</h1>
                <p class="text-secondary-light mb-0">Organize school charges by campus so billing reports stay clean.</p>
            </div>
            <form method="get" class="campus-select-card d-flex align-items-center gap-2">
                <label class="text-sm fw-semibold text-secondary-light mb-0">Campus</label>
                <select name="campus_id" class="form-select radius-10" onchange="this.form.submit()">
                    <?php foreach ($activeCampuses as $campus): ?>
                        <option value="<?php echo (int)$campus['id']; ?>" <?php echo $selectedCampusId === (int)$campus['id'] ? 'selected' : ''; ?>>
                            <?php echo academix_admin_e($campus['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="card finance-page-card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="mb-0 text-primary-light"><?php echo academix_admin_e(academix_admin_campus_name($campuses, $selectedCampusId)); ?></h5>
                    <span class="text-sm text-secondary-light"><?php echo count($feeGroups); ?> fee group<?php echo count($feeGroups) === 1 ? '' : 's'; ?> configured</span>
                </div>
                <span class="fee-group-pill"><i class="ri-folder-chart-line"></i> Campus billing groups</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table bordered-table mb-0" id="dataTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($feeGroups)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-40 text-secondary-light">
                                        No fee groups found for this campus.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($feeGroups as $feeGroup): ?>
                                <tr>
                                    <td><span class="fw-semibold text-primary-light"><?php echo academix_admin_e($feeGroup['name']); ?></span></td>
                                    <td><?php echo academix_admin_e($feeGroup['description'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($feeGroup['status'] ?? 'Active') === 'Active' ? 'bg-success-100 text-success-600' : 'bg-danger-100 text-danger-600'; ?>">
                                            <?php echo academix_admin_e($feeGroup['status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo academix_admin_e(!empty($feeGroup['created_at']) ? date('M j, Y', strtotime((string)$feeGroup['created_at'])) : 'recently'); ?></td>
                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary-600 edit-fee-group"
                                            data-bs-toggle="modal"
                                            data-bs-target="#feeGroupModal"
                                            data-id="<?php echo (int)$feeGroup['id']; ?>"
                                            data-name="<?php echo academix_admin_e($feeGroup['name']); ?>"
                                            data-description="<?php echo academix_admin_e($feeGroup['description'] ?? ''); ?>"
                                            data-status="<?php echo academix_admin_e($feeGroup['status'] ?? 'Active'); ?>">
                                            Edit
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this fee group?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                            <input type="hidden" name="action" value="delete_fees_group">
                                            <input type="hidden" name="id" value="<?php echo (int)$feeGroup['id']; ?>">
                                            <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
                                            <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger-600">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="feeGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                <input type="hidden" name="action" id="fee-group-action" value="create_fees_group">
                <input type="hidden" name="id" id="fee-group-id" value="">
                <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
                <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title text-primary-light" id="fee-group-title">Add Fee Group</h5>
                        <p class="text-secondary-light text-sm mb-0">This group will belong to <?php echo academix_admin_e(academix_admin_campus_name($campuses, $selectedCampusId)); ?>.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-16">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" name="name" id="fee-group-name" class="form-control" required placeholder="e.g. Termly fees, Boarding fees">
                    </div>
                    <div class="mb-16">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="fee-group-description" class="form-control" rows="3" placeholder="Optional internal note"></textarea>
                    </div>
                    <div>
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" id="fee-group-status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-neutral-200" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Save fee group</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/dataTables.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
<script>
$(function () {
    const hasEmptyState = $('#dataTable tbody td[colspan]').length > 0;
    const table = hasEmptyState ? null : $('#dataTable').DataTable({ pageLength: 10, dom: 'lrtip' });
    $('#tableSearch').on('keyup', function () {
        if (table) {
            table.search(this.value).draw();
        }
    });

    $('#feeGroupModal').on('hidden.bs.modal', function () {
        $('#fee-group-title').text('Add Fee Group');
        $('#fee-group-action').val('create_fees_group');
        $('#fee-group-id').val('');
        $('#fee-group-name').val('');
        $('#fee-group-description').val('');
        $('#fee-group-status').val('Active');
    });

    $('.edit-fee-group').on('click', function () {
        $('#fee-group-title').text('Edit Fee Group');
        $('#fee-group-action').val('update_fees_group');
        $('#fee-group-id').val($(this).data('id'));
        $('#fee-group-name').val($(this).data('name'));
        $('#fee-group-description').val($(this).data('description'));
        $('#fee-group-status').val($(this).data('status') || 'Active');
    });
});
</script>
</body>
</html>
