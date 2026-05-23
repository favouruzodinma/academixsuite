<?php
require_once __DIR__ . '/includes/handlers/fees-handler.php';
require_once __DIR__ . '/includes/campus-context.php';

$csrf = academix_admin_csrf_token();
$toasts = academix_admin_take_toasts();
$campuses = academix_admin_get_campuses($schoolDb, $school, false);
$activeCampuses = array_values(array_filter($campuses, static fn($campus) => (int)($campus['is_active'] ?? 1) === 1));
$selectedCampusId = academix_admin_resolve_campus_id($schoolDb, $school, false);
$returnTo = 'fees-type.php?campus_id=' . $selectedCampusId;
$feeTypes = [];

if ($schoolDb && $selectedCampusId > 0) {
    try {
        $stmt = $schoolDb->prepare("
            SELECT ft.*, COUNT(fs.id) AS class_fee_count
            FROM fee_types ft
            LEFT JOIN fee_structures fs
                ON fs.fee_category_id = ft.id
                AND fs.school_id = ft.school_id
                AND fs.campus_id = ft.campus_id
            WHERE ft.school_id = ? AND ft.campus_id = ?
            GROUP BY ft.id
            ORDER BY ft.status = 'Active' DESC, ft.created_at DESC, ft.name ASC
        ");
        $stmt->execute([(int)$school['id'], $selectedCampusId]);
        $feeTypes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee type page load failed: ' . $e->getMessage());
        $toasts['error'] = 'Could not load fee types for this campus.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Fee Types</title>
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
        .fee-type-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 7px 12px;
            background: #eefdf8;
            color: #0f766e;
            font-weight: 700;
            font-size: 12px;
        }
        .campus-select-card {
            background: linear-gradient(135deg, #f8fafc, #eefdf8);
            border: 1px solid #dbe4ea;
            border-radius: 18px;
            padding: 18px;
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
                <h1 class="fw-bold mb-4 h4 text-primary-light">Fee Types</h1>
                <p class="text-secondary-light mb-0">Create campus-specific fee names before assigning amounts to classes.</p>
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
                    <span class="text-sm text-secondary-light"><?php echo count($feeTypes); ?> fee type<?php echo count($feeTypes) === 1 ? '' : 's'; ?> configured</span>
                </div>
                <span class="fee-type-pill"><i class="ri-price-tag-3-line"></i> Campus fee catalogue</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table bordered-table mb-0" id="dataTable">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Class fee usage</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($feeTypes)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-40 text-secondary-light">
                                        No fee types found for this campus.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($feeTypes as $feeType): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-primary-light"><?php echo academix_admin_e($feeType['name']); ?></span>
                                        <span class="d-block text-xs text-secondary-light">Created <?php echo academix_admin_e(!empty($feeType['created_at']) ? date('M j, Y', strtotime((string)$feeType['created_at'])) : 'recently'); ?></span>
                                    </td>
                                    <td><?php echo academix_admin_e($feeType['description'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge <?php echo ($feeType['status'] ?? 'Active') === 'Active' ? 'bg-success-100 text-success-600' : 'bg-danger-100 text-danger-600'; ?>">
                                            <?php echo academix_admin_e($feeType['status'] ?? 'Active'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int)($feeType['class_fee_count'] ?? 0); ?> class setup<?php echo (int)($feeType['class_fee_count'] ?? 0) === 1 ? '' : 's'; ?></td>
                                    <td class="text-end">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary-600 edit-fee-type"
                                            data-bs-toggle="modal"
                                            data-bs-target="#feeTypeModal"
                                            data-id="<?php echo (int)$feeType['id']; ?>"
                                            data-name="<?php echo academix_admin_e($feeType['name']); ?>"
                                            data-description="<?php echo academix_admin_e($feeType['description'] ?? ''); ?>"
                                            data-status="<?php echo academix_admin_e($feeType['status'] ?? 'Active'); ?>">
                                            Edit
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this fee type?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                            <input type="hidden" name="action" value="delete_fees_type">
                                            <input type="hidden" name="id" value="<?php echo (int)$feeType['id']; ?>">
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

<div class="modal fade" id="feeTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content radius-16">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                <input type="hidden" name="action" id="fee-type-action" value="create_fees_type">
                <input type="hidden" name="id" id="fee-type-id" value="">
                <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
                <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title text-primary-light" id="fee-type-title">Add Fee Type</h5>
                        <p class="text-secondary-light text-sm mb-0">This fee type will belong to <?php echo academix_admin_e(academix_admin_campus_name($campuses, $selectedCampusId)); ?>.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-16">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" name="name" id="fee-type-name" class="form-control" required placeholder="e.g. Tuition, PTA levy, Uniform">
                    </div>
                    <div class="mb-16">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="fee-type-description" class="form-control" rows="3" placeholder="Optional internal note"></textarea>
                    </div>
                    <div>
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" id="fee-type-status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-neutral-200" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Save fee type</button>
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

    $('#feeTypeModal').on('hidden.bs.modal', function () {
        $('#fee-type-title').text('Add Fee Type');
        $('#fee-type-action').val('create_fees_type');
        $('#fee-type-id').val('');
        $('#fee-type-name').val('');
        $('#fee-type-description').val('');
        $('#fee-type-status').val('Active');
    });

    $('.edit-fee-type').on('click', function () {
        $('#fee-type-title').text('Edit Fee Type');
        $('#fee-type-action').val('update_fees_type');
        $('#fee-type-id').val($(this).data('id'));
        $('#fee-type-name').val($(this).data('name'));
        $('#fee-type-description').val($(this).data('description'));
        $('#fee-type-status').val($(this).data('status'));
    });
});
</script>
</body>
</html>
