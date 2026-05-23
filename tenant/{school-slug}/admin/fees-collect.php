<?php require_once __DIR__ . '/includes/handlers/fees-handler.php'; ?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="description"
    content="Modern Education Admin Dashboard for schools, colleges, universities, and eLearning platforms. Includes student and course management, attendance, exams, payments, analytics, and a fully responsive clean UI—ideal for LMS, coaching centers, and academic admin systems.">
  <meta name="keywords"
    content="Education Admin Dashboard, School Admin Panel, College Dashboard, University Dashboard, LMS Dashboard, eLearning Admin Template, Student Management System, Course Management, Education Template, Study Dashboard, Online Learning Dashboard, Academic Admin Panel, Bootstrap Dashboard, React Education Dashboard, Next.js Education Template">
  <meta name="robots" content="INDEX,FOLLOW">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($school['name']); ?> | <?php echo defined('APP_NAME') ? APP_NAME : 'School Management'; ?></title>
  <link rel="icon" type="image/png" href="<?php echo academix_admin_e($schoolLogoUrl); ?>" sizes="16x16">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/remixicon.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/bootstrap.min.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/apexcharts.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/dataTables.min.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/flatpickr.min.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/full-calendar.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/lib/calendar.css">
  <link rel="stylesheet" href="https://academixsuite.com/tenant/assets/css/style.css">
</head>

<body>
<?php
$toasts = academix_admin_take_toasts();
$toastSuccess = $toasts['success'] ?? '';
$toastError   = $toasts['error'] ?? '';
$schoolId = (int)($school['id'] ?? 0);

// ── Filter state ─────────────────────────────────────────────────────────────
$campuses = $schoolDb ? academix_admin_get_campuses($schoolDb, $school, true) : [];
$selectedCampusId = $schoolDb ? academix_admin_resolve_campus_id($schoolDb, $school, false) : 0;
$selectedClassId  = (int)($_GET['class_id'] ?? 0);
$returnTo = 'fees-collect.php?' . http_build_query([
    'campus_id' => $selectedCampusId,
    'class_id' => $selectedClassId,
]);

// ── Classes ──────────────────────────────────────────────────────────────────
$classes = [];
if ($schoolDb && $selectedCampusId > 0) {
    try {
        $classSql = 'SELECT id, name FROM classes WHERE school_id = ?';
        $classParams = [$schoolId];
        if (academix_admin_has_column($schoolDb, 'classes', 'campus_id')) {
            $classSql .= ' AND campus_id = ?';
            $classParams[] = $selectedCampusId;
        }
        if (academix_admin_has_column($schoolDb, 'classes', 'is_active')) {
            $classSql .= ' AND is_active = 1';
        }
        $classSql .= ' ORDER BY name';
        $stmt = $schoolDb->prepare($classSql);
        $stmt->execute($classParams);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee collection class load failed: ' . $e->getMessage());
        $toastError = $toastError ?: 'Unable to load classes for this campus.';
    }
}

// ── Fee types for collect form ───────────────────────────────────────────────
$feeTypes = [];
if ($schoolDb && $selectedCampusId > 0) {
    try {
        $stmt = $schoolDb->prepare("SELECT id, name FROM fee_types WHERE school_id = ? AND campus_id = ? AND status = 'Active' ORDER BY name");
        $stmt->execute([$schoolId, $selectedCampusId]);
        $feeTypes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee collection fee-type load failed: ' . $e->getMessage());
    }
}

// ── Discounts for collect form ───────────────────────────────────────────────
$discounts = [];
if ($schoolDb && $selectedCampusId > 0) {
    try {
        $stmt = $schoolDb->prepare("SELECT id, name, amount, type FROM fee_discounts WHERE school_id = ? AND campus_id = ? AND status = 'Active' ORDER BY name");
        $stmt->execute([$schoolId, $selectedCampusId]);
        $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('Fee collection discount load failed: ' . $e->getMessage());
    }
}

// ── Students with fee summary ────────────────────────────────────────────────
$students = [];
if ($schoolDb && $selectedCampusId > 0 && $selectedClassId > 0) {
    try {
        $paymentCampusJoin = '';
        if (academix_admin_has_column($schoolDb, 'fee_payments', 'campus_id')) {
            $paymentCampusJoin = ' AND fp.campus_id = ' . $selectedCampusId;
        }

        $studentWhere = 's.school_id = ? AND s.class_id = ?';
        $studentParams = [$schoolId, $selectedClassId];
        if (academix_admin_has_column($schoolDb, 'students', 'campus_id')) {
            $studentWhere .= ' AND s.campus_id = ?';
            $studentParams[] = $selectedCampusId;
        }

        $sql = "SELECT s.id, s.admission_number, s.first_name, s.middle_name, s.last_name, s.roll_number,
                       c.name AS class_name,
                       COALESCE(SUM(fp.amount - COALESCE(fp.discount_amount, 0)), 0) AS total_paid
                FROM students s
                LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
                LEFT JOIN fee_payments fp ON fp.student_id = s.id AND fp.school_id = s.school_id {$paymentCampusJoin}
                WHERE {$studentWhere}
                GROUP BY s.id
                ORDER BY s.first_name, s.last_name";
        $stmt = $schoolDb->prepare($sql);
        $stmt->execute($studentParams);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $feeSql = 'SELECT COALESCE(SUM(amount), 0) AS total_fee FROM fee_structures WHERE school_id = ? AND class_id = ?';
        $feeParams = [$schoolId, $selectedClassId];
        if (academix_admin_has_column($schoolDb, 'fee_structures', 'campus_id')) {
            $feeSql .= ' AND campus_id = ?';
            $feeParams[] = $selectedCampusId;
        }
        $totalFeeStmt = $schoolDb->prepare($feeSql);
        $totalFeeStmt->execute($feeParams);
        $classTotalFee = (float)$totalFeeStmt->fetchColumn();

        foreach ($students as &$student) {
            $student['total_fee'] = $classTotalFee;
            $student['total_paid'] = (float)$student['total_paid'];
            $student['balance'] = max(0, $student['total_fee'] - $student['total_paid']);
            if ($student['balance'] <= 0 && $student['total_fee'] > 0) {
                $student['status'] = 'Paid';
            } elseif ($student['total_paid'] > 0) {
                $student['status'] = 'Partial';
            } else {
                $student['status'] = 'Unpaid';
            }
        }
        unset($student);
    } catch (Throwable $e) {
        error_log('Fee collection student load failed: ' . $e->getMessage());
        $toastError = $toastError ?: 'Unable to load fee collection records for this class.';
    }
}
?>
  <!-- Toast Container -->
  <?php if ($toastSuccess || $toastError): ?>
  <div class="toast-container" id="toastContainer">
    <?php if ($toastSuccess): ?>
    <div class="toast success show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
      <div class="toast-header">
        <i class="ri-checkbox-circle-line me-2"></i>
        <strong class="me-auto">Success</strong>
        <small>just now</small>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body"><?php echo htmlspecialchars($toastSuccess); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($toastError): ?>
    <div class="toast error show" role="alert" aria-live="assertive" aria-atomic="true" data-autohide="true" data-delay="5000">
      <div class="toast-header">
        <i class="ri-error-warning-line me-2"></i>
        <strong class="me-auto">Error</strong>
        <small>just now</small>
        <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
      <div class="toast-body"><?php echo htmlspecialchars($toastError); ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="overlay bg-black bg-opacity-50 w-100 h-100 position-fixed z-9 visibility-hidden opacity-0 duration-300"></div>

  <?php include_once('includes/sidebar.php'); ?>

  <main class="dashboard-main">
    <?php require_once __DIR__ . '/includes/nav-header.php'; ?>

    <div class="dashboard-main-body">
      <div class="breadcrumb d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
          <h1 class="fw-semibold mb-4 h6 text-primary-light">Fee Collection</h1>
          <div>
            <a href="index.php" class="text-secondary-light hover-text-primary hover-underline">Dashboard</a>
            <span class="text-secondary-light">/ Fee Collection</span>
          </div>
        </div>
      </div>

      <!-- Filter Card -->
      <div class="card mb-24">
        <div class="card-body">
          <form class="row g-3 align-items-end" method="GET" action="">
            <div class="col-md-4">
              <label for="campus_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Campus</label>
              <select name="campus_id" id="campus_id" class="form-control form-select">
                <option value="">Select Campus</option>
                <?php foreach ($campuses as $campus): ?>
                <option value="<?php echo (int)$campus['id']; ?>" <?php echo $selectedCampusId === (int)$campus['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($campus['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label for="class_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Class</label>
              <select name="class_id" id="class_id" class="form-control form-select">
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                <option value="<?php echo (int)$class['id']; ?>" <?php echo $selectedClassId === (int)$class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-flex gap-3">
              <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8">Load</button>
              <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-danger-200 text-danger-600 text-md px-28 py-12 radius-8">Reset</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Student DataTable Card -->
      <div class="card h-100">
        <div class="card-body p-0 dataTable-wrapper">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-16 px-20 py-12 border-bottom border-neutral-200">
            <div class="d-flex flex-wrap align-items-center gap-16">
              <div class="dropdown">
                <button type="button" class="px-12 py-5-px border border-neutral-300 radius-8 d-flex align-items-center gap-20" data-bs-toggle="dropdown" aria-expanded="false">
                  <span class="d-flex align-items-center gap-1 text-secondary-light text-sm">
                    <i class="ri-file-upload-line text-md line-height-1"></i>
                    Export
                  </span>
                  <span><i class="ri-arrow-down-s-line"></i></span>
                </button>
                <ul class="dropdown-menu p-12 border bg-base shadow">
                  <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" onclick="exportPDF()"><i class="ri-file-3-line"></i>PDF</button></li>
                  <li><button type="button" class="dropdown-item px-16 py-8 rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-10" onclick="exportExcel()"><i class="ri-file-excel-line"></i>Excel</button></li>
                </ul>
              </div>
              <form class="navbar-search dt-search m-0">
                <input type="text" class="dt-input bg-transparent radius-4" aria-controls="dataTable" name="search" placeholder="Search...">
                <iconify-icon icon="ion:search-outline" class="icon"></iconify-icon>
              </form>
            </div>
            <div class="d-flex align-items-center gap-8 text-secondary-light">
              <span>Rows per page:</span>
              <div class="dt-length">
                <select name="dataTable_length" aria-controls="dataTable" class="dt-input form-control form-select">
                  <option value="5">5</option>
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
              </div>
            </div>
          </div>

          <div class="p-0">
            <table class="table bordered-table mb-0 data-table" id="dataTable" data-page-length="10">
              <thead>
                <tr>
                  <th scope="col">S.L</th>
                  <th scope="col">Admission No</th>
                  <th scope="col">Student Name</th>
                  <th scope="col">Class</th>
                  <th scope="col">Total Fee</th>
                  <th scope="col">Paid</th>
                  <th scope="col">Balance</th>
                  <th scope="col">Status</th>
                  <th scope="col">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($students)): ?>
                  <?php $sl = 1; ?>
                  <?php foreach ($students as $student): ?>
                  <?php
                    $studentName = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
                    $statusClass = match ($student['status']) {
                        'Paid' => 'bg-success-100 text-success-600',
                        'Partial' => 'bg-warning-100 text-warning-600',
                        default => 'bg-danger-100 text-danger-600',
                    };
                    $admissionNumber = $student['admission_number'] ?? 'N/A';
                    $className = $student['class_name'] ?? 'N/A';
                    $rollNumber = $student['roll_number'] ?? '';
                  ?>
                  <tr>
                    <td><span class="text-sm"><?php echo $sl++; ?></span></td>
                    <td><span class="text-primary-600"><?php echo htmlspecialchars($admissionNumber); ?></span></td>
                    <td>
                      <div class="d-flex align-items-center">
                        <div>
                          <h6 class="text-md mb-0 fw-medium"><?php echo htmlspecialchars($studentName); ?></h6>
                          <?php if ($rollNumber): ?><span class="text-xs text-secondary-light">Roll: <?php echo htmlspecialchars($rollNumber); ?></span><?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <td><?php echo htmlspecialchars($className); ?></td>
                    <td><?php echo number_format($student['total_fee'], 2); ?></td>
                    <td><?php echo number_format($student['total_paid'], 2); ?></td>
                    <td><?php echo number_format($student['balance'], 2); ?></td>
                    <td><span class="<?php echo $statusClass; ?> px-16 py-2 radius-4 fw-medium text-sm"><?php echo $student['status']; ?></span></td>
                    <td>
                      <div class="btn-group">
                        <button type="button" class="text-primary-light text-xl" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
                          <iconify-icon icon="tabler:dots-vertical"></iconify-icon>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-lg-end border p-12">
                          <li>
                            <button type="button" class="collect-fee-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                              data-student-id="<?php echo (int)$student['id']; ?>"
                              data-student-name="<?php echo htmlspecialchars($studentName); ?>">
                              <i class="ri-wallet-3-line"></i>
                              Collect Fee
                            </button>
                          </li>
                          <li>
                            <button type="button" class="view-payments-btn dropdown-item rounded text-secondary-light bg-hover-neutral-200 text-hover-neutral-900 d-flex align-items-center gap-2 py-6"
                              data-student-id="<?php echo (int)$student['id']; ?>"
                              data-student-name="<?php echo htmlspecialchars($studentName); ?>"
                              data-bs-toggle="modal" data-bs-target="#paymentHistoryModal">
                              <i class="ri-eye-line"></i>
                              View
                            </button>
                          </li>
                        </ul>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="9" class="text-center py-20 text-secondary-light">
                      <?php echo $selectedClassId > 0 ? 'No students found for the selected campus and class.' : 'Select a campus and class, then click Load to view students.'; ?>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>
  </main>

  <!-- Collect Fee Sidebar / Offcanvas -->
  <div class="my-sidebar bg-white position-fixed end-0 top-0 h-100vh overflow-y-auto z-99 max-w-700-px w-100 translate-x-full duration-300 active-translate-0" id="collectFeeSidebar">
    <div class="px-20 py-12 border-bottom d-flex align-items-center justify-content-between gap-20">
      <h5 class="text-lg mb-0">Collect Fee</h5>
      <button type="button" class="close-my-sidebar text-danger-600 text-lg d-flex">
        <i class="ri-close-large-line"></i>
      </button>
    </div>
    <form action="" method="POST" class="d-flex flex-column p-20">
      <input type="hidden" name="action" value="collect_fee">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken ?? ''); ?>">
      <input type="hidden" name="return_to" value="<?php echo academix_admin_e($returnTo); ?>">
      <input type="hidden" name="campus_id" value="<?php echo (int)$selectedCampusId; ?>">
      <input type="hidden" name="student_id" id="cf_student_id" value="">

      <div class="row g-3">
        <div class="col-sm-12">
          <div>
            <label class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Student Name</label>
            <input type="text" class="form-control" id="cf_student_name" readonly>
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_fee_type_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Fee Type</label>
            <select name="fee_type_id" id="cf_fee_type_id" class="form-control form-select">
              <option value="">Select Fee Type</option>
              <?php foreach ($feeTypes as $ft): ?>
              <option value="<?php echo (int)$ft['id']; ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_amount" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Amount</label>
            <input type="number" step="0.01" min="0" name="amount" id="cf_amount" class="form-control" placeholder="Enter amount" required>
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_discount_id" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Discount</label>
            <select name="discount_id" id="cf_discount_id" class="form-control form-select">
              <option value="">Select Discount</option>
              <?php foreach ($discounts as $d): ?>
              <option value="<?php echo (int)$d['id']; ?>" data-type="<?php echo htmlspecialchars($d['type']); ?>" data-amount="<?php echo (float)$d['amount']; ?>"><?php echo htmlspecialchars($d['name']); ?> (<?php echo $d['type'] === 'percentage' ? $d['amount'] . '%' : number_format((float)$d['amount'], 2); ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_discount_amount" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Discount Amount</label>
            <input type="number" step="0.01" min="0" name="discount_amount" id="cf_discount_amount" class="form-control" placeholder="0.00">
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_payment_method" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Payment Method</label>
            <select name="payment_method" id="cf_payment_method" class="form-control form-select" required>
              <option value="">Select Payment Method</option>
              <option value="Cash">Cash</option>
              <option value="Bank Transfer">Bank Transfer</option>
              <option value="Card">Card</option>
              <option value="Cheque">Cheque</option>
              <option value="Mobile Money">Mobile Money</option>
            </select>
          </div>
        </div>
        <div class="col-sm-6">
          <div>
            <label for="cf_reference" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Reference</label>
            <input type="text" name="reference" id="cf_reference" class="form-control" placeholder="Transaction reference">
          </div>
        </div>
        <div class="col-sm-12">
          <div>
            <label for="cf_notes" class="text-sm fw-semibold text-primary-light d-inline-block mb-8">Notes</label>
            <textarea name="notes" id="cf_notes" class="form-control" rows="3" placeholder="Enter notes..."></textarea>
          </div>
        </div>
        <div class="col-12">
          <div class="d-flex align-items-center justify-content-center gap-3 mt-8">
            <button type="reset" class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-50 py-11 radius-8 close-my-sidebar">Cancel</button>
            <button type="submit" class="btn btn-primary-600 border border-primary-600 text-md px-28 py-12 radius-8 max-w-156-px w-100">Record Payment</button>
          </div>
        </div>
      </div>
    </form>
  </div>

  <!-- Payment History Modal -->
  <div class="modal fade" id="paymentHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog modal-dialog-centered max-w-700-px">
      <div class="modal-content radius-16 bg-base">
        <div class="modal-header">
          <h5 class="modal-title" id="paymentHistoryLabel">Payment History</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-24">
          <div id="paymentHistoryContent">
            <p class="text-secondary-light text-center">Loading...</p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-3.7.1.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/bootstrap.bundle.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/apexcharts.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/iconify-icon.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/dataTables.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/jquery-ui.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/lib/flatpickr.min.js"></script>
  <script src="https://academixsuite.com/tenant/assets/js/app.js"></script>

  <script>
    $(function() {
      // DataTable init
      if ($('.data-table').length) {
        $('.data-table').each(function() {
          const $table = $(this);
          if ($table.find('tbody td[colspan]').length) {
            return;
          }
          const tableInstance = new DataTable(this);
          $table.closest('.dataTable-wrapper').find('.dt-search .dt-input').on('keyup', function() {
            tableInstance.search(this.value).draw();
          });
          $table.closest('.dataTable-wrapper').find('.dt-length .dt-input').on('change', function() {
            tableInstance.page.len($(this).val()).draw();
          });
        });
      }

      // Sidebar (collect fee) toggle
      $('.my-sidebar-btn').on('click', function() {
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
      });
      $('.close-my-sidebar, .overlay').on('click', function() {
        $('.my-sidebar').removeClass('active');
        $('.overlay').removeClass('active');
      });

      // Collect Fee button
      $(document).on('click', '.collect-fee-btn', function() {
        const id = $(this).data('student-id');
        const name = $(this).data('student-name');
        $('#cf_student_id').val(id);
        $('#cf_student_name').val(name);
        $('#cf_amount').val('');
        $('#cf_discount_id').val('');
        $('#cf_discount_amount').val('');
        $('#cf_fee_type_id').val('');
        $('#cf_reference').val('');
        $('#cf_notes').val('');
        $('.my-sidebar').addClass('active');
        $('.overlay').addClass('active');
      });

      // Discount auto-calculate
      $('#cf_discount_id').on('change', function() {
        const selected = $(this).find(':selected');
        const type = selected.data('type');
        const amount = parseFloat(selected.data('amount')) || 0;
        const feeAmount = parseFloat($('#cf_amount').val()) || 0;
        if (amount > 0) {
          if (type === 'percentage') {
            $('#cf_discount_amount').val((feeAmount * amount / 100).toFixed(2));
          } else {
            $('#cf_discount_amount').val(amount.toFixed(2));
          }
        } else {
          $('#cf_discount_amount').val('');
        }
      });

      // Recalculate discount when amount changes and discount is selected
      $('#cf_amount').on('input', function() {
        $('#cf_discount_id').trigger('change');
      });

      // View Payments - load payment history via AJAX
      $(document).on('click', '.view-payments-btn', function() {
        const studentId = $(this).data('student-id');
        const studentName = $(this).data('student-name');
        $('#paymentHistoryLabel').text('Payment History - ' + studentName);
        $('#paymentHistoryContent').html('<p class="text-secondary-light text-center">Loading...</p>');

        $.ajax({
          url: 'ajax/fee-payments.php',
          method: 'GET',
          data: { student_id: studentId, campus_id: <?php echo (int)$selectedCampusId; ?> },
          dataType: 'json',
          success: function(res) {
            if (res.success && res.payments.length > 0) {
              let html = '<div class="table-responsive"><table class="table bordered-table mb-0">';
              html += '<thead><tr><th>#</th><th>Fee Type</th><th>Amount</th><th>Discount</th><th>Method</th><th>Reference</th><th>Date</th><th>Notes</th></tr></thead><tbody>';
              $.each(res.payments, function(i, p) {
                html += '<tr>';
                html += '<td>' + (i + 1) + '</td>';
                html += '<td>' + (p.fee_type_name || 'N/A') + '</td>';
                html += '<td>' + parseFloat(p.amount).toFixed(2) + '</td>';
                html += '<td>' + (parseFloat(p.discount_amount) > 0 ? parseFloat(p.discount_amount).toFixed(2) : '-') + '</td>';
                html += '<td>' + (p.payment_method || 'N/A') + '</td>';
                html += '<td>' + (p.reference || 'N/A') + '</td>';
                html += '<td>' + (p.paid_at ? p.paid_at : (p.created_at || 'N/A')) + '</td>';
                html += '<td>' + (p.notes || '') + '</td>';
                html += '</tr>';
              });
              html += '</tbody></table></div>';
              $('#paymentHistoryContent').html(html);
            } else {
              $('#paymentHistoryContent').html('<p class="text-secondary-light text-center">No payment records found.</p>');
            }
          },
          error: function() {
            $('#paymentHistoryContent').html('<p class="text-danger text-center">Failed to load payment history.</p>');
          }
        });
      });
    });

    // Export stubs
    function exportPDF() { alert('PDF export coming soon.'); }
    function exportExcel() { alert('Excel export coming soon.'); }
  </script>
</body>
</html>
