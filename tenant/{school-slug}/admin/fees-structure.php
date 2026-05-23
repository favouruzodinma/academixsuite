<?php
require_once __DIR__ . '/includes/handlers/fees-handler.php';
require_once __DIR__ . '/includes/campus-context.php';

$csrf = academix_admin_csrf_token();
$currency = $school['currency_symbol'] ?? '₦';
$toasts = academix_admin_take_toasts();
$campuses = academix_admin_get_campuses($schoolDb, $school, false);
$activeCampuses = array_values(array_filter($campuses, static fn($campus) => (int)($campus['is_active'] ?? 1) === 1));
$selectedCampusId = academix_admin_resolve_campus_id($schoolDb, $school, false);
$selectedYearId = (int)($_GET['year_id'] ?? 0);
$selectedTermId = (int)($_GET['term_id'] ?? 0);
$selectedClassId = (int)($_GET['class_id'] ?? 0);

$years = [];
$terms = [];
$classes = [];
$feeTypes = [];
$existing = [];
$classTotal = 0.0;

if ($schoolDb) {
    try {
        $stmt = $schoolDb->prepare("
            SELECT id, name, status, is_default
            FROM academic_years
            WHERE school_id = ? AND campus_id = ?
            ORDER BY (status = 'active') DESC, is_default DESC, start_date DESC, id DESC
        ");
        $stmt->execute([(int)$school['id'], $selectedCampusId]);
        $years = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($selectedYearId <= 0 && !empty($years)) {
            $selectedYearId = (int)$years[0]['id'];
        }
    } catch (Throwable $e) {
        error_log('Fee structure year load failed: ' . $e->getMessage());
    }

    if ($selectedYearId > 0) {
        try {
            $stmt = $schoolDb->prepare("
                SELECT id, name, is_default
                FROM academic_terms
                WHERE school_id = ? AND campus_id = ? AND academic_year_id = ?
                ORDER BY is_default DESC, start_date ASC, id ASC
            ");
            $stmt->execute([(int)$school['id'], $selectedCampusId, $selectedYearId]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($selectedTermId <= 0 && !empty($terms)) {
                $selectedTermId = (int)$terms[0]['id'];
            }
        } catch (Throwable $e) {
            error_log('Fee structure term load failed: ' . $e->getMessage());
        }
    }

    try {
        $stmt = $schoolDb->prepare("
            SELECT id, name, code
            FROM classes
            WHERE school_id = ? AND campus_id = ? AND is_active = 1
            ORDER BY name ASC
        ");
        $stmt->execute([(int)$school['id'], $selectedCampusId]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($selectedClassId <= 0 && !empty($classes)) {
            $selectedClassId = (int)$classes[0]['id'];
        }
    } catch (Throwable $e) {
        error_log('Fee structure class load failed: ' . $e->getMessage());
    }

    try {
        $stmt = $schoolDb->prepare("
            SELECT id, name, description
            FROM fee_types
            WHERE school_id = ? AND campus_id = ? AND status = 'Active'
            ORDER BY name ASC
        ");
        $stmt->execute([(int)$school['id'], $selectedCampusId]);
        $feeTypes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee structure fee type load failed: ' . $e->getMessage());
    }

    if ($selectedCampusId > 0 && $selectedYearId > 0 && $selectedTermId > 0 && $selectedClassId > 0) {
        try {
            $stmt = $schoolDb->prepare("
                SELECT *
                FROM fee_structures
                WHERE school_id = ? AND campus_id = ? AND academic_year_id = ? AND academic_term_id = ? AND class_id = ?
            ");
            $stmt->execute([(int)$school['id'], $selectedCampusId, $selectedYearId, $selectedTermId, $selectedClassId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $existing[(int)$row['fee_category_id']] = $row;
                $classTotal += (float)$row['amount'];
            }
        } catch (Throwable $e) {
            error_log('Fee structure existing load failed: ' . $e->getMessage());
        }
    }
}

$returnTo = 'fees-structure.php?' . http_build_query([
    'campus_id' => $selectedCampusId,
    'year_id' => $selectedYearId,
    'term_id' => $selectedTermId,
    'class_id' => $selectedClassId,
]);
$canCreateClassFee = !empty($years) && !empty($terms) && !empty($classes);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Class Fees</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        .fee-shell-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, .06);
        }
        .fee-stat {
            border-radius: 18px;
            padding: 20px;
            min-height: 128px;
            border: 1px solid #e5e7eb;
            background: #fff;
        }
        .fee-row {
            border: 1px solid #eef2f7;
            border-radius: 16px;
            padding: 16px;
            background: #fff;
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
                <h1 class="fw-bold mb-4 h4 text-primary-light">Class Fee Setup</h1>
                <p class="text-secondary-light mb-0">Create the exact fees each class pays per campus, academic year, and term.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-primary-600 radius-10" data-bs-toggle="modal" data-bs-target="#quickClassFeeModal" <?php echo $canCreateClassFee ? '' : 'disabled'; ?>>
                    <i class="ri-add-line"></i> Add class fee
                </button>
                <a href="fees-type.php?campus_id=<?php echo (int)$selectedCampusId; ?>" class="btn btn-outline-primary-600 radius-10">
                    <i class="ri-price-tag-3-line"></i> Fee types
                </a>
                <a href="transaction.php?campus_id=<?php echo (int)$selectedCampusId; ?>" class="btn btn-outline-success-600 radius-10">
                    <i class="ri-wallet-3-line"></i> Transaction ledger
                </a>
            </div>
        </div>

        <div class="row gy-4 mb-24">
            <div class="col-xxl-3 col-sm-6">
                <div class="fee-stat">
                    <span class="text-secondary-light text-sm">Selected campus</span>
                    <h5 class="mt-10 mb-0"><?php echo academix_admin_e(academix_admin_campus_name($campuses, $selectedCampusId)); ?></h5>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="fee-stat">
                    <span class="text-secondary-light text-sm">Active fee types</span>
                    <h5 class="mt-10 mb-0"><?php echo count($feeTypes); ?></h5>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="fee-stat">
                    <span class="text-secondary-light text-sm">Saved class items</span>
                    <h5 class="mt-10 mb-0"><?php echo count($existing); ?></h5>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="fee-stat">
                    <span class="text-secondary-light text-sm">Class total</span>
                    <h5 class="mt-10 mb-0"><?php echo academix_admin_e($currency . ' ' . number_format($classTotal, 2)); ?></h5>
                </div>
            </div>
        </div>

        <div class="card fee-shell-card mb-24">
            <div class="card-body">
                <form method="get" class="row gy-3 align-items-end">
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label fw-semibold">Campus</label>
                        <select name="campus_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($activeCampuses as $campus): ?>
                                <option value="<?php echo (int)$campus['id']; ?>" <?php echo $selectedCampusId === (int)$campus['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($campus['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label fw-semibold">Academic Year</label>
                        <select name="year_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo (int)$year['id']; ?>" <?php echo $selectedYearId === (int)$year['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($year['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label fw-semibold">Term</label>
                        <select name="term_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo (int)$term['id']; ?>" <?php echo $selectedTermId === (int)$term['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($term['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label fw-semibold">Class</label>
                        <select name="class_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo $selectedClassId === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button class="btn btn-primary-600 radius-10" type="submit"><i class="ri-filter-3-line"></i> Load fees</button>
                        <a href="fees-structure.php" class="btn btn-neutral-200 radius-10">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card fee-shell-card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-0 text-primary-light">Fee Items</h5>
                    <p class="text-secondary-light text-sm mb-0">Saving a row creates or updates that class fee for the selected campus, year, and term.</p>
                </div>
                <button type="button" class="btn btn-sm btn-primary-600 radius-8" data-bs-toggle="modal" data-bs-target="#quickClassFeeModal" <?php echo $canCreateClassFee ? '' : 'disabled'; ?>>
                    <i class="ri-add-circle-line"></i> Quick add fee
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($years) || empty($terms) || empty($classes)): ?>
                    <div class="text-center py-40">
                        <h6 class="text-primary-light">Academic setup is incomplete</h6>
                        <p class="text-secondary-light mb-0">Add an academic year, term, and class for this campus before creating class fees.</p>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-16">
                            <a href="general.php#academic" class="btn btn-outline-primary-600 radius-10">Academic setup</a>
                            <a href="class-list.php?campus_id=<?php echo (int)$selectedCampusId; ?>" class="btn btn-outline-primary-600 radius-10">Classes</a>
                        </div>
                    </div>
                <?php elseif (empty($feeTypes)): ?>
                    <div class="text-center py-40">
                        <h6 class="text-primary-light">No active fee types for this campus</h6>
                        <p class="text-secondary-light">Add a class fee here and the matching fee type will be created automatically.</p>
                        <button type="button" class="btn btn-primary-600 radius-10" data-bs-toggle="modal" data-bs-target="#quickClassFeeModal">
                            <i class="ri-add-line"></i> Add class fee
                        </button>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach ($feeTypes as $feeType): ?>
                            <?php
                                $feeTypeId = (int)$feeType['id'];
                                $row = $existing[$feeTypeId] ?? null;
                            ?>
                            <div class="fee-row">
                                <div class="row gy-3 align-items-end">
                                    <div class="col-xl-3 col-md-6">
                                        <span class="text-secondary-light text-sm">Fee type</span>
                                        <h6 class="mb-0 text-primary-light"><?php echo academix_admin_e($feeType['name']); ?></h6>
                                        <?php if (!empty($feeType['description'])): ?>
                                            <p class="text-secondary-light text-xs mb-0"><?php echo academix_admin_e($feeType['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <form method="post" class="col-xl-8 col-md-12">
                                        <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                        <input type="hidden" name="action" value="save_fee_structure">
                                        <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                                        <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
                                        <input type="hidden" name="academic_year_id" value="<?php echo (int)$selectedYearId; ?>">
                                        <input type="hidden" name="academic_term_id" value="<?php echo (int)$selectedTermId; ?>">
                                        <input type="hidden" name="class_id" value="<?php echo (int)$selectedClassId; ?>">
                                        <input type="hidden" name="fee_type_id" value="<?php echo $feeTypeId; ?>">
                                        <div class="row gy-3 align-items-end">
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Amount</label>
                                                <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?php echo $row ? academix_admin_e($row['amount']) : ''; ?>" placeholder="0.00" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Due date</label>
                                                <input type="date" name="due_date" class="form-control" value="<?php echo $row ? academix_admin_e($row['due_date'] ?? '') : ''; ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold">Late fee</label>
                                                <input type="number" step="0.01" min="0" name="late_fee" class="form-control" value="<?php echo $row ? academix_admin_e($row['late_fee'] ?? '0') : '0'; ?>">
                                            </div>
                                            <div class="col-12 d-flex justify-content-end">
                                                <button class="btn btn-primary-600 radius-10" type="submit">
                                                    <i class="ri-save-3-line"></i> <?php echo $row ? 'Update fee' : 'Save fee'; ?>
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                    <div class="col-xl-1 col-md-12 d-flex justify-content-xl-end">
                                        <?php if ($row): ?>
                                            <form method="post" onsubmit="return confirm('Remove this fee from the selected class?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                                <input type="hidden" name="action" value="delete_fee_structure">
                                                <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-outline-danger-600 radius-10" type="submit"><i class="ri-delete-bin-line"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<div class="modal fade" id="quickClassFeeModal" tabindex="-1" aria-labelledby="quickClassFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
            <input type="hidden" name="action" value="create_class_fee">
            <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
            <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold text-primary-light" id="quickClassFeeModalLabel">Add Class Fee</h5>
                    <p class="text-secondary-light text-sm mb-0">Create a fee type and attach it to a class for the selected campus.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row gy-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Academic year</label>
                        <select name="academic_year_id" class="form-select" required>
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo (int)$year['id']; ?>" <?php echo $selectedYearId === (int)$year['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($year['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Term</label>
                        <select name="academic_term_id" class="form-select" required>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?php echo (int)$term['id']; ?>" <?php echo $selectedTermId === (int)$term['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($term['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Class</label>
                        <select name="class_id" class="form-select" required>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo (int)$class['id']; ?>" <?php echo $selectedClassId === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo academix_admin_e($class['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Fee name</label>
                        <input type="text" name="fee_name" class="form-control" placeholder="e.g. Tuition, PTA levy, Exam fee" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Amount</label>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0.01" placeholder="0.00" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Due date</label>
                        <input type="date" name="due_date" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Late fee</label>
                        <input type="number" name="late_fee" class="form-control" step="0.01" min="0" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional note for this fee type"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-neutral-200 radius-10" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary-600 radius-10" <?php echo $canCreateClassFee ? '' : 'disabled'; ?>>
                    <i class="ri-save-3-line"></i> Save class fee
                </button>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
</body>
</html>
