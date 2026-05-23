<?php
require_once __DIR__ . '/includes/handlers/other-handler.php';
require_once __DIR__ . '/includes/campus-context.php';

$csrf = academix_admin_csrf_token();
$currency = $school['currency_symbol'] ?? '₦';
$toasts = academix_admin_take_toasts();
$campuses = $schoolDb ? academix_admin_get_campuses($schoolDb, $school, false) : [];
$activeCampuses = array_values(array_filter($campuses, static fn($campus) => (int)($campus['is_active'] ?? 1) === 1));
$selectedCampusId = $schoolDb ? academix_admin_resolve_campus_id($schoolDb, $school, true) : 0;
$selectedCampusName = academix_admin_campus_name($campuses, $selectedCampusId);
$returnTo = 'transaction.php?campus_id=' . $selectedCampusId;

$summary = [
    'manual_income' => 0.0,
    'manual_expense' => 0.0,
    'fee_collections' => 0.0,
    'gateway_income' => 0.0,
    'manual_count' => 0,
    'fee_count' => 0,
    'gateway_count' => 0,
];
$ledger = [];
$tableWarning = '';

if ($schoolDb) {
    academix_admin_ensure_transactions_table($schoolDb);
    $transactionColumns = academix_admin_fresh_columns($schoolDb, 'transactions');
    $transactionHasCampus = in_array('campus_id', $transactionColumns, true);

    try {
        $where = 'school_id = ?';
        $params = [(int)$school['id']];
        if ($selectedCampusId > 0 && $transactionHasCampus) {
            $where .= ' AND campus_id = ?';
            $params[] = $selectedCampusId;
        }

        $stmt = $schoolDb->prepare("SELECT type, SUM(amount) AS total, COUNT(*) AS total_count FROM transactions WHERE {$where} GROUP BY type");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = strtolower((string)($row['type'] ?? 'income'));
            if ($type === 'expense') {
                $summary['manual_expense'] += (float)($row['total'] ?? 0);
            } else {
                $summary['manual_income'] += (float)($row['total'] ?? 0);
            }
            $summary['manual_count'] += (int)($row['total_count'] ?? 0);
        }

        $selectCampus = $transactionHasCampus ? 'campus_id' : 'NULL AS campus_id';
        $stmt = $schoolDb->prepare("
            SELECT id, {$selectCampus}, type, amount, description, category, payment_method, reference,
                   COALESCE(`date`, DATE(created_at)) AS txn_date, created_at
            FROM transactions
            WHERE {$where}
            ORDER BY COALESCE(`date`, DATE(created_at)) DESC, created_at DESC
            LIMIT 60
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ledger[] = [
                'source' => 'manual',
                'id' => (int)$row['id'],
                'campus_id' => (int)($row['campus_id'] ?? 0),
                'date' => $row['txn_date'] ?: $row['created_at'],
                'type' => strtolower((string)($row['type'] ?? 'income')) === 'expense' ? 'expense' : 'income',
                'category' => $row['category'] ?: 'Manual transaction',
                'description' => $row['description'] ?: 'Recorded by admin',
                'method' => $row['payment_method'] ?: 'cash',
                'reference' => $row['reference'] ?: ('TXN-' . (int)$row['id']),
                'amount' => (float)$row['amount'],
            ];
        }
    } catch (Throwable $e) {
        $tableWarning = 'Could not load manual transactions: ' . $e->getMessage();
        error_log('Transaction page manual ledger failed: ' . $e->getMessage());
    }

    if (academix_admin_table_exists($schoolDb, 'fee_payments')) {
        try {
            $where = 'fp.school_id = ?';
            $params = [(int)$school['id']];
            $feePaymentsHasCampus = academix_admin_has_column($schoolDb, 'fee_payments', 'campus_id');
            $feePaymentsCampusSelect = $feePaymentsHasCampus ? 'fp.campus_id' : 'NULL AS campus_id';
            if ($selectedCampusId > 0 && $feePaymentsHasCampus) {
                $where .= ' AND fp.campus_id = ?';
                $params[] = $selectedCampusId;
            }

            $stmt = $schoolDb->prepare("SELECT SUM(fp.amount - COALESCE(fp.discount_amount, 0)) AS total, COUNT(*) AS total_count FROM fee_payments fp WHERE {$where}");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['fee_collections'] = (float)($row['total'] ?? 0);
            $summary['fee_count'] = (int)($row['total_count'] ?? 0);

            $stmt = $schoolDb->prepare("
                SELECT fp.id, {$feePaymentsCampusSelect}, fp.amount, fp.discount_amount, fp.payment_method, fp.reference, fp.notes,
                       COALESCE(fp.paid_at, fp.created_at) AS txn_date,
                       CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) AS student_name,
                       ft.name AS fee_name
                FROM fee_payments fp
                LEFT JOIN students s ON s.id = fp.student_id AND s.school_id = fp.school_id
                LEFT JOIN fee_types ft ON ft.id = fp.fee_type_id AND ft.school_id = fp.school_id
                WHERE {$where}
                ORDER BY COALESCE(fp.paid_at, fp.created_at) DESC
                LIMIT 60
            ");
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $feeName = trim((string)($row['fee_name'] ?? 'Fee payment'));
                $studentName = trim((string)($row['student_name'] ?? ''));
                $ledger[] = [
                    'source' => 'fee',
                    'id' => (int)$row['id'],
                    'campus_id' => (int)($row['campus_id'] ?? 0),
                    'date' => $row['txn_date'],
                    'type' => 'income',
                    'category' => $feeName !== '' ? $feeName : 'Fee payment',
                    'description' => $studentName !== '' ? 'Payment from ' . $studentName : ($row['notes'] ?: 'Fee payment recorded'),
                    'method' => $row['payment_method'] ?: 'cash',
                    'reference' => $row['reference'] ?: ('FEE-' . (int)$row['id']),
                    'amount' => max(0, (float)$row['amount'] - (float)($row['discount_amount'] ?? 0)),
                ];
            }
        } catch (Throwable $e) {
            error_log('Transaction page fee ledger failed: ' . $e->getMessage());
        }
    }

    if (academix_admin_table_exists($schoolDb, 'payment_transactions')) {
        try {
            $columns = academix_admin_fresh_columns($schoolDb, 'payment_transactions');
            $amountColumn = in_array('amount', $columns, true) ? 'amount' : (in_array('amount_paid', $columns, true) ? 'amount_paid' : null);
            $hasStatus = in_array('status', $columns, true);
            $hasCampus = in_array('campus_id', $columns, true);
            if ($amountColumn !== null) {
                $where = 'school_id = ?';
                $params = [(int)$school['id']];
                if ($hasStatus) {
                    $where .= " AND status IN ('success', 'successful', 'paid', 'completed')";
                }
                if ($selectedCampusId > 0 && $hasCampus) {
                    $where .= ' AND campus_id = ?';
                    $params[] = $selectedCampusId;
                }
                $stmt = $schoolDb->prepare("SELECT SUM({$amountColumn}) AS total, COUNT(*) AS total_count FROM payment_transactions WHERE {$where}");
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $summary['gateway_income'] = (float)($row['total'] ?? 0);
                $summary['gateway_count'] = (int)($row['total_count'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('Transaction page gateway summary failed: ' . $e->getMessage());
        }
    }
}

usort($ledger, static function ($a, $b) {
    return strtotime((string)$b['date']) <=> strtotime((string)$a['date']);
});
$ledger = array_slice($ledger, 0, 50);

$totalIncome = $summary['manual_income'] + $summary['fee_collections'] + $summary['gateway_income'];
$totalExpense = $summary['manual_expense'];
$netBalance = $totalIncome - $totalExpense;

function academix_admin_money(float $amount, string $currency): string {
    return $currency . ' ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo academix_admin_e($school['name']); ?> | Transactions</title>
    <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/remixicon.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/lib/dataTables.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo academix_admin_asset('css/style.css'); ?>">
    <style>
        .finance-stat {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            background: #fff;
            padding: 22px;
            min-height: 150px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, .06);
        }
        .finance-stat__icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .ledger-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 16px 45px rgba(15, 23, 42, .07);
        }
        .campus-chip {
            border: 1px solid #dbe4ea;
            background: #fff;
            border-radius: 999px;
            padding: 8px 14px;
            color: #475569;
            font-weight: 600;
        }
        .campus-chip.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        .transaction-type {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .transaction-type.income { background: #10b981; }
        .transaction-type.expense { background: #ef4444; }
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

        <?php if ($tableWarning !== ''): ?>
            <div class="alert alert-warning"><?php echo academix_admin_e($tableWarning); ?></div>
        <?php endif; ?>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
            <div>
                <h1 class="fw-bold mb-4 h4 text-primary-light">Transactions</h1>
                <p class="text-secondary-light mb-0">Campus-aware income, expense, fee collection, and payment activity.</p>
            </div>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="text-sm fw-semibold text-secondary-light mb-0">Campus</label>
                <select name="campus_id" class="form-select radius-10" onchange="this.form.submit()">
                    <option value="0" <?php echo $selectedCampusId === 0 ? 'selected' : ''; ?>>All campuses</option>
                    <?php foreach ($activeCampuses as $campus): ?>
                        <option value="<?php echo (int)$campus['id']; ?>" <?php echo $selectedCampusId === (int)$campus['id'] ? 'selected' : ''; ?>>
                            <?php echo academix_admin_e($campus['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-24">
            <a class="campus-chip <?php echo $selectedCampusId === 0 ? 'active' : ''; ?>" href="transaction.php?campus_id=0">All campuses</a>
            <?php foreach ($activeCampuses as $campus): ?>
                <a class="campus-chip <?php echo $selectedCampusId === (int)$campus['id'] ? 'active' : ''; ?>" href="transaction.php?campus_id=<?php echo (int)$campus['id']; ?>">
                    <?php echo academix_admin_e($campus['name']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="row gy-4 mb-24">
            <div class="col-xxl-3 col-sm-6">
                <div class="finance-stat">
                    <span class="finance-stat__icon bg-success-100 text-success-600"><i class="ri-arrow-up-circle-line"></i></span>
                    <p class="text-secondary-light mb-8 mt-16">Total Income</p>
                    <h3 class="mb-4"><?php echo academix_admin_money($totalIncome, $currency); ?></h3>
                    <span class="text-sm text-success-600 fw-semibold"><?php echo (int)($summary['manual_count'] + $summary['fee_count'] + $summary['gateway_count']); ?> income records</span>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="finance-stat">
                    <span class="finance-stat__icon bg-danger-100 text-danger-600"><i class="ri-arrow-down-circle-line"></i></span>
                    <p class="text-secondary-light mb-8 mt-16">Total Expenses</p>
                    <h3 class="mb-4"><?php echo academix_admin_money($totalExpense, $currency); ?></h3>
                    <span class="text-sm text-danger-600 fw-semibold">Manual expense ledger</span>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="finance-stat">
                    <span class="finance-stat__icon bg-info-100 text-info-600"><i class="ri-bank-card-line"></i></span>
                    <p class="text-secondary-light mb-8 mt-16">Fee Collections</p>
                    <h3 class="mb-4"><?php echo academix_admin_money($summary['fee_collections'], $currency); ?></h3>
                    <span class="text-sm text-info-600 fw-semibold"><?php echo (int)$summary['fee_count']; ?> fee payments</span>
                </div>
            </div>
            <div class="col-xxl-3 col-sm-6">
                <div class="finance-stat">
                    <span class="finance-stat__icon bg-primary-100 text-primary-600"><i class="ri-wallet-3-line"></i></span>
                    <p class="text-secondary-light mb-8 mt-16">Net Balance</p>
                    <h3 class="mb-4"><?php echo academix_admin_money($netBalance, $currency); ?></h3>
                    <span class="text-sm fw-semibold <?php echo $netBalance >= 0 ? 'text-success-600' : 'text-danger-600'; ?>">
                        <?php echo academix_admin_e($selectedCampusName); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="card ledger-card">
            <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="card-title mb-0 text-primary-light">Recent Ledger</h5>
                    <p class="text-secondary-light text-sm mb-0">Manual entries and fee payments are grouped by selected campus.</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="fees-collect.php" class="btn btn-outline-success-600 radius-10"><i class="ri-money-dollar-circle-line"></i> Collect Fees</a>
                    <a href="fees-structure.php" class="btn btn-outline-primary-600 radius-10"><i class="ri-file-list-3-line"></i> Class Fees</a>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table bordered-table mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Campus</th>
                                <th>Reference</th>
                                <th>Method</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ledger)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-40 text-secondary-light">No transactions found for this campus view.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($ledger as $entry): ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-primary-light"><?php echo academix_admin_e(date('M j, Y', strtotime((string)$entry['date']))); ?></span>
                                        <span class="d-block text-xs text-secondary-light"><?php echo academix_admin_e(ucfirst($entry['source'])); ?> record</span>
                                    </td>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-2 fw-semibold <?php echo $entry['type'] === 'expense' ? 'text-danger-600' : 'text-success-600'; ?>">
                                            <span class="transaction-type <?php echo academix_admin_e($entry['type']); ?>"></span>
                                            <?php echo academix_admin_e(ucfirst($entry['type'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?php echo academix_admin_e($entry['category']); ?></span>
                                        <span class="d-block text-xs text-secondary-light"><?php echo academix_admin_e($entry['description']); ?></span>
                                    </td>
                                    <td><?php echo academix_admin_e(academix_admin_campus_name($campuses, (int)$entry['campus_id'])); ?></td>
                                    <td><span class="badge bg-neutral-100 text-secondary-light"><?php echo academix_admin_e($entry['reference']); ?></span></td>
                                    <td><?php echo academix_admin_e(ucfirst((string)$entry['method'])); ?></td>
                                    <td class="text-end fw-bold <?php echo $entry['type'] === 'expense' ? 'text-danger-600' : 'text-success-600'; ?>">
                                        <?php echo ($entry['type'] === 'expense' ? '-' : '+') . academix_admin_money((float)$entry['amount'], $currency); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($entry['source'] === 'manual'): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this transaction?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                                                <input type="hidden" name="action" value="delete_transaction">
                                                <input type="hidden" name="id" value="<?php echo (int)$entry['id']; ?>">
                                                <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                                                <button class="btn btn-sm btn-outline-danger-600" type="submit"><i class="ri-delete-bin-line"></i></button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-xs text-secondary-light">System</span>
                                        <?php endif; ?>
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

<div class="modal fade" id="transactionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content radius-16">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo academix_admin_e($csrf); ?>">
                <input type="hidden" name="action" value="create_transaction">
                <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title text-primary-light">Record Manual Transaction</h5>
                        <p class="text-secondary-light text-sm mb-0">Use this for non-fee income, expenses, grants, refunds, and operating costs.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row gy-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Campus</label>
                            <select name="campus_id" class="form-select" required>
                                <?php foreach ($activeCampuses as $campus): ?>
                                    <option value="<?php echo (int)$campus['id']; ?>" <?php echo ($selectedCampusId > 0 ? $selectedCampusId : (int)($activeCampuses[0]['id'] ?? 0)) === (int)$campus['id'] ? 'selected' : ''; ?>>
                                        <?php echo academix_admin_e($campus['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type</label>
                            <select name="type" class="form-select" required>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount</label>
                            <input type="number" step="0.01" min="0" name="amount" class="form-control" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g. Transport, PTA levy, Stationery">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank transfer</option>
                                <option value="card">Card</option>
                                <option value="pos">POS</option>
                                <option value="cheque">Cheque</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Reference</label>
                            <input type="text" name="reference" class="form-control" placeholder="Auto-generated if empty">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="What was this transaction for?"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-neutral-200" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary-600">Save transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?php echo academix_admin_asset('js/lib/jquery-3.7.1.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/bootstrap.bundle.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/lib/iconify-icon.min.js'); ?>"></script>
<script src="<?php echo academix_admin_asset('js/app.js'); ?>"></script>
</body>
</html>
