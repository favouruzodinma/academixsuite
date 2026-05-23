<?php
require_once __DIR__ . '/includes/admin-bootstrap.php';
require_once __DIR__ . '/includes/campus-context.php';

$csrf = academix_admin_csrf_token();
academix_admin_ensure_campuses($schoolDb, $school);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $schoolDb) {
    try {
        if (!academix_admin_validate_csrf($_POST['csrf_token'] ?? null)) {
            throw new Exception('Security validation failed. Please refresh and try again.');
        }

        $action = $_POST['action'] ?? '';
        if ($action === 'save_campus') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new Exception('Campus name is required.');
            }

            $campusId = (int)($_POST['id'] ?? 0);
            $code = academix_admin_campus_code((string)($_POST['code'] ?? $name));
            $payload = [
                $name,
                $code,
                trim((string)($_POST['address'] ?? '')),
                trim((string)($_POST['city'] ?? '')),
                trim((string)($_POST['state'] ?? '')),
                trim((string)($_POST['country'] ?? '')),
                trim((string)($_POST['phone'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
                !empty($_POST['is_active']) ? 1 : 0,
            ];

            if ($campusId > 0) {
                $stmt = $schoolDb->prepare("
                    UPDATE campuses
                    SET name = ?, code = ?, address = ?, city = ?, state = ?, country = ?, phone = ?, email = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([...$payload, $campusId, (int)$school['id']]);
                setToast('success', 'Campus updated successfully.');
            } else {
                $stmt = $schoolDb->prepare("
                    INSERT INTO campuses
                        (school_id, name, code, address, city, state, country, phone, email, is_active, created_at, updated_at)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([(int)$school['id'], ...$payload]);
                setToast('success', 'Campus added successfully.');
            }
        } elseif ($action === 'toggle_campus') {
            $campusId = (int)($_POST['id'] ?? 0);
            if ($campusId <= 0) {
                throw new Exception('Campus not found.');
            }
            $stmt = $schoolDb->prepare('UPDATE campuses SET is_active = ?, updated_at = NOW() WHERE id = ? AND school_id = ?');
            $stmt->execute([!empty($_POST['is_active']) ? 1 : 0, $campusId, (int)$school['id']]);
            setToast('success', 'Campus status updated.');
        }
    } catch (Throwable $e) {
        error_log('Campus page error: ' . $e->getMessage());
        setToast('error', $e->getMessage());
    }

    header('Location: campuses.php');
    exit;
}

$toasts = academix_admin_take_toasts();
$campuses = academix_admin_get_campuses($schoolDb, $school, false);
$selectedCampusId = academix_admin_resolve_campus_id($schoolDb, $school, true);
$currency = $school['currency_symbol'] ?? '₦';

function academix_campus_count(PDO $db, string $table, int $schoolId, int $campusId): int {
    if (!academix_admin_table_exists($db, $table) || !academix_admin_has_column($db, $table, 'campus_id')) {
        return 0;
    }
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE school_id = ? AND campus_id = ?");
        $stmt->execute([$schoolId, $campusId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Campus count failed for ' . $table . ': ' . $e->getMessage());
        return 0;
    }
}

function academix_campus_sum(PDO $db, string $table, string $column, int $schoolId, int $campusId): float {
    if (!academix_admin_table_exists($db, $table) || !academix_admin_has_column($db, $table, 'campus_id') || !academix_admin_has_column($db, $table, $column)) {
        return 0.0;
    }
    try {
        $stmt = $db->prepare("SELECT SUM(`{$column}`) FROM `{$table}` WHERE school_id = ? AND campus_id = ?");
        $stmt->execute([$schoolId, $campusId]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('Campus sum failed for ' . $table . ': ' . $e->getMessage());
        return 0.0;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Campuses</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        .campus-shell-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, .06);
        }
        .campus-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 12px;
            font-weight: 700;
            font-size: 12px;
        }
        .campus-status.active { background: #dcfce7; color: #166534; }
        .campus-status.inactive { background: #fee2e2; color: #991b1b; }
        .campus-stat-mini {
            background: #f8fafc;
            border: 1px solid #eef2f7;
            border-radius: 14px;
            padding: 14px;
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
                <h1 class="fw-bold mb-4 h4 text-primary-light">Campuses</h1>
                <p class="text-secondary-light mb-0">Add campuses and keep finance, students, classes, and fees filtered by location.</p>
            </div>
            <button class="btn btn-primary-600 radius-10" data-bs-toggle="modal" data-bs-target="#campusModal">
                <i class="ri-add-line"></i> Add Campus
            </button>
        </div>

        <div class="row gy-4">
            <?php foreach ($campuses as $campus): ?>
                <?php
                    $campusId = (int)$campus['id'];
                    $students = academix_campus_count($schoolDb, 'students', (int)$school['id'], $campusId);
                    $classes = academix_campus_count($schoolDb, 'classes', (int)$school['id'], $campusId);
                    $teachers = academix_campus_count($schoolDb, 'teachers', (int)$school['id'], $campusId);
                    $collections = academix_campus_sum($schoolDb, 'fee_payments', 'amount', (int)$school['id'], $campusId);
                ?>
                <div class="col-xxl-4 col-lg-6">
                    <div class="card campus-shell-card h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between gap-3 mb-18">
                                <div>
                                    <h5 class="mb-4 text-primary-light"><?php echo academix_admin_e($campus['name']); ?></h5>
                                    <span class="text-secondary-light text-sm"><?php echo academix_admin_e($campus['code']); ?></span>
                                </div>
                                <span class="campus-status <?php echo !empty($campus['is_active']) ? 'active' : 'inactive'; ?>">
                                    <span class="w-8-px h-8-px rounded-circle bg-current"></span>
                                    <?php echo !empty($campus['is_active']) ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>

                            <p class="text-secondary-light mb-16">
                                <?php echo academix_admin_e(trim(($campus['address'] ?? '') . ' ' . ($campus['city'] ?? '') . ' ' . ($campus['state'] ?? '')) ?: 'No address added yet.'); ?>
                            </p>

                            <div class="row gy-3 mb-20">
                                <div class="col-6">
                                    <div class="campus-stat-mini">
                                        <span class="text-secondary-light text-xs">Students</span>
                                        <h6 class="mb-0"><?php echo $students; ?></h6>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="campus-stat-mini">
                                        <span class="text-secondary-light text-xs">Classes</span>
                                        <h6 class="mb-0"><?php echo $classes; ?></h6>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="campus-stat-mini">
                                        <span class="text-secondary-light text-xs">Teachers</span>
                                        <h6 class="mb-0"><?php echo $teachers; ?></h6>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="campus-stat-mini">
                                        <span class="text-secondary-light text-xs">Collected</span>
                                        <h6 class="mb-0"><?php echo academix_admin_e($currency . ' ' . number_format($collections, 0)); ?></h6>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <a href="transaction.php?campus_id=<?php echo $campusId; ?>" class="btn btn-sm btn-outline-primary-600">View finance</a>
                                <a href="fees-structure.php?campus_id=<?php echo $campusId; ?>" class="btn btn-sm btn-outline-success-600">Class fees</a>
                                <button
                                    class="btn btn-sm btn-neutral-200 edit-campus-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#campusModal"
                                    data-campus='<?php echo academix_admin_e(json_encode($campus, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>'>
                                    Edit
                                </button>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                    <input type="hidden" name="action" value="toggle_campus">
                                    <input type="hidden" name="id" value="<?php echo $campusId; ?>">
                                    <input type="hidden" name="is_active" value="<?php echo !empty($campus['is_active']) ? 0 : 1; ?>">
                                    <button class="btn btn-sm <?php echo !empty($campus['is_active']) ? 'btn-outline-danger-600' : 'btn-outline-success-600'; ?>" type="submit">
                                        <?php echo !empty($campus['is_active']) ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<div class="modal fade" id="campusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content radius-16">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                <input type="hidden" name="action" value="save_campus">
                <input type="hidden" name="id" id="campus-id" value="">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title text-primary-light" id="campus-modal-title">Add Campus</h5>
                        <p class="text-secondary-light text-sm mb-0">Each campus can have its own students, classes, fees, and transactions.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row gy-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Campus name</label>
                            <input type="text" name="name" id="campus-name" class="form-control" required placeholder="Main Campus">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Campus code</label>
                            <input type="text" name="code" id="campus-code" class="form-control" placeholder="MAIN">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Address</label>
                            <input type="text" name="address" id="campus-address" class="form-control" placeholder="Street address">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">City</label>
                            <input type="text" name="city" id="campus-city" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">State</label>
                            <input type="text" name="state" id="campus-state" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Country</label>
                            <input type="text" name="country" id="campus-country" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" id="campus-phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" id="campus-email" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-check d-flex align-items-center gap-2">
                                <input type="checkbox" name="is_active" id="campus-active" class="form-check-input" value="1" checked>
                                <span class="form-check-label fw-semibold">Campus is active</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-neutral-200" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Save campus</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
<script>
document.querySelectorAll('.edit-campus-btn').forEach((button) => {
    button.addEventListener('click', () => {
        const campus = JSON.parse(button.dataset.campus || '{}');
        document.getElementById('campus-modal-title').textContent = 'Edit Campus';
        document.getElementById('campus-id').value = campus.id || '';
        document.getElementById('campus-name').value = campus.name || '';
        document.getElementById('campus-code').value = campus.code || '';
        document.getElementById('campus-address').value = campus.address || '';
        document.getElementById('campus-city').value = campus.city || '';
        document.getElementById('campus-state').value = campus.state || '';
        document.getElementById('campus-country').value = campus.country || '';
        document.getElementById('campus-phone').value = campus.phone || '';
        document.getElementById('campus-email').value = campus.email || '';
        document.getElementById('campus-active').checked = String(campus.is_active ?? '1') === '1';
    });
});

document.getElementById('campusModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('campus-modal-title').textContent = 'Add Campus';
    document.getElementById('campus-id').value = '';
    document.getElementById('campus-name').value = '';
    document.getElementById('campus-code').value = '';
    document.getElementById('campus-address').value = '';
    document.getElementById('campus-city').value = '';
    document.getElementById('campus-state').value = '';
    document.getElementById('campus-country').value = '';
    document.getElementById('campus-phone').value = '';
    document.getElementById('campus-email').value = '';
    document.getElementById('campus-active').checked = true;
});
</script>
</body>
</html>
